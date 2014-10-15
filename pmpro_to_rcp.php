<?php
/**
 * Plugin Name: PMPro to RCP
 * Plugin URI:  http://wordpress.org/extend/plugins
 * Description: Convert user data from Paid Membership Pro to Restrict Content Pro
 * Version:     0.1.0
 * Author:      Tanner Moushey
 * Author URI:  http://tannermoushey.com
 * License:     GPLv2+
 * Text Domain: ptr
 * Domain Path: /languages
 */

/**
 * Copyright (c) 2014 Tanner Moushey (email : tanner@iwitnessdesign.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Built using grunt-wp-plugin
 * Copyright (c) 2013 10up, LLC
 * https://github.com/10up/grunt-wp-plugin
 */

// Useful global constants
define( 'PTR_VERSION', '0.1.0' );
define( 'PTR_URL',     plugin_dir_url( __FILE__ ) );
define( 'PTR_PATH',    dirname( __FILE__ ) . '/' );

include_once( PTR_PATH . 'includes/migrate.php' );

/**
 * Default initialization for the plugin:
 * - Registers the default textdomain.
 */
function ptr_init() {
	$locale = apply_filters( 'plugin_locale', get_locale(), 'ptr' );
	load_textdomain( 'ptr', WP_LANG_DIR . '/ptr/ptr-' . $locale . '.mo' );
	load_plugin_textdomain( 'ptr', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}

/**
 * Prevent RCP from expiring users. In the event that we are dealing with old data
 * we want to make sure the expiration date has a chance to update before expiring everyone.
 */
function ptr_unhook() {
	remove_action( 'rcp_expired_users_check', 'rcp_check_for_expired_users' );
	remove_action( 'rcp_send_expiring_soon_notice', 'rcp_check_for_soon_to_expire_users' );
}
add_action( 'plugins_loaded', 'ptr_unhook' );