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
	 * The base-map layers, in draw order from the bottom.
	 */
	const BASE_LAYERS = array( 'land', 'cities', 'areas', 'water', 'roadsMajor', 'roadsMinor' );

	/**
	 * Searches every source that offers the wanted type.
	 *
	 * @param string $query Text typed by a person.
	 * @param array  $args  type, state, limit.
	 * @return array results (list) and errors (source ID => message). One failing source never hides the others.
	 */
	public static function search( string $query, array $args = array() ): array {
		if ( mb_strlen( str_replace( '%', '', Arcgis::like( $query ) ) ) < 2 ) {
			return array(
				'results' => array(),
				'errors'  => array( 'query' => __( 'Type at least two letters or digits.', 'mappulse' ) ),
				'more'    => false,
			);
		}

		$type    = isset( $args['type'] ) ? (string) $args['type'] : '';
		$limit   = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 20;
		$results = array();
		$errors  = array();
		$more    = false;
		foreach ( Sources::all() as $provider ) {
			if ( '' !== $type && ! in_array( $type, $provider->types(), true ) ) {
				continue;
			}
			$found = $provider->search( $query, array_merge( $args, array( 'limit' => $limit ) ) );
			if ( is_wp_error( $found ) ) {
				$errors[ $provider->id() ] = $found->get_error_message();
				continue;
			}
			// A source that filled its limit very likely has more matches than it returned.
			$more    = $more || count( $found ) >= $limit;
			$results = array_merge( $results, $found );
		}

		// Rank on letters and digits only, so "st petersburg" equals "St. Petersburg".
		$plain = static function ( string $text ): string {
			return (string) preg_replace( '/[^\\p{L}\\p{N}]/u', '', mb_strtoupper( $text ) );
		};
		$text  = $plain( $query );
		$rank  = static function ( array $result ) use ( $text, $plain ): int {
			$name = $plain( $result['name'] );
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
			'more'    => $more,
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
	 * Counts what a shape holds: rings, points and its bounding box.
	 *
	 * @param array $feature A GeoJSON Feature with a Polygon or MultiPolygon geometry.
	 * @return array rings, points, bbox (west, south, east, north; null when the shape is empty).
	 */
	public static function describe( array $feature ): array {
		$type     = $feature['geometry']['type'] ?? '';
		$coords   = $feature['geometry']['coordinates'] ?? array();
		$polygons = 'Polygon' === $type ? array( $coords ) : ( 'MultiPolygon' === $type ? $coords : array() );
		$rings    = 0;
		$points   = 0;
		$box      = null;

		foreach ( $polygons as $polygon ) {
			foreach ( is_array( $polygon ) ? $polygon : array() as $ring ) {
				++$rings;
				foreach ( is_array( $ring ) ? $ring : array() as $point ) {
					if ( ! isset( $point[0], $point[1] ) || ! is_numeric( $point[0] ) || ! is_numeric( $point[1] ) ) {
						continue;
					}
					++$points;
					$x   = (float) $point[0];
					$y   = (float) $point[1];
					$box = null === $box ? array( $x, $y, $x, $y ) : array( min( $box[0], $x ), min( $box[1], $y ), max( $box[2], $x ), max( $box[3], $y ) );
				}
			}
		}

		return array(
			'rings'  => $rings,
			'points' => $points,
			'bbox'   => $box,
		);
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
	 * Gathers every base-map layer that touches a bounding box.
	 *
	 * @param array $bbox West, south, east, north.
	 * @return array|\WP_Error FeatureCollections under land, cities, areas, water, roadsMajor and roadsMinor,
	 *                         plus errors (source ID => message) for registered layers that could not be read.
	 */
	public static function base( array $bbox ) {
		$base   = array();
		$errors = array();
		foreach ( self::BASE_LAYERS as $name ) {
			$layer = self::base_layer( $bbox, $name );
			if ( is_wp_error( $layer ) ) {
				return $layer;
			}
			if ( isset( $layer['errors'] ) ) {
				$errors = array_merge( $errors, $layer['errors'] );
				unset( $layer['errors'] );
			}
			$base[ $name ] = $layer;
		}
		$base['errors'] = $errors;
		return $base;
	}

	/**
	 * Gathers one base-map layer that touches a bounding box. Each layer is cached on its own.
	 *
	 * @param array  $bbox West, south, east, north.
	 * @param string $name One of BASE_LAYERS.
	 * @return array|\WP_Error A FeatureCollection. For "areas" it may carry errors (source ID => message).
	 */
	public static function base_layer( array $bbox, string $name ) {
		$bbox = self::parse_bbox( implode( ',', $bbox ) );
		if ( is_wp_error( $bbox ) ) {
			return $bbox;
		}
		if ( ! in_array( $name, self::BASE_LAYERS, true ) ) {
			return new \WP_Error( 'mappulse_bad_layer', __( 'That is not a base-map layer.', 'mappulse' ) );
		}

		// Boundary answers are large once decoded. Use the higher memory limit WordPress allows in the admin.
		wp_raise_memory_limit( 'admin' );

		// Round outward so nearby requests share one cache entry.
		$box    = array( floor( $bbox[0] * 100 ) / 100, floor( $bbox[1] * 100 ) / 100, ceil( $bbox[2] * 100 ) / 100, ceil( $bbox[3] * 100 ) / 100 );
		$layers = 'areas' === $name ? Sources::layers() : array();
		$key    = 'base|' . $name . '|' . implode( ',', $box ) . '|' . md5( implode( ',', wp_list_pluck( $layers, 'url' ) ) );
		$cached = Cache::get( $key );
		if ( null !== $cached ) {
			return $cached;
		}

		$within = array(
			'geometry'           => implode( ',', $box ),
			'geometryType'       => 'esriGeometryEnvelope',
			'inSR'               => 4326,
			'spatialRel'         => 'esriSpatialRelIntersects',
			// About four metres: far below what a map this size can show, and it keeps answers small.
			'maxAllowableOffset' => 0.00004,
		);

		if ( 'areas' !== $name ) {
			// Each: service, layer name pattern, fallback layer number, fields, feature cap.
			$census = array(
				'land'       => array( Census_Provider::SERVICE, '/^counties$/i', 82, 'BASENAME,NAME', 300 ),
				'cities'     => array( Census_Provider::SERVICE, '/^incorporated places$/i', 28, 'BASENAME,NAME', 1000 ),
				'water'      => array( self::HYDRO, '/^areal hydrography$/i', 1, 'BASENAME,NAME', 6000 ),
				'roadsMajor' => array( self::TRANSPORT, '/^primary roads$/i', 2, 'NAME', 3000 ),
				'roadsMinor' => array( self::TRANSPORT, '/^secondary roads/i', 6, 'NAME', 6000 ),
			);
			list( $service, $pattern, $fallback, $fields, $cap ) = $census[ $name ];

			$layer_id = Arcgis::find_layer( $service, $pattern, $fallback );
			if ( is_wp_error( $layer_id ) ) {
				return $layer_id;
			}
			$data = Arcgis::query( $service . '/' . $layer_id, array_merge( $within, array( 'outFields' => $fields ) ), $cap );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			Cache::set( $key, $data );
			return $data;
		}

		// Registered layers are surrounding context. One that fails is left out and reported, and the
		// incomplete answer is not saved, so it is asked for again next time.
		$areas  = array();
		$errors = array();
		foreach ( $layers as $layer ) {
			$data = Arcgis::query( $layer['url'], array_merge( $within, array( 'outFields' => $layer['name_field'] ) ), 3000 );
			if ( is_wp_error( $data ) ) {
				$errors[ $layer['id'] ] = $data->get_error_message();
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

		$result = array(
			'type'     => 'FeatureCollection',
			'features' => $areas,
		);
		if ( ! $errors ) {
			Cache::set( $key, $result );
			return $result;
		}
		$result['errors'] = $errors;
		return $result;
	}
}
