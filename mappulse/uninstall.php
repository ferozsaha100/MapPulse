<?php
/**
 * Runs when MapPulse is deleted from the Plugins screen. Maps are kept.
 *
 * @package MapPulse
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array_keys( wp_roles()->roles ) as $mappulse_role_name ) {
	$mappulse_role = get_role( $mappulse_role_name );
	if ( $mappulse_role ) {
		$mappulse_role->remove_cap( 'manage_mappulse_maps' );
	}
}
