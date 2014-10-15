<?php
$rcp_levels = new RCP_Levels();
$map = get_option( 'pmpro_to_rcp', array() );
?>
<div class="wrap">
	<h2>Migrate Subscriptions</h2>

	<form action="" method="post">
		<table class="widefat">
			<thead>
			<tr>
				<th>
					PMPro Level
				</th>
				<th>
					RCP Level
				</th>
			</tr>
			</thead>
			<?php foreach ( pmpro_getAllLevels( true ) as $level ) : ?>
				<tr>
					<td><?php echo $level->name; ?></td>
					<td>
						<select id="rcp_level" name="pmpro_<?php echo $level->id; ?>">
							<option value="none">--- None ---</option>
							<?php foreach ( $rcp_levels->get_levels() as $rcp_level ) : ?>
								<option value="rcp_<?php echo $rcp_level->id; ?>" <?php selected( $rcp_level->id, ptr_get_rcp_map( $level->id ) ); ?>><?php echo $rcp_level->name; ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<?php wp_nonce_field( 'ptr-save', 'ptr_save_nonce' ); ?>
		<?php submit_button( 'Save', 'primary large' ); ?>

	</form>

	<form action="" method="post">
		<?php wp_nonce_field( 'ptr-migrate-users', 'ptr_migrate_users' ); ?>
		<input type="submit" value="Migrate PMPro Users" />
	</form>
	<h2>Users</h2>

	<table class="widefat">
		<thead>
		<tr>
			<th>ID</th>
			<th>User</th>
			<th>PMPro Level</th>
			<th>RCP Subscription</th>
		</tr>
		</thead>
		<?php foreach ( get_users() as $user ) :
			if ( ! pmpro_hasMembershipLevel( null, $user->id ) ) {
				continue;
			}

			$levels      = pmpro_getMembershipLevelsForUser( $user->id );
			$level_names = wp_list_pluck( $levels, 'name' );

			if ( isset( $_POST['ptr_migrate_users'] ) && wp_verify_nonce( $_POST['ptr_migrate_users'], 'ptr-migrate-users' ) && isset( $levels[0] ) ) {
				ptr_migrate_user( $user->id, $levels[0]->id );
			}
			?>
			<tr>
				<td><?php echo $user->ID; ?></td>
				<td><?php echo $user->display_name; ?></td>
				<td><?php echo implode( ', ', $level_names ); ?></td>
				<td><?php echo rcp_get_subscription( $user->id ); ?></td>
			</tr>
		<?php endforeach; ?>
	</table>

</div>