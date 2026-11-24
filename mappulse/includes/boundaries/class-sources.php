<?php
/**
 * The list of boundary sources: the built-in Census source plus the layers a site has registered.
 *
 * @package MapPulse
 */

namespace MapPulse\Boundaries;

defined( 'ABSPATH' ) || exit;

/**
 * Source registry.
 */
class Sources {

	const OPTION = 'mappulse_layers';

	/**
	 * A layer address: https, an ArcGIS MapServer or FeatureServer, ending in the layer number.
	 */
	const URL_PATTERN = '/^https:\/\/[^\/\s]+\/\S+\/(MapServer|FeatureServer)\/\d+$/i';

	/**
	 * Lets MapPulse contact the hosts of registered layers.
	 */
	public static function register(): void {
		add_filter( 'mappulse_allowed_hosts', array( self::class, 'layer_hosts' ) );
	}

	/**
	 * Adds the hosts of registered layers to the allowed list.
	 *
	 * @param array $hosts Allowed hosts so far.
	 */
	public static function layer_hosts( $hosts ): array {
		$hosts = (array) $hosts;
		foreach ( self::layers() as $layer ) {
			$hosts[] = (string) wp_parse_url( $layer['url'], PHP_URL_HOST );
		}
		return $hosts;
	}

	/**
	 * Registered layers as stored entries.
	 */
	public static function layers(): array {
		$stored = get_option( self::OPTION, array() );
		$layers = array();
		foreach ( is_array( $stored ) ? $stored : array() as $layer ) {
			if ( is_array( $layer ) && isset( $layer['id'], $layer['url'], $layer['label'], $layer['parent'], $layer['name_field'], $layer['id_field'] ) && preg_match( self::URL_PATTERN, (string) $layer['url'] ) ) {
				$layers[] = $layer;
			}
		}
		return $layers;
	}

	/**
	 * Every source, Census first.
	 *
	 * @return Provider[]
	 */
	public static function all(): array {
		$providers = array( new Census_Provider() );
		foreach ( self::layers() as $layer ) {
			$providers[] = new Layer_Provider( $layer );
		}
		return $providers;
	}

	/**
	 * One source by ID.
	 *
	 * @param string $id Source ID.
	 */
	public static function get( string $id ): ?Provider {
		foreach ( self::all() as $provider ) {
			if ( $provider->id() === $id ) {
				return $provider;
			}
		}
		return null;
	}

	/**
	 * Registers a polygon layer after checking its address and reading what it holds.
	 *
	 * @param string $url    Layer address.
	 * @param string $label  Name for the source.
	 * @param string $place  Place the layer covers, shown beside results (for example "St. Petersburg, FL").
	 * @return array|\WP_Error The stored entry.
	 */
	public static function add_layer( string $url, string $label, string $place = '' ) {
		$url  = trim( $url );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! preg_match( self::URL_PATTERN, $url ) || 'localhost' === $host || ! str_contains( $host, '.' ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return new \WP_Error( 'mappulse_bad_layer_url', __( 'Enter the https address of one ArcGIS layer, ending in MapServer or FeatureServer and the layer number.', 'mappulse' ) );
		}

		$label  = sanitize_text_field( $label );
		$label  = '' !== $label ? $label : $host;
		$id     = 'layer:' . sanitize_title( $label );
		$layers = self::layers();
		foreach ( $layers as $layer ) {
			if ( $layer['id'] === $id || 0 === strcasecmp( $layer['url'], $url ) ) {
				return new \WP_Error( 'mappulse_duplicate_layer', __( 'That layer, or one with the same name, is already registered.', 'mappulse' ) );
			}
		}

		// The host is allowed for this one read only. It joins the allowed list when the layer is stored.
		$allow = static function ( $hosts ) use ( $host ) {
			$hosts[] = $host;
			return $hosts;
		};
		add_filter( 'mappulse_allowed_hosts', $allow );
		$info = Arcgis::layer_info( $url );
		remove_filter( 'mappulse_allowed_hosts', $allow );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( 'esriGeometryPolygon' !== $info['geometryType'] ) {
			return new \WP_Error( 'mappulse_not_polygons', __( 'That layer does not hold area shapes. Choose a polygon layer.', 'mappulse' ) );
		}

		$name_field = '';
		foreach ( $info['fields'] as $field => $type ) {
			if ( 'esriFieldTypeString' !== $type ) {
				continue;
			}
			if ( preg_match( '/name/i', $field ) ) {
				$name_field = $field;
				break;
			}
			if ( '' === $name_field ) {
				$name_field = $field;
			}
		}
		if ( '' === $name_field ) {
			return new \WP_Error( 'mappulse_no_name_field', __( 'That layer has no text field to search by name.', 'mappulse' ) );
		}

		$entry    = array(
			'id'         => $id,
			'url'        => $url,
			'label'      => $label,
			'parent'     => sanitize_text_field( $place ),
			'name_field' => $name_field,
			'id_field'   => '' !== $info['id_field'] ? $info['id_field'] : 'OBJECTID',
		);
		$layers[] = $entry;
		update_option( self::OPTION, $layers, false );

		return $entry;
	}

	/**
	 * Removes a registered layer.
	 *
	 * @param string $id Source ID.
	 */
	public static function remove_layer( string $id ): bool {
		$layers = self::layers();
		$kept   = array();
		foreach ( $layers as $layer ) {
			if ( $layer['id'] !== $id ) {
				$kept[] = $layer;
			}
		}
		if ( count( $kept ) === count( $layers ) ) {
			return false;
		}
		update_option( self::OPTION, $kept, false );
		return true;
	}
}
