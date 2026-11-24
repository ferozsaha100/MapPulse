<?php
/**
 * Helpers for ArcGIS REST services, the format used by the Census (TIGERweb) and most city GIS sites.
 *
 * @package MapPulse
 */

namespace MapPulse\Boundaries;

defined( 'ABSPATH' ) || exit;

/**
 * ArcGIS REST queries.
 */
class Arcgis {

	const MAX_TEXT = 60;

	/**
	 * Makes user text safe to place inside quotes in a "where" clause.
	 *
	 * Upper-cases it, keeps only letters, digits, spaces, hyphens, periods and apostrophes,
	 * cuts it to 60 characters and doubles every apostrophe.
	 *
	 * @param string $text Raw text.
	 */
	public static function clean( string $text ): string {
		$text = mb_strtoupper( wp_check_invalid_utf8( $text ) );
		$text = (string) preg_replace( "/[^\\p{L}\\p{N} .'\\-]/u", '', $text );
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		$text = mb_substr( $text, 0, self::MAX_TEXT );
		return str_replace( "'", "''", $text );
	}

	/**
	 * Turns user text into the middle of a LIKE pattern: cleaned, with every run of spaces and
	 * punctuation replaced by a wildcard, so "St Petersburg" matches "St. Petersburg".
	 *
	 * The result holds only letters, digits and % signs, so it needs no quoting.
	 *
	 * @param string $text Raw text.
	 */
	public static function like( string $text ): string {
		$clean = str_replace( "''", "'", self::clean( $text ) );
		return trim( (string) preg_replace( "/[ .'\\-]+/", '%', $clean ), '%' );
	}

	/**
	 * Lists the layers of a map service. Group layers are left out.
	 *
	 * @param string $service_url URL ending in MapServer or FeatureServer.
	 * @return array|\WP_Error List of arrays with id and name.
	 */
	public static function layers( string $service_url ) {
		$key    = 'layers|' . $service_url;
		$cached = Cache::get( $key );
		if ( null !== $cached ) {
			return $cached;
		}

		$data = Http::get_json( $service_url, array( 'f' => 'json' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$layers = array();
		foreach ( isset( $data['layers'] ) && is_array( $data['layers'] ) ? $data['layers'] : array() as $layer ) {
			if ( ! isset( $layer['id'], $layer['name'] ) || ! empty( $layer['subLayerIds'] ) ) {
				continue;
			}
			$layers[] = array(
				'id'   => (int) $layer['id'],
				'name' => (string) $layer['name'],
			);
		}

		Cache::set( $key, $layers, DAY_IN_SECONDS );
		return $layers;
	}

	/**
	 * Finds a layer's number by its name, because the numbers change between data vintages.
	 *
	 * @param string   $service_url  Service URL.
	 * @param string   $name_pattern Regular expression matched against layer names.
	 * @param int|null $fallback_id  Number to use when no name matches.
	 * @return int|\WP_Error
	 */
	public static function find_layer( string $service_url, string $name_pattern, ?int $fallback_id ) {
		$layers = self::layers( $service_url );
		if ( is_wp_error( $layers ) && 'mappulse_http' === $layers->get_error_code() ) {
			// The service cannot be reached. Guessing a layer number would only wait for it again.
			return $layers;
		}
		$found = null;
		foreach ( is_array( $layers ) ? $layers : array() as $layer ) {
			if ( preg_match( $name_pattern, $layer['name'] ) ) {
				$found = $layer['id'];
			}
		}
		if ( null !== $found ) {
			return $found;
		}
		if ( null !== $fallback_id ) {
			return $fallback_id;
		}
		return is_wp_error( $layers ) ? $layers : new \WP_Error( 'mappulse_layer_not_found', __( 'The boundary source no longer offers the layer MapPulse needs.', 'mappulse' ) );
	}

	/**
	 * Reads what a layer holds.
	 *
	 * @param string $layer_url URL ending in the layer number.
	 * @return array|\WP_Error name, geometryType, fields (name => type), id_field.
	 */
	public static function layer_info( string $layer_url ) {
		$data = Http::get_json( $layer_url, array( 'f' => 'json' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$fields   = array();
		$id_field = '';
		foreach ( isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : array() as $field ) {
			if ( ! isset( $field['name'], $field['type'] ) || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_.]*$/', (string) $field['name'] ) ) {
				continue;
			}
			$fields[ (string) $field['name'] ] = (string) $field['type'];
			if ( 'esriFieldTypeOID' === $field['type'] && '' === $id_field ) {
				$id_field = (string) $field['name'];
			}
		}

		return array(
			'name'         => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			'geometryType' => isset( $data['geometryType'] ) ? (string) $data['geometryType'] : '',
			'fields'       => $fields,
			'id_field'     => $id_field,
		);
	}

	/**
	 * Queries a layer and returns one GeoJSON FeatureCollection in WGS 84, following the service's paging.
	 *
	 * @param string $layer_url    URL ending in the layer number.
	 * @param array  $params       ArcGIS query parameters (where, outFields, geometry and so on).
	 * @param int    $max_features Stop with an error beyond this many features.
	 * @param bool   $first_page   Return only the first $max_features and set "truncated" when there are more.
	 * @return array|\WP_Error
	 */
	public static function query( string $layer_url, array $params, int $max_features = 2000, bool $first_page = false ) {
		$params = array_merge(
			array(
				'where'     => '1=1',
				'outFields' => '*',
			),
			$params,
			array(
				'f'                 => 'geojson',
				'outSR'             => 4326,
				'geometryPrecision' => 6,
			)
		);

		$features = array();
		$bytes    = 0;
		for ( $page = 0; $page < 50; $page++ ) {
			if ( $features ) {
				$params['resultOffset'] = count( $features );
			}
			$data = Http::get_json( $layer_url . '/query', $params );
			if ( is_wp_error( $data ) ) {
				return $data;
			}

			if ( ! isset( $data['features'] ) || ! is_array( $data['features'] ) ) {
				return new \WP_Error( 'mappulse_bad_response', __( 'The boundary source sent an answer MapPulse could not read.', 'mappulse' ) );
			}

			// The size cap applies to a whole query, not to each page of it.
			$bytes += Http::last_bytes();
			if ( $bytes > Http::max_bytes() ) {
				return new \WP_Error( 'mappulse_too_large', __( 'The boundary source sent more data than MapPulse accepts. Try a smaller area.', 'mappulse' ) );
			}

			$batch    = $data['features'];
			$features = array_merge( $features, $batch );
			$more     = ! empty( $data['exceededTransferLimit'] ) || ! empty( $data['properties']['exceededTransferLimit'] );

			if ( $first_page ) {
				return array(
					'type'      => 'FeatureCollection',
					'features'  => array_slice( $features, 0, $max_features ),
					'truncated' => $more || count( $features ) > $max_features,
				);
			}

			if ( count( $features ) > $max_features || ( $more && count( $features ) >= $max_features ) ) {
				return new \WP_Error( 'mappulse_too_many_features', __( 'The boundary source returned more shapes than MapPulse accepts. Try a smaller area or a more specific name.', 'mappulse' ) );
			}
			if ( ! $more || ! $batch ) {
				break;
			}
		}

		return array(
			'type'     => 'FeatureCollection',
			'features' => $features,
		);
	}
}
