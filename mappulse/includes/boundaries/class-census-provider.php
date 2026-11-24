<?php
/**
 * US Census Bureau boundaries (TIGERweb): cities, ZIP areas and counties for the whole country.
 *
 * ZIP areas are Census "ZIP Code Tabulation Areas", which follow USPS ZIP codes closely but not exactly.
 *
 * @package MapPulse
 */

namespace MapPulse\Boundaries;

defined( 'ABSPATH' ) || exit;

/**
 * The built-in Census source.
 */
class Census_Provider implements Provider {

	const SERVICE = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/tigerWMS_Current/MapServer';

	/**
	 * Layers by kind: name pattern, fallback layer number, record prefix and ID field.
	 *
	 * "cdp" holds census designated places: communities that are not incorporated cities, such as
	 * Fisher Island or Palm Harbor. People think of them as cities, so they are searched and typed as "city".
	 */
	const LAYERS = array(
		'city'   => array( '/^incorporated places$/i', 28, 'place', 'GEOID' ),
		'cdp'    => array( '/^census designated places$/i', 30, 'cdp', 'GEOID' ),
		'zip'    => array( '/zip code tabulation areas$/i', 2, 'zcta', 'ZCTA5' ),
		'county' => array( '/^counties$/i', 82, 'county', 'GEOID' ),
	);

	/**
	 * States by postal code, loaded once.
	 *
	 * @var array|null
	 */
	private static $states = null;

	/**
	 * Source ID.
	 */
	public function id(): string {
		return 'census';
	}

	/**
	 * Source name.
	 */
	public function label(): string {
		return __( 'US Census Bureau', 'mappulse' );
	}

	/**
	 * Area types offered.
	 */
	public function types(): array {
		return array( 'city', 'zip', 'county' );
	}

	/**
	 * States by postal code: FIPS code and name.
	 */
	public static function states(): array {
		if ( null === self::$states ) {
			self::$states = require MAPPULSE_DIR . 'includes/boundaries/states.php';
		}
		return self::$states;
	}

	/**
	 * Finds cities, ZIP areas and counties by name or number.
	 *
	 * @param string $query Text typed by a person.
	 * @param array  $args  type, state, limit.
	 * @return array|\WP_Error
	 */
	public function search( string $query, array $args ) {
		$text = Arcgis::like( $query );
		if ( mb_strlen( str_replace( '%', '', $text ) ) < 2 ) {
			return new \WP_Error( 'mappulse_bad_query', __( 'Type at least two letters or digits.', 'mappulse' ) );
		}

		$type  = isset( $args['type'] ) ? (string) $args['type'] : '';
		$state = isset( $args['state'] ) ? strtoupper( trim( (string) $args['state'] ) ) : '';
		$limit = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 20;
		$fips  = '';
		if ( '' !== $state ) {
			if ( ! isset( self::states()[ $state ] ) ) {
				return new \WP_Error( 'mappulse_bad_state', __( 'That is not a US state code.', 'mappulse' ) );
			}
			$fips = self::states()[ $state ][0];
		}

		$digits = (bool) preg_match( '/^\d{3,5}$/', $text );
		if ( '' === $type ) {
			$types = $digits ? array( 'zip' ) : array( 'city', 'county' );
		} elseif ( in_array( $type, $this->types(), true ) && ( 'zip' !== $type || $digits ) ) {
			$types = array( $type );
		} else {
			return array();
		}

		if ( in_array( 'city', $types, true ) ) {
			$types[] = 'cdp';
		}

		$key    = 'census|search|' . implode( ',', $types ) . '|' . $state . '|' . $limit . '|' . $text;
		$cached = Cache::get( $key );
		if ( null !== $cached ) {
			return $cached;
		}

		$results = array();
		foreach ( $types as $area_type ) {
			$found = $this->search_layer( $area_type, $text, $fips, $limit );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
			$results = array_merge( $results, $found );
		}

		Cache::set( $key, $results, DAY_IN_SECONDS );
		return $results;
	}

	/**
	 * Returns one area's shape.
	 *
	 * @param string $record Record such as place:1228175, zcta:33701 or county:12103.
	 * @return array|\WP_Error
	 */
	public function fetch( string $record ) {
		if ( ! preg_match( '/^(place|cdp|zcta|county):(\d{5,7})$/', $record, $match ) ) {
			return new \WP_Error( 'mappulse_bad_record', __( 'That is not a valid Census record.', 'mappulse' ) );
		}

		$key    = 'census|shape|' . $record;
		$cached = Cache::get( $key );
		if ( null !== $cached ) {
			return $cached;
		}

		$type = '';
		foreach ( self::LAYERS as $area_type => $layer ) {
			if ( $layer[2] === $match[1] ) {
				$type = $area_type;
			}
		}
		$layer_id = Arcgis::find_layer( self::SERVICE, self::LAYERS[ $type ][0], self::LAYERS[ $type ][1] );
		if ( is_wp_error( $layer_id ) ) {
			return $layer_id;
		}

		$data = Arcgis::query(
			self::SERVICE . '/' . $layer_id,
			array(
				'where'     => self::LAYERS[ $type ][3] . "='" . $match[2] . "'",
				'outFields' => 'zip' === $type ? 'ZCTA5,BASENAME' : 'BASENAME,NAME,STATE,GEOID',
			),
			5
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['features'][0]['geometry'] ) ) {
			return new \WP_Error( 'mappulse_not_found', __( 'The Census has no shape for that record.', 'mappulse' ) );
		}

		$properties = isset( $data['features'][0]['properties'] ) && is_array( $data['features'][0]['properties'] ) ? $data['features'][0]['properties'] : array();
		$feature    = array(
			'type'       => 'Feature',
			'properties' => array(
				'source' => $this->id(),
				'record' => $record,
				'name'   => $this->name_of( $type, $properties ),
				'type'   => 'cdp' === $type ? 'city' : $type,
			),
			'geometry'   => $data['features'][0]['geometry'],
		);

		Cache::set( $key, $feature );
		return $feature;
	}

	/**
	 * Searches one layer: names that start with the text first, then names that contain it.
	 *
	 * @param string $type  Area type.
	 * @param string $text  LIKE pattern from Arcgis::like().
	 * @param string $fips  State FIPS code, or '' for the whole country.
	 * @param int    $limit Maximum results.
	 * @return array|\WP_Error
	 */
	private function search_layer( string $type, string $text, string $fips, int $limit ) {
		list( $pattern, $fallback, $prefix, $id_field ) = self::LAYERS[ $type ];

		$layer_id = Arcgis::find_layer( self::SERVICE, $pattern, $fallback );
		if ( is_wp_error( $layer_id ) ) {
			return $layer_id;
		}

		$wheres = 'zip' === $type
			? array( "ZCTA5 LIKE '" . $text . "%'" )
			: array( "UPPER(BASENAME) LIKE '" . $text . "%'", "UPPER(BASENAME) LIKE '%" . $text . "%'" );

		$features = array();
		foreach ( $wheres as $where ) {
			if ( 'zip' !== $type && '' !== $fips ) {
				$where .= " AND STATE='" . $fips . "'";
			}
			$data = Arcgis::query(
				self::SERVICE . '/' . $layer_id,
				array(
					'where'             => $where,
					'outFields'         => 'zip' === $type ? 'ZCTA5,BASENAME,CENTLON,CENTLAT' : 'BASENAME,NAME,STATE,GEOID,CENTLON,CENTLAT',
					'returnGeometry'    => 'false',
					'orderByFields'     => 'BASENAME',
					'resultRecordCount' => $limit,
				),
				$limit,
				true
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$features = $data['features'];
			if ( $features ) {
				break;
			}
		}

		$names   = array_column( self::states(), 1, 0 );
		$results = array();
		foreach ( $features as $feature ) {
			$properties = isset( $feature['properties'] ) && is_array( $feature['properties'] ) ? $feature['properties'] : array();
			$record_id  = isset( $properties[ $id_field ] ) ? preg_replace( '/\D/', '', (string) $properties[ $id_field ] ) : '';
			if ( '' === $record_id ) {
				continue;
			}
			$state  = isset( $properties['STATE'] ) ? (string) $properties['STATE'] : '';
			$center = isset( $properties['CENTLON'], $properties['CENTLAT'] ) && is_numeric( $properties['CENTLON'] ) && is_numeric( $properties['CENTLAT'] )
				? array( round( (float) $properties['CENTLON'], 4 ), round( (float) $properties['CENTLAT'], 4 ) )
				: null;

			$results[] = array(
				'source' => $this->id(),
				'record' => $prefix . ':' . $record_id,
				'name'   => $this->name_of( $type, $properties ),
				'type'   => 'cdp' === $type ? 'city' : $type,
				'parent' => 'zip' === $type ? __( 'ZIP area', 'mappulse' ) : ( $names[ $state ] ?? '' ),
				'center' => $center,
			);
		}

		return $results;
	}

	/**
	 * The display name of a record.
	 *
	 * @param string $type       Area type.
	 * @param array  $properties Feature properties.
	 */
	private function name_of( string $type, array $properties ): string {
		if ( 'zip' === $type ) {
			return sanitize_text_field( (string) ( $properties['ZCTA5'] ?? '' ) );
		}
		$field = 'county' === $type ? 'NAME' : 'BASENAME';
		return sanitize_text_field( (string) ( $properties[ $field ] ?? ( $properties['BASENAME'] ?? '' ) ) );
	}
}
