<?php
/**
 * The boundary service: one entry point over every source.
 *
 * The REST routes, the command line and the Sources screen all call this class.
 *
 * @package MapPulse
 */

namespace MapPulse\Boundaries;

defined( 'ABSPATH' ) || exit;

/**
 * Searches sources, fetches shapes and gathers base-map layers.
 */
class Service {

	const HYDRO     = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/Hydro/MapServer';
	const TRANSPORT = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/Transportation/MapServer';

	/**
	 * Largest side of a bounding box, in degrees.
	 */
	const MAX_SPAN = 1.5;

	/**
	 * Searches every source that offers the wanted type.
	 *
	 * @param string $query Text typed by a person.
	 * @param array  $args  type, state, limit.
	 * @return array results (list) and errors (source ID => message). One failing source never hides the others.
	 */
	public static function search( string $query, array $args = array() ): array {
		$text = str_replace( "''", "'", Arcgis::clean( $query ) );
		if ( mb_strlen( $text ) < 2 ) {
			return array(
				'results' => array(),
				'errors'  => array( 'query' => __( 'Type at least two letters or digits.', 'mappulse' ) ),
			);
		}

		$type    = isset( $args['type'] ) ? (string) $args['type'] : '';
		$results = array();
		$errors  = array();
		foreach ( Sources::all() as $provider ) {
			if ( '' !== $type && ! in_array( $type, $provider->types(), true ) ) {
				continue;
			}
			$found = $provider->search( $query, $args );
			if ( is_wp_error( $found ) ) {
				$errors[ $provider->id() ] = $found->get_error_message();
				continue;
			}
			$results = array_merge( $results, $found );
		}

		$rank = static function ( array $result ) use ( $text ): int {
			$name = mb_strtoupper( $result['name'] );
			if ( $name === $text ) {
				return 0;
			}
			if ( str_starts_with( $name, $text ) ) {
				return 1;
			}
			return str_contains( $name, $text ) ? 2 : 3;
		};
		usort(
			$results,
			static function ( array $a, array $b ) use ( $rank ): int {
				$order = $rank( $a ) <=> $rank( $b );
				return 0 !== $order ? $order : strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'results' => $results,
			'errors'  => $errors,
		);
	}

	/**
	 * Fetches one shape from one source.
	 *
	 * @param string $source Source ID.
	 * @param string $record Record ID from a search result.
	 * @return array|\WP_Error A GeoJSON Feature.
	 */
	public static function fetch( string $source, string $record ) {
		$provider = Sources::get( $source );
		if ( null === $provider ) {
			return new \WP_Error( 'mappulse_unknown_source', __( 'That boundary source is not registered on this site.', 'mappulse' ) );
		}
		return $provider->fetch( $record );
	}

	/**
	 * Reads "west,south,east,north" into four numbers.
	 *
	 * @param string $text Bounding box text.
	 * @return array|\WP_Error
	 */
	public static function parse_bbox( string $text ) {
		$parts = array_map( 'trim', explode( ',', $text ) );
		$error = new \WP_Error( 'mappulse_bad_bbox', __( 'The map area must be west, south, east, north in degrees, and no more than 1.5 degrees on a side.', 'mappulse' ) );
		if ( 4 !== count( $parts ) || count( array_filter( $parts, 'is_numeric' ) ) !== 4 ) {
			return $error;
		}

		list( $west, $south, $east, $north ) = array_map( 'floatval', $parts );
		if ( $west >= $east || $south >= $north || $west < -180 || $east > 180 || $south < -90 || $north > 90 || $east - $west > self::MAX_SPAN || $north - $south > self::MAX_SPAN ) {
			return $error;
		}
		return array( $west, $south, $east, $north );
	}

	/**
	 * Gathers the base-map layers that touch a bounding box.
	 *
	 * @param array $bbox West, south, east, north.
	 * @return array|\WP_Error FeatureCollections under land, cities, areas, water, roadsMajor and roadsMinor.
	 */
	public static function base( array $bbox ) {
		$bbox = self::parse_bbox( implode( ',', $bbox ) );
		if ( is_wp_error( $bbox ) ) {
			return $bbox;
		}

		// Round outward so nearby requests share one cache entry.
		$box = array( floor( $bbox[0] * 100 ) / 100, floor( $bbox[1] * 100 ) / 100, ceil( $bbox[2] * 100 ) / 100, ceil( $bbox[3] * 100 ) / 100 );

		$layers = Sources::layers();
		$key    = 'base|' . implode( ',', $box ) . '|' . implode( ',', wp_list_pluck( $layers, 'id' ) );
		$cached = Cache::get( $key );
		if ( null !== $cached ) {
			return $cached;
		}

		$within = array(
			'geometry'     => implode( ',', $box ),
			'geometryType' => 'esriGeometryEnvelope',
			'inSR'         => 4326,
			'spatialRel'   => 'esriSpatialRelIntersects',
		);

		// Each: result key, service, layer name pattern, fallback layer number, fields, extra parameters, feature cap.
		$census = array(
			array( 'land', Census_Provider::SERVICE, '/^counties$/i', 82, 'BASENAME,NAME', array(), 300 ),
			array( 'cities', Census_Provider::SERVICE, '/^incorporated places$/i', 28, 'BASENAME,NAME', array(), 1000 ),
			array( 'water', self::HYDRO, '/^areal hydrography$/i', 1, 'BASENAME,NAME', array( 'maxAllowableOffset' => 0.00004 ), 6000 ),
			array( 'roadsMajor', self::TRANSPORT, '/^primary roads$/i', 2, 'NAME', array(), 3000 ),
			array( 'roadsMinor', self::TRANSPORT, '/^secondary roads/i', 6, 'NAME', array(), 6000 ),
		);

		$base = array_fill_keys( array( 'land', 'cities', 'areas', 'water', 'roadsMajor', 'roadsMinor' ), null );
		foreach ( $census as $layer ) {
			$layer_id = Arcgis::find_layer( $layer[1], $layer[2], $layer[3] );
			if ( is_wp_error( $layer_id ) ) {
				return $layer_id;
			}
			$data = Arcgis::query( $layer[1] . '/' . $layer_id, array_merge( $within, array( 'outFields' => $layer[4] ), $layer[5] ), $layer[6] );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$base[ $layer[0] ] = $data;
		}

		// Registered layers are surrounding context. One that fails is left out instead of failing the map.
		$areas = array();
		foreach ( $layers as $layer ) {
			$data = Arcgis::query( $layer['url'], array_merge( $within, array( 'outFields' => $layer['name_field'] ) ), 3000 );
			if ( is_wp_error( $data ) ) {
				continue;
			}
			foreach ( $data['features'] as $feature ) {
				$feature['properties'] = array(
					'source' => $layer['id'],
					'name'   => sanitize_text_field( (string) ( $feature['properties'][ $layer['name_field'] ] ?? '' ) ),
				);
				$areas[]               = $feature;
			}
		}
		$base['areas'] = array(
			'type'     => 'FeatureCollection',
			'features' => $areas,
		);

		Cache::set( $key, $base );
		return $base;
	}
}
