<?php
/**
 * Helper for tests/smoke.sh, run with "wp eval-file". Keeps quoting out of the shell.
 *
 * Modes (first argument):
 *   pages <map id>  Create a shortcode page, a block page and, with Elementor active, an Elementor page.
 *   atomic          Turn on every Elementor experiment whose key mentions "atomic". Prints the keys.
 *   versions        Print the WordPress, PHP and Elementor versions.
 *   errors          Print the number of PHP errors in debug.log that mention MapPulse, plus fatals.
 *   cap             Print whether administrators hold manage_mappulse_maps.
 */

$mode = $args[0] ?? '';

if ( 'pages' === $mode ) {
	$map_id = (int) ( $args[1] ?? 0 );
	$make   = static function ( string $title, string $content ) {
		return (int) wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_content' => $content,
				)
			)
		);
	};

	$shortcode = $make( 'MapPulse shortcode', '[mappulse id="' . $map_id . '"]' );
	$block     = $make( 'MapPulse block', '<!-- wp:agentview/mappulse {"mapId":' . $map_id . ',"layout":"list-right"} /-->' );
	$elementor = 0;

	if ( class_exists( '\Elementor\Plugin' ) ) {
		$elementor = $make( 'MapPulse Elementor', '' );
		$data      = array(
			array(
				'id'       => 'a1b2c3d',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'b2c3d4e',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'c3d4e5f',
								'elType'     => 'widget',
								'widgetType' => 'mappulse',
								'settings'   => array(
									'map_id'           => (string) $map_id,
									'layout'           => 'list-below',
									'zone_hover_color' => '#7A1F1F',
								),
								'elements'   => array(),
							),
						),
					),
				),
			),
		);
		update_post_meta( $elementor, '_elementor_edit_mode', 'builder' );
		update_post_meta( $elementor, '_elementor_template_type', 'wp-page' );
		update_post_meta( $elementor, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
		update_post_meta( $elementor, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	}

	echo "shortcode=$shortcode block=$block elementor=$elementor\n";
	return;
}

if ( 'atomic' === $mode ) {
	$keys = array();
	if ( class_exists( '\Elementor\Plugin' ) ) {
		foreach ( array_keys( \Elementor\Plugin::$instance->experiments->get_features() ) as $key ) {
			if ( false !== stripos( $key, 'atomic' ) ) {
				update_option( 'elementor_experiment-' . $key, 'active' );
				$keys[] = $key;
			}
		}
	}
	echo 'atomic=' . ( $keys ? implode( ',', $keys ) : 'none' ) . "\n";
	return;
}

if ( 'versions' === $mode ) {
	echo 'versions wordpress=' . get_bloginfo( 'version' ) . ' php=' . PHP_VERSION . ' elementor=' . ( defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'none' ) . "\n";
	return;
}

if ( 'errors' === $mode ) {
	$log   = WP_CONTENT_DIR . '/debug.log';
	$count = 0;
	if ( is_readable( $log ) ) {
		foreach ( file( $log ) as $line ) {
			if ( preg_match( '/PHP (Fatal|Parse) error/', $line ) || ( false !== stripos( $line, 'mappulse' ) && preg_match( '/PHP (Warning|Notice|Deprecated)/', $line ) ) ) {
				++$count;
				fwrite( STDERR, $line );
			}
		}
	}
	echo "errors=$count\n";
	return;
}

if ( 'cap' === $mode ) {
	$role = get_role( 'administrator' );
	echo 'cap=' . ( $role && $role->has_cap( 'manage_mappulse_maps' ) ? 'yes' : 'no' ) . "\n";
	return;
}

echo "unknown mode\n";
