<?php
/**
 * Turns a sanitized map document into markup.
 *
 * svg() depends only on the document, so its result can be cached. section() wraps it with the
 * heading, the area list and the per-placement options.
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the map SVG and the section around it.
 */
class Renderer {

	/**
	 * How many map sections have been printed in this request. Makes card ids unique on a page.
	 *
	 * @var int
	 */
	private static $instances = 0;

	const LAYOUTS = array( 'list-left', 'list-right', 'list-below', 'map-only' );

	/**
	 * Style keys and the custom properties that carry them on the section.
	 *
	 * These are the document's defaults. The stylesheet reads var(--mappulse-zone, var(--mappulse-doc-zone)),
	 * so a placement or a theme overrides a value by setting the public name (--mappulse-zone) anywhere above.
	 */
	const VARIABLES = array(
		'water'         => '--mappulse-doc-water',
		'coast'         => '--mappulse-doc-coast',
		'land'          => '--mappulse-doc-land',
		'context'       => '--mappulse-doc-context',
		'contextStroke' => '--mappulse-doc-context-stroke',
		'zone'          => '--mappulse-doc-zone',
		'zoneStroke'    => '--mappulse-doc-zone-stroke',
		'zoneHover'     => '--mappulse-doc-zone-hover',
		'label'         => '--mappulse-doc-label',
		'labelHover'    => '--mappulse-doc-label-hover',
		'waterLabel'    => '--mappulse-doc-water-label',
		'road'          => '--mappulse-doc-road',
		'roadCasing'    => '--mappulse-doc-road-casing',
		'labelFont'     => '--mappulse-doc-font',
	);

	/**
	 * The map as one <svg> element. Carries no ids, styles or scripts, so any number of copies can share a page.
	 *
	 * @param array $doc A sanitized map document.
	 */
	public static function svg( array $doc ): string {
		$width    = self::num( $doc['frame']['width'] );
		$height   = self::num( $doc['frame']['height'] );
		$layers   = $doc['topology']['layers'];
		$shapes   = $doc['topology']['zones'];
		$zones    = '';
		$leaders  = '';
		$labels   = '';
		$captions = '';

		foreach ( $doc['zones'] as $zone ) {
			$id = $zone['id'];
			if ( ! isset( $shapes[ $id ] ) ) {
				continue;
			}
			$shape = $shapes[ $id ];
			$path  = '<path d="' . esc_attr( $shape['d'] ) . '" fill-rule="evenodd"/>';

			$link = self::link( $zone );
			if ( '' !== $link ) {
				$zones .= '<a class="mappulse__zone" href="' . esc_url( $link ) . '" data-zone="' . esc_attr( $id ) . '" aria-label="' . esc_attr( $zone['name'] ) . '">' . $path . '</a>';
			} else {
				$zones .= '<g class="mappulse__zone mappulse__zone--static" tabindex="0" role="img" data-zone="' . esc_attr( $id ) . '" aria-label="' . esc_attr( $zone['name'] ) . '">' . $path . '</g>';
			}

			$label = self::placed_label( $shape['label'], $zone['label'] ?? null, (float) $doc['frame']['width'], (float) $doc['frame']['height'] );
			if ( null === $label ) {
				continue;
			}
			$size        = (float) $label['size'];
			$line_height = $size * 1.18;
			$first       = $label['y'] - ( ( count( $label['lines'] ) - 1 ) * $line_height ) / 2 + $size * 0.36;
			$spans       = '';
			foreach ( $label['lines'] as $index => $line ) {
				$spans .= '<tspan x="' . self::num( $label['x'] ) . '" y="' . self::num( $first + $index * $line_height ) . '">' . esc_html( $line ) . '</tspan>';
			}
			$labels .= '<text class="mappulse__label" data-zone="' . esc_attr( $id ) . '" text-anchor="middle" font-size="' . self::num( $size ) . '">' . $spans . '</text>';

			if ( $label['anchor'] ) {
				$leaders .= self::leader( $label );
			}
		}

		foreach ( $doc['topology']['waterLabels'] as $caption ) {
			$place     = $caption['vertical']
				? 'transform="translate(' . self::num( $caption['x'] ) . ' ' . self::num( $caption['y'] ) . ') rotate(90)"'
				: 'x="' . self::num( $caption['x'] ) . '" y="' . self::num( $caption['y'] ) . '"';
			$captions .= '<text class="mappulse__water-label" ' . $place . ' text-anchor="middle">' . esc_html( $caption['text'] ) . '</text>';
		}

		$roads = '';
		foreach ( array(
			'minor' => $layers['roadsMinor'],
			'major' => $layers['roadsMajor'],
		) as $rank => $d ) {
			if ( '' === $d ) {
				continue;
			}
			$roads .= '<path class="mappulse__road-casing mappulse__road-casing--' . $rank . '" d="' . esc_attr( $d ) . '"/>';
			$roads .= '<path class="mappulse__road mappulse__road--' . $rank . '" d="' . esc_attr( $d ) . '"/>';
		}

		return '<svg class="mappulse__svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" role="group" aria-label="' . esc_attr( $doc['title'] ) . '">'
			. '<rect class="mappulse__sea" width="' . $width . '" height="' . $height . '"/>'
			. self::group( 'land', self::shape( $layers['land'] ) )
			. self::group( 'cities', self::shape( $layers['cities'] ) )
			. self::group( 'areas', self::shape( $layers['areas'] ) )
			. self::group( 'zones', $zones )
			. self::group( 'water', self::shape( $layers['water'] ) )
			. self::group( 'roads', $roads )
			. self::group( 'leaders', $leaders )
			. self::group( 'labels', $labels )
			. self::group( 'water-labels', $captions )
			. '</svg>';
	}

	/**
	 * Where a zone links to. A link chosen from the site's pages follows the page while it is published;
	 * otherwise the address stored with the map is used.
	 *
	 * @param array $zone A sanitized zone.
	 */
	public static function link( array $zone ): string {
		return self::resolve( (string) $zone['link'], (int) ( $zone['linkPost'] ?? 0 ) );
	}

	/**
	 * An address that follows a page of this site while that page is published, and otherwise the stored one.
	 *
	 * @param string $url     The stored address.
	 * @param int    $post_id The page or post it was chosen from, or 0.
	 */
	public static function resolve( string $url, int $post_id ): string {
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( $post && 'publish' === $post->post_status && is_post_type_viewable( $post->post_type ) ) {
				$link = get_permalink( $post );
				if ( $link ) {
					return $link;
				}
			}
		}
		return $url;
	}

	/**
	 * One area's hover card: real markup, hidden until the view script shows it.
	 * An empty string when the area has nothing to show in a card.
	 *
	 * @param array  $zone A sanitized zone.
	 * @param string $id   The id attribute for the card, unique on the page.
	 */
	public static function card( array $zone, string $id ): string {
		if ( empty( $zone['card'] ) || ! empty( $zone['hidden'] ) ) {
			return '';
		}
		$card = $zone['card'];
		$body = '';

		if ( $card['image'] > 0 && wp_attachment_is_image( $card['image'] ) ) {
			$image = wp_get_attachment_image(
				$card['image'],
				'medium_large',
				false,
				array(
					'class'   => 'mappulse__card-image',
					'loading' => 'lazy',
					'alt'     => '',
				)
			);
			if ( $image ) {
				$body .= '<div class="mappulse__card-photo">' . $image . '</div>';
			}
		}
		if ( '' !== $card['tagline'] ) {
			$body .= '<p class="mappulse__card-tagline">' . esc_html( $card['tagline'] ) . '</p>';
		}
		if ( $card['stats'] ) {
			$body .= '<dl class="mappulse__card-stats">';
			foreach ( $card['stats'] as $stat ) {
				$body .= '<div><dt>' . esc_html( $stat['label'] ) . '</dt><dd>' . esc_html( $stat['value'] ) . '</dd></div>';
			}
			$body .= '</dl>';
		}

		$buttons = array();
		foreach ( $card['buttons'] as $button ) {
			$buttons[] = array( self::resolve( $button['url'], (int) $button['post'] ), $button['label'] );
		}
		// A card with no buttons of its own leads to the area's link.
		$link = self::link( $zone );
		if ( ! $buttons && '' !== $link ) {
			$buttons[] = array( $link, __( 'View area', 'mappulse' ) );
		}
		// A card that would hold only a button to where the area already leads says nothing new.
		if ( '' === $body && ! $card['buttons'] ) {
			return '';
		}
		if ( $buttons ) {
			$body .= '<p class="mappulse__card-actions">';
			foreach ( $buttons as $button ) {
				$body .= '<a class="mappulse__card-button" href="' . esc_url( $button[0] ) . '">' . esc_html( $button[1] ) . '</a>';
			}
			$body .= '</p>';
		}

		return '<div class="mappulse__card" id="' . esc_attr( $id ) . '" data-zone="' . esc_attr( $zone['id'] ) . '" hidden>'
			. '<h3 class="mappulse__card-title">' . esc_html( $zone['name'] ) . '</h3>' . $body . '</div>';
	}

	/**
	 * Upper-case label text over at most two lines, broken at the space nearest the middle.
	 *
	 * @param string $text Label text.
	 */
	public static function label_lines( string $text ): array {
		$text  = mb_strtoupper( trim( $text ) );
		$words = preg_split( '/\s+/', $text );
		if ( count( $words ) < 2 || mb_strlen( $text ) <= 9 ) {
			return '' === $text ? array() : array( $text );
		}
		$best  = 1;
		$score = PHP_INT_MAX;
		for ( $i = 1, $n = count( $words ); $i < $n; $i++ ) {
			$diff = abs( mb_strlen( implode( ' ', array_slice( $words, 0, $i ) ) ) - mb_strlen( implode( ' ', array_slice( $words, $i ) ) ) );
			if ( $diff < $score ) {
				$score = $diff;
				$best  = $i;
			}
		}
		return array( implode( ' ', array_slice( $words, 0, $best ) ), implode( ' ', array_slice( $words, $best ) ) );
	}

	/**
	 * A zone's label after any hand-set text, offset or hiding. Null when there is nothing to draw.
	 *
	 * @param array      $label    The label the builder placed.
	 * @param array|null $override The zone's hand-set label, if any.
	 * @param float      $width    Frame width.
	 * @param float      $height   Frame height.
	 */
	private static function placed_label( array $label, ?array $override, float $width, float $height ): ?array {
		if ( null === $override ) {
			return $label['lines'] ? $label : null;
		}
		if ( $override['hidden'] ) {
			return null;
		}
		if ( '' !== $override['text'] ) {
			$label['lines'] = self::label_lines( $override['text'] );
		}
		if ( ! $label['lines'] ) {
			return null;
		}
		if ( 0.0 === (float) $override['dx'] && 0.0 === (float) $override['dy'] ) {
			return $label;
		}

		$size   = (float) $label['size'];
		$half_w = ( max( array_map( 'mb_strlen', $label['lines'] ) ) * $size * 0.8 + 6 ) / 2;
		$half_h = ( count( $label['lines'] ) * $size * 1.18 + 3 ) / 2;
		// The point the label belongs to: its leader's anchor if it has one, else where the builder put it.
		$home = $label['anchor'] ? $label['anchor'] : array(
			'x' => $label['x'],
			'y' => $label['y'],
		);

		$label['x'] = min( max( $label['x'] + $override['dx'], min( $half_w + 4, $width / 2 ) ), max( $width - $half_w - 4, $width / 2 ) );
		$label['y'] = min( max( $label['y'] + $override['dy'], min( $half_h + 4, $height / 2 ) ), max( $height - $half_h - 4, $height / 2 ) );
		// A label moved clear of its point is tied back to it with a leader line.
		$clear           = abs( $home['x'] - $label['x'] ) > $half_w + 3 || abs( $home['y'] - $label['y'] ) > $half_h + 3;
		$label['anchor'] = $clear ? $home : null;
		return $label;
	}

	/**
	 * The whole section: heading, intro, area list and the map.
	 *
	 * @param array  $doc  A sanitized map document.
	 * @param string $svg  The result of svg() for the same document.
	 * @param array  $args layout, heading, intro, show_list.
	 */
	public static function section( array $doc, string $svg, array $args ): string {
		$layout    = isset( $args['layout'] ) && in_array( $args['layout'], self::LAYOUTS, true ) ? $args['layout'] : self::LAYOUTS[0];
		$show_list = 'map-only' !== $layout && ( ! isset( $args['show_list'] ) || $args['show_list'] );
		$heading   = isset( $args['heading'] ) ? trim( (string) $args['heading'] ) : '';
		$intro     = isset( $args['intro'] ) ? trim( (string) $args['intro'] ) : '';

		$variables = array();
		foreach ( self::VARIABLES as $key => $name ) {
			$variables[] = $name . ':' . $doc['style'][ $key ];
		}

		// Border and hover values are written only when they differ from the built-in look.
		$style = $doc['style'];
		if ( 1.6 !== (float) $style['zoneStrokeWidth'] ) {
			$variables[] = '--mappulse-doc-zone-stroke-width:' . self::num( $style['zoneStrokeWidth'] );
		}
		if ( 'solid' !== $style['zoneStrokeStyle'] ) {
			$variables[] = '--mappulse-doc-zone-dash:' . Map_Document::STROKE_STYLES[ $style['zoneStrokeStyle'] ];
		}
		$hover = 'outline' === $style['hoverEffect'] ? ' mappulse--hover-outline' : '';
		foreach ( Map_Document::CARD_COLORS as $key => $fallback ) {
			if ( strtoupper( (string) $style[ $key ] ) !== $fallback ) {
				$variables[] = '--mappulse-doc-' . strtolower( preg_replace( '/([A-Z])/', '-$1', $key ) ) . ':' . $style[ $key ];
			}
		}

		// Hover cards, unless this placement switches them off. Each gets an id that is unique on the page.
		$cards = array();
		if ( ! isset( $args['cards'] ) || $args['cards'] ) {
			$uid = 'mappulse-' . ( ++self::$instances );
			foreach ( $doc['zones'] as $zone ) {
				$card_id = $uid . '-card-' . $zone['id'];
				$card    = self::card( $zone, $card_id );
				if ( '' !== $card ) {
					$cards[ $zone['id'] ] = array( $card_id, $card );
				}
			}
		}
		// The drawing is stored without knowing where it will be placed, so each area is tied to its card here.
		foreach ( $cards as $zone_id => $card ) {
			$needle = ' data-zone="' . esc_attr( $zone_id ) . '" aria-label=';
			$at     = strpos( $svg, $needle );
			if ( false !== $at ) {
				$svg = substr_replace( $svg, ' data-zone="' . esc_attr( $zone_id ) . '" aria-describedby="' . esc_attr( $card[0] ) . '" aria-label=', $at, strlen( $needle ) );
			}
		}

		// The view script adds a Close button to a card shown as a sheet. Its label is given here so it can be translated.
		$close = $cards ? ' data-close-label="' . esc_attr__( 'Close', 'mappulse' ) . '"' : '';

		$html = '<section class="mappulse mappulse--' . esc_attr( $layout ) . $hover . '" data-mappulse' . $close . ' style="' . esc_attr( implode( ';', $variables ) ) . '">';
		if ( '' !== $heading ) {
			$html .= '<h2 class="mappulse__heading">' . esc_html( $heading ) . '</h2>';
		}
		if ( '' !== $intro ) {
			$html .= '<p class="mappulse__intro">' . esc_html( $intro ) . '</p>';
		}
		if ( $show_list && $doc['zones'] ) {
			$html .= '<ul class="mappulse__list">';
			foreach ( $doc['zones'] as $zone ) {
				if ( ! empty( $zone['hidden'] ) ) {
					continue;
				}
				$link      = self::link( $zone );
				$described = isset( $cards[ $zone['id'] ] ) ? ' aria-describedby="' . esc_attr( $cards[ $zone['id'] ][0] ) . '"' : '';
				$html     .= '' !== $link
					? '<li><a href="' . esc_url( $link ) . '" data-zone="' . esc_attr( $zone['id'] ) . '"' . $described . '>' . esc_html( $zone['name'] ) . '</a></li>'
					: '<li><span data-zone="' . esc_attr( $zone['id'] ) . '">' . esc_html( $zone['name'] ) . '</span></li>';
			}
			$html .= '</ul>';
		}
		$html .= '<div class="mappulse__map">' . $svg . '</div>';
		if ( $cards ) {
			$html .= '<div class="mappulse__cards">' . implode( '', array_column( $cards, 1 ) ) . '</div>';
		}
		$html .= '</section>';

		return $html;
	}

	/**
	 * A leader line from a zone's anchor point to the nearest edge of its moved label.
	 *
	 * @param array $label A sanitized label with an anchor.
	 */
	private static function leader( array $label ): string {
		$size   = (float) $label['size'];
		$chars  = max( array_map( 'mb_strlen', $label['lines'] ) );
		$half_w = ( $chars * $size * 0.8 + 6 ) / 2;
		$half_h = ( count( $label['lines'] ) * $size * 1.18 + 3 ) / 2;
		$ax     = $label['anchor']['x'];
		$ay     = $label['anchor']['y'];
		$ex     = min( max( $ax, $label['x'] - $half_w ), $label['x'] + $half_w );
		$ey     = min( max( $ay, $label['y'] - $half_h ), $label['y'] + $half_h );

		return '<line x1="' . self::num( $ax ) . '" y1="' . self::num( $ay ) . '" x2="' . self::num( $ex ) . '" y2="' . self::num( $ey ) . '"/>'
			. '<circle cx="' . self::num( $ax ) . '" cy="' . self::num( $ay ) . '" r="1.8"/>';
	}

	/**
	 * One named SVG group.
	 *
	 * @param string $name    Group name, used in its class.
	 * @param string $content Markup inside the group.
	 */
	private static function group( string $name, string $content ): string {
		return '<g class="mappulse__' . $name . '">' . $content . '</g>';
	}

	/**
	 * A filled shape from path data, or nothing when the layer is empty.
	 *
	 * @param string $d Path data.
	 */
	private static function shape( string $d ): string {
		return '' === $d ? '' : '<path d="' . esc_attr( $d ) . '" fill-rule="evenodd"/>';
	}

	/**
	 * A number as SVG wants it: at most two decimals, no trailing zeros.
	 *
	 * @param float|int $value Number.
	 */
	private static function num( $value ): string {
		$text = number_format( (float) $value, 2, '.', '' );
		return str_contains( $text, '.' ) ? rtrim( rtrim( $text, '0' ), '.' ) : $text;
	}
}
