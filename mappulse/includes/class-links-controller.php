<?php
/**
 * REST routes that find pages and posts for area links.
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Link routes under mappulse/v1. Both need the manage_mappulse_maps capability and return only
 * published posts of public types.
 */
class Links_Controller {

	/**
	 * Adds the hook.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public static function routes(): void {
		$can = array( Rest_Controller::class, 'can' );

		register_rest_route(
			Rest_Controller::ROUTE_NAMESPACE,
			'/links/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'search' ),
				'permission_callback' => $can,
				'args'                => array(
					'q' => array(
						'required'          => true,
						'type'              => 'string',
						'minLength'         => 2,
						'maxLength'         => 60,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			Rest_Controller::ROUTE_NAMESPACE,
			'/links/match',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'match' ),
				'permission_callback' => $can,
				'args'                => array(
					'names' => array(
						'required' => true,
						'type'     => 'array',
						'maxItems' => MAPPULSE_MAX_ZONES,
					),
				),
			)
		);
	}

	/**
	 * Post types a map may link to: public ones with pages of their own, without attachments.
	 *
	 * @return string[]
	 */
	private static function types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( array_filter( $types, 'is_post_type_viewable' ) );
	}

	/**
	 * A plain form of a title for comparing: lower case, with punctuation and hyphens read as spaces.
	 *
	 * @param string $text Title or area name.
	 */
	public static function plain( string $text ): string {
		$text = remove_accents( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
		return trim( preg_replace( '/\s+/', ' ', preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $text ) ) ) );
	}

	/**
	 * One post as a link choice.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function choice( \WP_Post $post ): array {
		$type = get_post_type_object( $post->post_type );
		return array(
			'id'    => $post->ID,
			'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'url'   => (string) get_permalink( $post ),
			'type'  => $type ? $type->labels->singular_name : $post->post_type,
		);
	}

	/**
	 * Published posts whose title contains the text. Only titles are searched, never content.
	 *
	 * @param string $text  Text to find.
	 * @param int    $limit Most posts to return.
	 * @param array  $types Post types to look in. All linkable types when empty.
	 * @return \WP_Post[]
	 */
	private static function titled( string $text, int $limit, array $types = array() ): array {
		$only_titles = static function () {
			return array( 'post_title' );
		};
		add_filter( 'post_search_columns', $only_titles );
		try {
			$posts = get_posts(
				array(
					'post_type'              => $types ? $types : self::types(),
					'post_status'            => 'publish',
					's'                      => $text,
					'posts_per_page'         => $limit,
					'orderby'                => 'title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'suppress_filters'       => false,
				)
			);
		} finally {
			remove_filter( 'post_search_columns', $only_titles );
		}
		return $posts;
	}

	/**
	 * Posts whose title contains the text: pages first, each kind looked up on its own, so a site with
	 * many posts on one subject cannot crowd its pages out.
	 *
	 * @param string $text Text to find.
	 * @return \WP_Post[]
	 */
	private static function candidates( string $text ): array {
		$types = self::types();
		$pages = in_array( 'page', $types, true ) ? self::titled( $text, 200, array( 'page' ) ) : array();
		$other = array_values( array_diff( $types, array( 'page' ) ) );
		return array_merge( $pages, $other ? self::titled( $text, 200, $other ) : array() );
	}

	/**
	 * Orders posts for a wanted title: an exact title first, then pages, then by title.
	 *
	 * @param \WP_Post[] $posts  Posts.
	 * @param string     $wanted Plain form of the text looked for.
	 * @return \WP_Post[]
	 */
	private static function ranked( array $posts, string $wanted ): array {
		usort(
			$posts,
			static function ( $a, $b ) use ( $wanted ) {
				$exact = ( self::plain( $b->post_title ) === $wanted ) <=> ( self::plain( $a->post_title ) === $wanted );
				if ( 0 !== $exact ) {
					return $exact;
				}
				$pages = ( 'page' === $b->post_type ) <=> ( 'page' === $a->post_type );
				return 0 !== $pages ? $pages : ( strcasecmp( $a->post_title, $b->post_title ) ? strcasecmp( $a->post_title, $b->post_title ) : $a->ID <=> $b->ID );
			}
		);
		return $posts;
	}

	/**
	 * GET /links/search.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function search( \WP_REST_Request $request ) {
		$text = (string) $request['q'];
		if ( mb_strlen( $text ) < 2 || mb_strlen( $text ) > 60 ) {
			return new \WP_Error( 'mappulse_bad_query', __( 'Type between 2 and 60 characters.', 'mappulse' ), array( 'status' => 400 ) );
		}
		// Rank a wide set, then cut: the best match must not be lost to an alphabetical cut-off.
		$posts = array_slice( self::ranked( self::candidates( $text ), self::plain( $text ) ), 0, 20 );
		return rest_ensure_response( array_map( array( self::class, 'choice' ), $posts ) );
	}

	/**
	 * POST /links/match. For each name, the published posts titled exactly that, ignoring case and punctuation.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function match( \WP_REST_Request $request ) {
		$names = $request['names'];
		if ( ! is_array( $names ) || count( $names ) > MAPPULSE_MAX_ZONES ) {
			return new \WP_Error( 'mappulse_bad_names', __( 'Send a list of area names, no longer than a map may hold.', 'mappulse' ), array( 'status' => 400 ) );
		}

		$result = array();
		foreach ( $names as $raw ) {
			$name = is_string( $raw ) ? sanitize_text_field( $raw ) : '';
			if ( '' === $name || isset( $result[ $name ] ) ) {
				continue;
			}
			$wanted = self::plain( $name );
			$found  = array();
			if ( mb_strlen( $wanted ) >= 2 ) {
				// The title search ignores punctuation differently than plain() does, so look for the longest word and compare here.
				$words = explode( ' ', $wanted );
				usort(
					$words,
					static function ( $a, $b ) {
						return strlen( $b ) <=> strlen( $a );
					}
				);
				foreach ( self::candidates( $words[0] ) as $post ) {
					if ( self::plain( $post->post_title ) === $wanted ) {
						$found[] = $post;
					}
				}
			}
			$result[ $name ] = array_map( array( self::class, 'choice' ), array_slice( self::ranked( $found, $wanted ), 0, 5 ) );
		}
		return rest_ensure_response( $result );
	}
}
