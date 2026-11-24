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

delete_option( 'mappulse_layers' );

// Saved boundary answers. The path mirrors MapPulse\Boundaries\Cache::dir(); plugin classes are not loaded here.
$mappulse_uploads = wp_upload_dir( null, false );
$mappulse_cache   = untrailingslashit( (string) apply_filters( 'mappulse_cache_dir', trailingslashit( $mappulse_uploads['basedir'] ) . 'mappulse/cache' ) );
foreach ( (array) glob( $mappulse_cache . '/*.json' ) as $mappulse_file ) {
	if ( is_string( $mappulse_file ) && is_file( $mappulse_file ) ) {
		wp_delete_file( $mappulse_file );
	}
}
