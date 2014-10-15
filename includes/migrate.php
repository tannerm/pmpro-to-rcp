<?php

PTR_Migrate::get_instance();

class PTR_Migrate {

	/**
	 * @var
	 */
	protected static $_instance;

	/**
	 * Only make one instance of the PTR_Migrate
	 *
	 * @return PTR_Migrate
	 */
	public static function get_instance() {
		if ( ! self::$_instance instanceof PTR_Migrate ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Add Hooks and Actions
	 */
	protected function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ), 100 );
		add_action( 'init', array( $this, 'save_relation' ) );
	}

	public function admin_menu() {
		add_submenu_page( 'rcp-members', 'PMPro to RCP', 'Convert PMPro Users', 'edit_users', 'pmpro-to-rcp', array( $this, 'content' ) );
	}

	public function content() {
		if ( is_plugin_active( 'paid-memberships-pro/paid-memberships-pro.php' ) && is_plugin_active( 'restrict-content-pro/restrict-content-pro.php' ) ) {
			include_once( PTR_PATH . 'views/settings.php' );
		} else {
			include_once( PTR_PATH . 'views/activate-plugins.php' );
		}
	}

	public function save_relation() {
		if ( empty( $_POST['ptr_save_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['ptr_save_nonce'], 'ptr-save' ) ) {
			return;
		}

		$map = array();

		foreach ( $_POST as $pmpro => $rcp ) {
			if ( false === strpos( $rcp, 'rcp_' ) ) {
				continue;
			}

			$key   = (int) str_replace( 'pmpro_', '', $pmpro );
			$value = (int) str_replace( 'rcp_', '', $rcp );

			$map[$key] = $value;
		}

		update_option( 'pmpro_to_rcp', $map );

	}

}

/**
 * Return RCP equivalent of PMPro level
 *
 * @param $pmpro_id
 *
 * @return bool
 */
function ptr_get_rcp_map( $pmpro_id ) {
	if ( ! $map = get_option( 'pmpro_to_rcp' ) ) {
		return false;
	}

	if ( empty( $map[$pmpro_id] ) ) {
		return false;
	}

	return $map[$pmpro_id];
}

/**
 * Collect data from PMPro and store it for RCP
 *
 * @param $user_id
 * @param $pmpro_level_id
 */
function ptr_migrate_user( $user_id, $pmpro_level_id ) {
	if ( ! $rcp_id = ptr_get_rcp_map( $pmpro_level_id ) ) {
		return;
	}

	// All active PMPro users should get an active RCP Level, we'll handle payments later
	update_user_meta( $user_id, 'rcp_status',             'active' );
	update_user_meta( $user_id, 'rcp_subscription_level', $rcp_id  );

	if ( ! get_user_meta( $user_id, 'rcp_subscription_key', true ) ) {
		update_user_meta( $user_id, 'rcp_subscription_key', rcp_generate_subscription_key() );
	}

	global $wpdb;

	// okay, add an invoice. first lookup the user_id from the subscription id passed
	$old_order_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $wpdb->pmpro_membership_orders WHERE user_id = '%s' AND membership_id = '%s' AND gateway = 'stripe' ORDER BY timestamp DESC LIMIT 1", $user_id, $pmpro_level_id ) );

	// If this account was manually set or is a test account, don't continue with payment migration
	if ( ! $old_order_id ) {
		return;
	}

	$old_order = new MemberOrder( $old_order_id );

	if ( empty( $old_order->subscription_transaction_id ) ) {
		return;
	}

	update_user_meta( $user_id, '_rcp_stripe_user_id',     $old_order->subscription_transaction_id );
	update_user_meta( $user_id, '_rcp_stripe_is_customer', 'yes'                                   );
	update_user_meta( $user_id, 'rcp_recurring',           'yes'                                   );

	ptr_migrate_payments( $user_id, $old_order->subscription_transaction_id );

}

/**
 * Save past payments so that RCP has a record going forward
 *
 * Duplicated much of the functionality in rcp_stripe_event_listener() in stripe-listener.php
 *
 * @param $user_id
 * @param $subscription_id
 */
function ptr_migrate_payments( $user_id, $subscription_id ) {
	global $wpdb;

	$member_new_expiration = false;

	// get old orders for this subscription... i.e. get all subscription payments
	$old_orders = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM $wpdb->pmpro_membership_orders WHERE user_id = '%s' AND subscription_transaction_id = '%s' AND gateway = 'stripe' ORDER BY timestamp ASC", $user_id, $subscription_id ) );

	// separate out the id
	$old_orders = wp_list_pluck( $old_orders, 'id' );

	foreach ( (array) $old_orders as $order_id ) {
		if ( ! $order = new MemberOrder( $order_id ) ) {
			return;
		}

		$rcp_payments = new RCP_Payments();

		// retrieve subscription details
		if ( ! $subscription_id = rcp_get_subscription_id( $user_id ) ) {
			return;
		}

		$subscription_details = rcp_get_subscription_details( $subscription_id );

		// setup payment data
		$payment_data = array(
			'date'             => date( 'Y-m-d g:i:s', $order->timestamp ),
			'subscription'     => $subscription_details->name,
			'payment_type'     => 'Credit Card',
			'subscription_key' => rcp_get_subscription_key( $user_id ),
			'amount'           => $order->total,
			'user_id'          => $user_id,
			'transaction_id'   => $order->payment_transaction_id,
		);

		// make sure this payment doesn't already exist say if this migration is run more than once
		if( ! rcp_check_for_existing_payment( $payment_data['payment_type'], $payment_data['date'], $payment_data['subscription_key'] ) ) {
				// record this payment if it hasn't been recorded yet
			$rcp_payments->insert( $payment_data );
		}

		// update the user's expiration to correspond with this payment
		$member_new_expiration = date( 'Y-m-d H:i:s', strtotime( '+' . $subscription_details->duration . ' ' . $subscription_details->duration_unit . ' 23:59:59', $order->timestamp ) );

	}


	// This is for a development environment whose data may not be up to date, this
	// simulates a payment made today and extending the subscription one term
	if ( strtotime( $member_new_expiration ) < time() ) {
		$member_new_expiration = date( 'Y-m-d H:i:s', strtotime( '+' . $subscription_details->duration . ' ' . $subscription_details->duration_unit . ' 23:59:59' ) );
	}

	update_user_meta( $user_id, 'rcp_expiration', $member_new_expiration );

}