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

	const PRIORITY = array( 'neighborhood', 'city', 'zip', 'county' );

	// Limits on what a map may store: one zone's source boundary, and the whole document, as JSON.
	const MAX_ZONE_BYTES = 400000;
	const MAX_BYTES      = 4000000;

	const PRESETS       = array( 'atlas', 'brand-tint', 'minimal-line' );
	const HOVER_EFFECTS = array( 'fill', 'outline' );

	// Border styles and the dash pattern each one draws with.
	const STROKE_STYLES = array(
		'solid'  => 'none',
		'dashed' => '6 4',
		'dotted' => '1.5 3.5',
	);

	const CARD_COLORS = array(
		'cardBackground' => '#FFFFFF',
		'cardText'       => '#1D2327',
		'cardButton'     => '',
		'cardButtonText' => '#FFFFFF',
	);

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
	 * The full built-in style block: the default colors, font and size, and the border and hover values.
	 */
	public static function style_defaults(): array {
		return self::style( array() );
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
		$seen   = array();
		foreach ( $raw_zones as $raw_zone ) {
			if ( ! is_array( $raw_zone ) ) {
				continue;
			}
			$raw_id = (string) ( $raw_zone['id'] ?? '' );
			$id     = sanitize_title( $raw_id );
			if ( '' === $id ) {
				continue;
			}
			if ( isset( $seen[ $id ] ) ) {
				return new \WP_Error( 'mappulse_duplicate_zone', __( 'Two zones in the map file share one ID.', 'mappulse' ) );
			}

			$name = self::text( $raw_zone['name'] ?? $id );

			// A zone added in the builder carries the boundary it came from, so the map can be rebuilt.
			$geometry = null;
			if ( isset( $raw_zone['geometry'] ) ) {
				$geometry = self::geometry( $raw_zone['geometry'], $name );
				if ( is_wp_error( $geometry ) ) {
					return $geometry;
				}
			}

			// A zone made in the builder is marked "built". Its boundary is stored beside the document, so the
			// mark is what tells a stored builder zone from an imported one once the boundary is set aside.
			$built = null !== $geometry || ! empty( $raw_zone['built'] );

			// Such a zone may have nothing left to draw once other zones are cut out of it. It is kept, hidden.
			$raw_shape = $raw_shapes[ $raw_id ] ?? ( $raw_shapes[ $id ] ?? null );
			$has_shape = is_array( $raw_shape ) && isset( $raw_shape['d'] ) && '' !== $raw_shape['d'];
			if ( ! $has_shape && ! ( $built && ( null !== $geometry || ! empty( $raw_zone['hidden'] ) ) ) ) {
				return new \WP_Error( 'mappulse_zone_shape', __( 'A zone in the map file has no shape.', 'mappulse' ) );
			}
			if ( $has_shape && ! self::is_path( $raw_shape['d'] ) ) {
				return self::bad_path();
			}

			$zone     = array(
				'id'     => $id,
				'name'   => $name,
				'type'   => sanitize_key( $raw_zone['type'] ?? '' ),
				'source' => array(
					'layer'  => self::text( $raw_zone['source']['layer'] ?? '' ),
					'record' => self::text( $raw_zone['source']['record'] ?? '' ),
				),
				'link'   => self::link( $raw_zone['link'] ?? '' ),
				'text'   => self::text( $raw_zone['text'] ?? '' ),
			);
			$override = self::label_override( $raw_zone['label'] ?? null, max( $width, $height ) );
			if ( null !== $override ) {
				$zone['label'] = $override;
			}
			// A link chosen from the site's pages keeps the page's ID, so the map follows the page if it moves.
			$link_post = isset( $raw_zone['linkPost'] ) && is_numeric( $raw_zone['linkPost'] ) ? (int) $raw_zone['linkPost'] : 0;
			if ( $link_post > 0 ) {
				$zone['linkPost'] = $link_post;
			}
			$card = self::card( $raw_zone['card'] ?? null );
			if ( null !== $card ) {
				$zone['card'] = $card;
			}
			if ( $built ) {
				if ( null !== $geometry ) {
					$zone['geometry'] = $geometry;
				}
				$zone['built']    = true;
				$zone['backdrop'] = ! empty( $raw_zone['backdrop'] );
				$zone['clean']    = ! empty( $raw_zone['clean'] );
				if ( ! $has_shape ) {
					$zone['hidden'] = true;
				}
			}
			$zones[]     = $zone;
			$seen[ $id ] = true;
			if ( $has_shape ) {
				$shapes[ $id ] = array(
					'd'     => $raw_shape['d'],
					'label' => self::label( isset( $raw_shape['label'] ) && is_array( $raw_shape['label'] ) ? $raw_shape['label'] : array() ),
				);
			}
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

		$frame = array(
			'width'  => $width,
			'height' => $height,
		);
		// Frame units per projected unit, written by the builder. It lets hand-set label offsets be rescaled.
		$scale = self::number( $raw['frame']['scale'] ?? null );
		if ( null !== $scale && $scale > 0 ) {
			$frame['scale'] = (float) $scale;
		}

		$doc = array(
			'schema'   => self::SCHEMA,
			'title'    => self::text( $raw['title'] ?? '' ),
			'frame'    => $frame,
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

		if ( isset( $raw['priority'] ) ) {
			$doc['priority'] = self::priority( $raw['priority'] );
		}

		if ( strlen( (string) wp_json_encode( $doc ) ) > self::MAX_BYTES ) {
			return new \WP_Error( 'mappulse_too_large', __( 'This map is too large to save. Remove an area, or use areas with simpler outlines.', 'mappulse' ) );
		}
		return $doc;
	}

	/**
	 * Cleans the order in which area types cut each other. Anything but a full reordering of the four types gives the default.
	 *
	 * @param mixed $raw Raw priority list.
	 */
	private static function priority( $raw ): array {
		$raw    = is_array( $raw ) ? array_values( array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) ) : array();
		$sorted = $raw;
		sort( $sorted );
		$wanted = self::PRIORITY;
		sort( $wanted );
		return $sorted === $wanted ? $raw : self::PRIORITY;
	}

	/**
	 * Cleans the source boundary of one zone: a GeoJSON Polygon or MultiPolygon holding numbers only.
	 *
	 * @param mixed  $raw  Raw geometry.
	 * @param string $name Zone name, for the error message.
	 * @return array|\WP_Error
	 */
	private static function geometry( $raw, string $name ) {
		/* translators: %s: area name. */
		$bad  = new \WP_Error( 'mappulse_zone_geometry', sprintf( __( 'The boundary stored for "%s" is not a valid shape.', 'mappulse' ), $name ) );
		$type = is_array( $raw ) ? ( $raw['type'] ?? '' ) : '';
		if ( ! in_array( $type, array( 'Polygon', 'MultiPolygon' ), true ) || ! isset( $raw['coordinates'] ) || ! is_array( $raw['coordinates'] ) ) {
			return $bad;
		}

		$polygons = array();
		foreach ( 'Polygon' === $type ? array( $raw['coordinates'] ) : $raw['coordinates'] as $polygon ) {
			if ( ! is_array( $polygon ) ) {
				return $bad;
			}
			$rings = array();
			foreach ( $polygon as $ring ) {
				if ( ! is_array( $ring ) ) {
					return $bad;
				}
				$points = array();
				foreach ( $ring as $point ) {
					if ( ! is_array( $point ) || ! isset( $point[0], $point[1] ) || ! is_numeric( $point[0] ) || ! is_numeric( $point[1] )
						|| abs( (float) $point[0] ) > 180 || abs( (float) $point[1] ) > 90 ) {
						return $bad;
					}
					$points[] = array( (float) $point[0], (float) $point[1] );
				}
				$rings[] = $points;
			}
			$polygons[] = $rings;
		}

		$geometry = array(
			'type'        => $type,
			'coordinates' => 'Polygon' === $type ? $polygons[0] : $polygons,
		);
		if ( strlen( (string) wp_json_encode( $geometry ) ) > self::MAX_ZONE_BYTES ) {
			return new \WP_Error(
				'mappulse_zone_too_large',
				/* translators: %s: area name. */
				sprintf( __( 'The boundary of "%s" is too detailed to store. Choose a simpler area.', 'mappulse' ), $name )
			);
		}
		return $geometry;
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
		$preset                   = isset( $raw['preset'] ) && is_string( $raw['preset'] ) ? $raw['preset'] : '';
		$style['preset']          = in_array( $preset, self::PRESETS, true ) ? $preset : self::PRESETS[0];
		$width                    = self::number( $raw['zoneStrokeWidth'] ?? null );
		$style['zoneStrokeWidth'] = null === $width ? 1.6 : (float) min( 4, max( 0, $width ) );
		$line                     = isset( $raw['zoneStrokeStyle'] ) && is_string( $raw['zoneStrokeStyle'] ) ? $raw['zoneStrokeStyle'] : '';
		$style['zoneStrokeStyle'] = isset( self::STROKE_STYLES[ $line ] ) ? $line : 'solid';
		$hover                    = isset( $raw['hoverEffect'] ) && is_string( $raw['hoverEffect'] ) ? $raw['hoverEffect'] : '';
		$style['hoverEffect']     = in_array( $hover, self::HOVER_EFFECTS, true ) ? $hover : self::HOVER_EFFECTS[0];
		$brand                    = isset( $raw['brand'] ) && is_string( $raw['brand'] ) ? sanitize_hex_color( $raw['brand'] ) : '';
		$style['brand']           = $brand ? $brand : '';
		// Hover card colors. An empty button color means "use the hover color".
		foreach ( self::CARD_COLORS as $key => $fallback ) {
			$color         = isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ? sanitize_hex_color( $raw[ $key ] ) : '';
			$style[ $key ] = $color ? $color : $fallback;
		}
		return $style;
	}

	/**
	 * Cleans a hover card: a photo, a tagline, up to two facts and up to two buttons.
	 * Returns null when it holds nothing, so an area without a card stores no card key.
	 *
	 * @param mixed $raw Raw card.
	 */
	private static function card( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$image   = isset( $raw['image'] ) && is_numeric( $raw['image'] ) ? max( 0, (int) $raw['image'] ) : 0;
		$tagline = self::text( $raw['tagline'] ?? '', 120 );

		$stats = array();
		foreach ( isset( $raw['stats'] ) && is_array( $raw['stats'] ) ? $raw['stats'] : array() as $stat ) {
			if ( ! is_array( $stat ) || count( $stats ) >= 2 ) {
				continue;
			}
			$label = self::text( $stat['label'] ?? '', 24 );
			$value = self::text( $stat['value'] ?? '', 24 );
			if ( '' !== $label || '' !== $value ) {
				$stats[] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}

		$buttons = array();
		foreach ( isset( $raw['buttons'] ) && is_array( $raw['buttons'] ) ? $raw['buttons'] : array() as $button ) {
			if ( ! is_array( $button ) || count( $buttons ) >= 2 ) {
				continue;
			}
			$label = self::text( $button['label'] ?? '', 24 );
			$url   = self::link( $button['url'] ?? '' );
			if ( '' === $label || '' === $url ) {
				continue;
			}
			$buttons[] = array(
				'label' => $label,
				'url'   => $url,
				'post'  => isset( $button['post'] ) && is_numeric( $button['post'] ) ? max( 0, (int) $button['post'] ) : 0,
			);
		}

		if ( 0 === $image && '' === $tagline && ! $stats && ! $buttons ) {
			return null;
		}
		return array(
			'image'   => $image,
			'tagline' => $tagline,
			'stats'   => $stats,
			'buttons' => $buttons,
		);
	}

	/**
	 * Cleans a hand-set label: its own text, an offset from where the builder placed it, or hidden.
	 * Returns null when nothing is set, so an untouched zone stores no label key.
	 *
	 * @param mixed $raw   Raw label override.
	 * @param float $limit The frame's larger side; an offset never exceeds it.
	 */
	private static function label_override( $raw, float $limit ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$text   = self::text( $raw['text'] ?? '', 60 );
		$dx     = self::number( $raw['dx'] ?? null );
		$dy     = self::number( $raw['dy'] ?? null );
		$dx     = null === $dx ? 0.0 : (float) min( $limit, max( -$limit, $dx ) );
		$dy     = null === $dy ? 0.0 : (float) min( $limit, max( -$limit, $dy ) );
		$hidden = ! empty( $raw['hidden'] );
		if ( '' === $text && 0.0 === $dx && 0.0 === $dy && ! $hidden ) {
			return null;
		}
		$label = array(
			'text'   => $text,
			'dx'     => $dx,
			'dy'     => $dy,
			'hidden' => $hidden,
		);
		// The frame scale the offset was set at, so the builder can keep its distance on the ground.
		$scale = self::number( $raw['scale'] ?? null );
		if ( null !== $scale && $scale > 0 ) {
			$label['scale'] = (float) $scale;
		}
		return $label;
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
