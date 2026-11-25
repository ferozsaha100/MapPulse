<?php
/**
 * The map builder screen.
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page that hosts the builder app, and the links that lead to it.
 */
class Builder_Screen {

	const PAGE = 'mappulse-builder';

	/**
	 * Adds the admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'views_edit-' . Map_Post_Type::POST_TYPE, array( self::class, 'list_button' ), 5 );
	}

	/**
	 * Admin URL of the builder.
	 *
	 * @param int $map_id Map to open, or 0 for a new map.
	 */
	public static function url( int $map_id = 0 ): string {
		$url = admin_url( 'edit.php?post_type=' . Map_Post_Type::POST_TYPE . '&page=' . self::PAGE );
		return $map_id > 0 ? $url . '&map=' . $map_id : $url;
	}

	/**
	 * Folder that holds the compiled builder.
	 */
	public static function build_dir(): string {
		/**
		 * Filters the folder the compiled builder is read from.
		 *
		 * @param string $dir Absolute path without a trailing slash.
		 */
		return untrailingslashit( (string) apply_filters( 'mappulse_build_dir', MAPPULSE_DIR . 'build' ) );
	}

	/**
	 * Adds "Add New Map" under the MapPulse menu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Map_Post_Type::POST_TYPE,
			__( 'Map builder', 'mappulse' ),
			__( 'Add New Map', 'mappulse' ),
			Map_Post_Type::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' ),
			1
		);
	}

	/**
	 * Prints a "Build a map" button at the top of the map list.
	 *
	 * @param array $views List table views, returned unchanged.
	 */
	public static function list_button( array $views ): array {
		printf(
			'<p><a class="page-title-action" href="%s">%s</a></p>',
			esc_url( self::url() ),
			esc_html__( 'Build a map', 'mappulse' )
		);
		return $views;
	}

	/**
	 * Sends a map's Edit links to the builder.
	 *
	 * @param string $link    The edit link.
	 * @param int    $post_id Post ID.
	 */
	public static function edit_link( $link, $post_id ) {
		return Map_Post_Type::POST_TYPE === get_post_type( $post_id ) ? self::url( (int) $post_id ) : $link;
	}

	/**
	 * What the builder app is told when it starts.
	 */
	public static function settings(): array {
		$states = array();
		foreach ( Boundaries\Census_Provider::states() as $code => $state ) {
			$states[] = array(
				'code' => $code,
				'name' => (string) ( $state[1] ?? $code ),
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only: which map to open.
		$map_id = isset( $_GET['map'] ) ? absint( wp_unslash( $_GET['map'] ) ) : 0;

		return array(
			'mapId'         => $map_id,
			'listUrl'       => admin_url( 'edit.php?post_type=' . Map_Post_Type::POST_TYPE ),
			'editUrl'       => self::url(),
			'tiles'         => array(
				/**
				 * Filters the street tiles the builder shows a boundary on. Used in the admin only.
				 *
				 * @param string $url Tile URL with {z}, {x} and {y}.
				 */
				'url'         => (string) apply_filters( 'mappulse_tile_url', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png' ),
				/**
				 * Filters the credit shown on the street tiles.
				 *
				 * @param string $attribution Plain text.
				 */
				'attribution' => (string) apply_filters( 'mappulse_tile_attribution', '© OpenStreetMap contributors' ),
			),
			'states'        => $states,
			'types'         => array(
				array(
					'value' => 'city',
					'label' => __( 'City', 'mappulse' ),
				),
				array(
					'value' => 'zip',
					'label' => __( 'ZIP area', 'mappulse' ),
				),
				array(
					'value' => 'county',
					'label' => __( 'County', 'mappulse' ),
				),
				array(
					'value' => 'neighborhood',
					'label' => __( 'Neighborhood', 'mappulse' ),
				),
			),
			'maxZones'      => MAPPULSE_MAX_ZONES,
			'siteColors'    => self::site_colors(),
			'siteFonts'     => self::site_fonts(),
			'styleDefaults' => Map_Document::style_defaults(),
		);
	}

	/**
	 * The site's own colors, offered first in the builder's color pickers: the theme's palette and,
	 * with Elementor, its global colors. Hex values only.
	 *
	 * @return array[] Each: name, color (#RRGGBB).
	 */
	public static function site_colors(): array {
		$found   = array();
		$palette = wp_get_global_settings( array( 'color', 'palette' ) );
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( isset( $palette[ $origin ] ) && is_array( $palette[ $origin ] ) ? $palette[ $origin ] : array() as $entry ) {
				$found[] = array( $entry['name'] ?? ( $entry['slug'] ?? '' ), $entry['color'] ?? '' );
			}
		}
		foreach ( array( 'system_colors', 'custom_colors' ) as $setting ) {
			foreach ( self::elementor_kit( $setting ) as $entry ) {
				$found[] = array( $entry['title'] ?? '', $entry['color'] ?? '' );
			}
		}

		$colors = array();
		foreach ( $found as list( $name, $color ) ) {
			$hex = is_string( $color ) ? sanitize_hex_color( $color ) : '';
			if ( ! $hex ) {
				continue;
			}
			if ( 4 === strlen( $hex ) ) {
				$hex = '#' . $hex[1] . $hex[1] . $hex[2] . $hex[2] . $hex[3] . $hex[3];
			}
			$hex = strtoupper( $hex );
			if ( ! isset( $colors[ $hex ] ) && count( $colors ) < 24 ) {
				$colors[ $hex ] = array(
					'name'  => sanitize_text_field( (string) $name ),
					'color' => $hex,
				);
			}
		}
		return array_values( $colors );
	}

	/**
	 * Fonts offered for labels: the site's own families, then four system stacks. A font is used by
	 * name only; MapPulse loads no font file.
	 *
	 * @return array[] Each: name, family.
	 */
	public static function site_fonts(): array {
		$found    = array();
		$families = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( isset( $families[ $origin ] ) && is_array( $families[ $origin ] ) ? $families[ $origin ] : array() as $entry ) {
				$found[] = array( $entry['name'] ?? ( $entry['slug'] ?? '' ), $entry['fontFamily'] ?? '' );
			}
		}
		foreach ( self::elementor_kit( 'system_typography' ) as $entry ) {
			$family = $entry['typography_font_family'] ?? '';
			if ( is_string( $family ) && '' !== $family ) {
				$found[] = array( ( $entry['title'] ?? '' ) . ': ' . $family, $family . ', sans-serif' );
			}
		}
		$found[] = array( __( 'Sans-serif (Inter, Helvetica, Arial)', 'mappulse' ), 'Inter, Helvetica, Arial, sans-serif' );
		$found[] = array( __( 'System sans-serif', 'mappulse' ), 'system-ui, -apple-system, Segoe UI, Roboto, sans-serif' );
		$found[] = array( __( 'Serif (Georgia)', 'mappulse' ), 'Georgia, Times New Roman, serif' );
		$found[] = array( __( 'Monospace', 'mappulse' ), 'ui-monospace, Menlo, Consolas, monospace' );

		$fonts = array();
		foreach ( $found as list( $name, $family ) ) {
			// A family given as a CSS variable cannot be stored as a name.
			if ( ! is_string( $family ) || false !== strpos( $family, '(' ) ) {
				continue;
			}
			// The same characters the map document accepts for a font.
			$family = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^A-Za-z0-9 ,_\-]/', '', $family ) ) );
			if ( '' !== $family && ! isset( $fonts[ $family ] ) && count( $fonts ) < 30 ) {
				$fonts[ $family ] = array(
					'name'   => sanitize_text_field( (string) $name ),
					'family' => $family,
				);
			}
		}
		return array_values( $fonts );
	}

	/**
	 * One list setting of the active Elementor kit, or an empty list without Elementor.
	 *
	 * @param string $setting Kit setting name.
	 */
	private static function elementor_kit( string $setting ): array {
		if ( ! class_exists( '\\Elementor\\Plugin' ) || empty( \Elementor\Plugin::$instance->kits_manager ) ) {
			return array();
		}
		try {
			$kit  = \Elementor\Plugin::$instance->kits_manager->get_active_kit_for_frontend();
			$list = $kit ? $kit->get_settings( $setting ) : array();
		} catch ( \Throwable $e ) {
			return array();
		}
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * Loads the builder on its own screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( ! is_string( $hook ) || ! str_ends_with( $hook, '_page_' . self::PAGE ) ) {
			return;
		}
		$dir   = self::build_dir();
		$asset = $dir . '/builder.asset.php';
		if ( ! is_readable( $asset ) ) {
			return;
		}
		$meta = require $asset;
		$base = MAPPULSE_DIR . 'build' === $dir ? MAPPULSE_URL . 'build/' : content_url( str_replace( wp_normalize_path( WP_CONTENT_DIR ), '', wp_normalize_path( $dir ) ) ) . '/';

		wp_enqueue_script( 'mappulse-builder', $base . 'builder.js', (array) ( $meta['dependencies'] ?? array() ), (string) ( $meta['version'] ?? MAPPULSE_VERSION ), true );
		wp_add_inline_script( 'mappulse-builder', 'window.mappulseBuilder = ' . wp_json_encode( self::settings() ) . ';', 'before' );
		wp_set_script_translations( 'mappulse-builder', 'mappulse' );
		if ( is_readable( $dir . '/builder.css' ) ) {
			wp_enqueue_style( 'mappulse-builder', $base . 'builder.css', array( 'wp-components' ), (string) ( $meta['version'] ?? MAPPULSE_VERSION ) );
		}
		// The preview draws the real front-end map, so it needs the front-end stylesheet.
		wp_enqueue_style( 'mappulse' );
		// The card's photo is chosen from the media library.
		wp_enqueue_media();
	}

	/**
	 * Prints the screen: the element the app mounts on, or why it cannot start.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Map_Post_Type::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to build maps.', 'mappulse' ) );
		}
		echo '<div class="wrap mappulse-builder-wrap">';
		if ( ! is_readable( self::build_dir() . '/builder.asset.php' ) ) {
			echo '<h1>' . esc_html__( 'Map builder', 'mappulse' ) . '</h1>';
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The map builder files are missing from this copy of MapPulse. Install the plugin from its release zip.', 'mappulse' ) . '</p></div>';
		} else {
			echo '<div id="mappulse-builder"><p>' . esc_html__( 'Loading the map builder…', 'mappulse' ) . '</p></div>';
			echo '<noscript><div class="notice notice-error inline"><p>' . esc_html__( 'The map builder needs JavaScript.', 'mappulse' ) . '</p></div></noscript>';
		}
		echo '</div>';
	}
}
