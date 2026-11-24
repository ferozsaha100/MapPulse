<?php
/**
 * The map document: schema 1, its defaults and its validation.
 *
 * Everything the renderer prints comes from a document that passed through sanitize().
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and cleans map documents.
 */
class Map_Document {

	const SCHEMA = 1;

	/**
	 * SVG path data may hold path commands, digits, spaces, commas, dots and minus signs. Nothing else.
	 */
	const PATH_PATTERN = '/^[MmLlHhVvCcSsQqTtAaZz0-9 ,.\-]*$/';

	const LAYERS = array( 'land', 'cities', 'areas', 'water', 'roadsMinor', 'roadsMajor' );

	const FRAME_MIN = 100;
	const FRAME_MAX = 4000;

	/**
	 * Default style values. Their keys are the only style keys a document may carry.
	 */
	public static function defaults(): array {
		return array(
			'water'         => '#DCE8EA',
			'coast'         => '#B4C9CF',
			'land'          => '#F6F1E6',
			'context'       => '#EEE7D8',
			'contextStroke' => '#FFFFFF',
			'zone'          => '#DCCFB6',
			'zoneStroke'    => '#FFFFFF',
			'zoneHover'     => '#1F3A5F',
			'label'         => '#2B2F36',
			'labelHover'    => '#FFFFFF',
			'waterLabel'    => '#3F6470',
			'road'          => '#FFFFFF',
			'roadCasing'    => '#BDB198',
			'labelFont'     => 'Inter, Helvetica, Arial, sans-serif',
			'labelSize'     => 11.5,
		);
	}

	/**
	 * Hash of a document, used to tell whether a cached SVG still matches it.
	 *
	 * @param array $doc A sanitized document.
	 */
	public static function hash( array $doc ): string {
		return md5( wp_json_encode( $doc ) . MAPPULSE_VERSION );
	}

	/**
	 * Validates a raw document and returns a clean copy with only known keys.
	 *
	 * @param array $raw Decoded map file.
	 * @return array|\WP_Error
	 */
	public static function sanitize( array $raw ) {
		if ( ! isset( $raw['schema'] ) || self::SCHEMA !== (int) $raw['schema'] ) {
			return new \WP_Error( 'mappulse_schema', __( 'This map file uses a schema version MapPulse cannot read.', 'mappulse' ) );
		}

		$width  = self::number( $raw['frame']['width'] ?? null );
		$height = self::number( $raw['frame']['height'] ?? null );
		if ( null === $width || null === $height || $width < self::FRAME_MIN || $width > self::FRAME_MAX || $height < self::FRAME_MIN || $height > self::FRAME_MAX ) {
			return new \WP_Error( 'mappulse_frame', __( 'The map frame is missing or outside the allowed size.', 'mappulse' ) );
		}

		$raw_zones = isset( $raw['zones'] ) && is_array( $raw['zones'] ) ? array_values( $raw['zones'] ) : array();
		if ( count( $raw_zones ) > MAPPULSE_MAX_ZONES ) {
			return new \WP_Error( 'mappulse_too_many_zones', __( 'The map file holds more zones than MapPulse allows.', 'mappulse' ) );
		}

		$raw_topology = isset( $raw['topology'] ) && is_array( $raw['topology'] ) ? $raw['topology'] : array();
		$raw_shapes   = isset( $raw_topology['zones'] ) && is_array( $raw_topology['zones'] ) ? $raw_topology['zones'] : array();

		$layers = array();
		foreach ( self::LAYERS as $layer ) {
			$d = $raw_topology['layers'][ $layer ] ?? '';
			if ( ! self::is_path( $d ) ) {
				return self::bad_path();
			}
			$layers[ $layer ] = $d;
		}

		$zones  = array();
		$shapes = array();
		foreach ( $raw_zones as $raw_zone ) {
			if ( ! is_array( $raw_zone ) ) {
				continue;
			}
			$raw_id = (string) ( $raw_zone['id'] ?? '' );
			$id     = sanitize_title( $raw_id );
			if ( '' === $id ) {
				continue;
			}
			if ( isset( $shapes[ $id ] ) ) {
				return new \WP_Error( 'mappulse_duplicate_zone', __( 'Two zones in the map file share one ID.', 'mappulse' ) );
			}

			$raw_shape = $raw_shapes[ $raw_id ] ?? ( $raw_shapes[ $id ] ?? null );
			if ( ! is_array( $raw_shape ) || ! isset( $raw_shape['d'] ) || '' === $raw_shape['d'] ) {
				return new \WP_Error( 'mappulse_zone_shape', __( 'A zone in the map file has no shape.', 'mappulse' ) );
			}
			if ( ! self::is_path( $raw_shape['d'] ) ) {
				return self::bad_path();
			}

			$zones[]       = array(
				'id'     => $id,
				'name'   => self::text( $raw_zone['name'] ?? $id ),
				'type'   => sanitize_key( $raw_zone['type'] ?? '' ),
				'source' => array(
					'layer'  => self::text( $raw_zone['source']['layer'] ?? '' ),
					'record' => self::text( $raw_zone['source']['record'] ?? '' ),
				),
				'link'   => self::link( $raw_zone['link'] ?? '' ),
				'text'   => self::text( $raw_zone['text'] ?? '' ),
			);
			$shapes[ $id ] = array(
				'd'     => $raw_shape['d'],
				'label' => self::label( isset( $raw_shape['label'] ) && is_array( $raw_shape['label'] ) ? $raw_shape['label'] : array() ),
			);
		}

		$water_labels = array();
		$raw_labels   = isset( $raw_topology['waterLabels'] ) && is_array( $raw_topology['waterLabels'] ) ? $raw_topology['waterLabels'] : array();
		foreach ( array_slice( $raw_labels, 0, 40 ) as $raw_label ) {
			$x = self::number( $raw_label['x'] ?? null );
			$y = self::number( $raw_label['y'] ?? null );
			if ( null === $x || null === $y ) {
				continue;
			}
			$water_labels[] = array(
				'text'     => self::text( $raw_label['text'] ?? '', 60 ),
				'x'        => $x,
				'y'        => $y,
				'vertical' => ! empty( $raw_label['vertical'] ),
			);
		}

		return array(
			'schema'   => self::SCHEMA,
			'title'    => self::text( $raw['title'] ?? '' ),
			'frame'    => array(
				'width'  => $width,
				'height' => $height,
			),
			'style'    => self::style( isset( $raw['style'] ) && is_array( $raw['style'] ) ? $raw['style'] : array() ),
			'zones'    => $zones,
			'topology' => array(
				'layers'      => $layers,
				'zones'       => $shapes,
				'waterLabels' => $water_labels,
			),
			'meta'     => array(
				'generator' => self::text( $raw['meta']['generator'] ?? '' ),
				'synthetic' => ! empty( $raw['meta']['synthetic'] ),
			),
		);
	}

	/**
	 * Cleans the style block: known keys only, hex colors only, defaults for anything invalid.
	 *
	 * @param array $raw Raw style values.
	 */
	private static function style( array $raw ): array {
		$style = self::defaults();
		foreach ( $style as $key => $fallback ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				continue;
			}
			if ( 'labelSize' === $key ) {
				$size          = self::number( $raw[ $key ] );
				$style[ $key ] = null === $size ? $fallback : min( 40, max( 6, $size ) );
			} elseif ( 'labelFont' === $key ) {
				$font          = trim( preg_replace( '/[^A-Za-z0-9 ,_\-]/', '', (string) $raw[ $key ] ) );
				$style[ $key ] = '' === $font ? $fallback : $font;
			} else {
				$color         = sanitize_hex_color( (string) $raw[ $key ] );
				$style[ $key ] = $color ? $color : $fallback;
			}
		}
		return $style;
	}

	/**
	 * Cleans one zone label.
	 *
	 * @param array $raw Raw label.
	 */
	private static function label( array $raw ): array {
		$lines = array();
		foreach ( array_slice( isset( $raw['lines'] ) && is_array( $raw['lines'] ) ? $raw['lines'] : array(), 0, 4 ) as $line ) {
			$line = self::text( $line, 40 );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		$size   = self::number( $raw['size'] ?? null );
		$anchor = null;
		if ( isset( $raw['anchor'] ) && is_array( $raw['anchor'] ) ) {
			$ax = self::number( $raw['anchor']['x'] ?? null );
			$ay = self::number( $raw['anchor']['y'] ?? null );
			if ( null !== $ax && null !== $ay ) {
				$anchor = array(
					'x' => $ax,
					'y' => $ay,
				);
			}
		}

		return array(
			'x'      => (float) self::number( $raw['x'] ?? 0 ),
			'y'      => (float) self::number( $raw['y'] ?? 0 ),
			'size'   => null === $size ? self::defaults()['labelSize'] : min( 40, max( 6, $size ) ),
			'lines'  => $lines,
			'anchor' => $anchor,
		);
	}

	/**
	 * Plain text, optionally cut to a maximum length.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum length in characters, 0 for no limit.
	 */
	private static function text( $value, int $max = 0 ): string {
		$text = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		return $max > 0 ? mb_substr( $text, 0, $max ) : $text;
	}

	/**
	 * A link: http, https or a site-relative path. Anything else becomes an empty string.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function link( $value ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}
		return esc_url_raw( trim( $value ), array( 'http', 'https' ) );
	}

	/**
	 * A finite number, or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function number( $value ): ?float {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && is_numeric( $value ) ) ) {
			return null;
		}
		$number = (float) $value;
		return is_finite( $number ) ? round( $number, 2 ) : null;
	}

	/**
	 * Whether a value is acceptable SVG path data.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function is_path( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::PATH_PATTERN, $value );
	}

	/**
	 * The error for path data that holds anything but path commands and numbers.
	 */
	private static function bad_path(): \WP_Error {
		return new \WP_Error( 'mappulse_bad_path', __( 'The map file holds shape data MapPulse does not accept.', 'mappulse' ) );
	}
}
