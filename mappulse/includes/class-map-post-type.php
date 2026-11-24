<?php
/**
 * The map store: one private post per map, holding the document and its cached SVG.
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the mappulse_map post type and reads and writes maps.
 */
class Map_Post_Type {

	const POST_TYPE  = 'mappulse_map';
	const CAPABILITY = 'manage_mappulse_maps';
	const META_DOC   = '_mappulse_map';
	const META_SVG   = '_mappulse_svg';
	const META_HASH  = '_mappulse_hash';

	/**
	 * Documents already read and cleaned in this request, keyed by post ID and content hash.
	 *
	 * @var array
	 */
	private static $documents = array();

	/**
	 * Registers the post type. Maps are created by import (and later the builder), never by "Add New".
	 */
	public static function register(): void {
		$capabilities = array_fill_keys(
			array(
				'edit_post',
				'read_post',
				'delete_post',
				'edit_posts',
				'edit_others_posts',
				'publish_posts',
				'read_private_posts',
				'delete_posts',
				'delete_private_posts',
				'delete_published_posts',
				'delete_others_posts',
				'edit_private_posts',
				'edit_published_posts',
			),
			self::CAPABILITY
		);

		$capabilities['create_posts'] = 'do_not_allow';

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'        => array(
					'name'               => __( 'Maps', 'mappulse' ),
					'singular_name'      => __( 'Map', 'mappulse' ),
					'menu_name'          => __( 'MapPulse', 'mappulse' ),
					'all_items'          => __( 'All Maps', 'mappulse' ),
					'edit_item'          => __( 'Edit Map', 'mappulse' ),
					'search_items'       => __( 'Search Maps', 'mappulse' ),
					'not_found'          => __( 'No maps yet. Import a map file to get started.', 'mappulse' ),
					'not_found_in_trash' => __( 'No maps in the Trash.', 'mappulse' ),
				),
				'public'        => false,
				'show_ui'       => true,
				'show_in_menu'  => true,
				'show_in_rest'  => false,
				'menu_position' => 58,
				'menu_icon'     => 'dashicons-location-alt',
				'supports'      => array( 'title', 'revisions' ),
				'capabilities'  => $capabilities,
				'map_meta_cap'  => false,
				'rewrite'       => false,
				'query_var'     => false,
			)
		);
	}

	/**
	 * Validates a document and stores it with its rendered SVG.
	 *
	 * @param array  $doc     Raw or sanitized map document.
	 * @param int    $post_id Existing map to replace, or 0 to create one.
	 * @param string $status  publish or draft.
	 * @return int|\WP_Error The map's post ID.
	 */
	public static function save( array $doc, int $post_id = 0, string $status = 'publish' ) {
		$doc = Map_Document::sanitize( $doc );
		if ( is_wp_error( $doc ) ) {
			return $doc;
		}
		if ( $post_id > 0 && self::POST_TYPE !== get_post_type( $post_id ) ) {
			return new \WP_Error( 'mappulse_not_found', __( 'That map does not exist.', 'mappulse' ) );
		}

		$post = array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'draft' === $status ? 'draft' : 'publish',
		);
		if ( $post_id > 0 ) {
			$post['ID'] = $post_id;
		} else {
			$post['post_title'] = '' !== $doc['title'] ? $doc['title'] : __( 'Untitled map', 'mappulse' );
		}

		$post_id = $post_id > 0 ? wp_update_post( wp_slash( $post ), true ) : wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, self::META_DOC, wp_slash( wp_json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) );
		self::rebuild( $post_id );

		return (int) $post_id;
	}

	/**
	 * Reads a map's document.
	 *
	 * @param int $post_id Map post ID.
	 */
	public static function get( int $post_id ): ?array {
		if ( $post_id <= 0 || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return null;
		}
		$json = (string) get_post_meta( $post_id, self::META_DOC, true );
		$key  = $post_id . ':' . md5( $json );

		if ( ! array_key_exists( $key, self::$documents ) ) {
			// Stored data is cleaned again on the way out: post meta can be written by imports and other code.
			$raw = json_decode( $json, true );
			$doc = is_array( $raw ) ? Map_Document::sanitize( $raw ) : null;
			if ( count( self::$documents ) >= 20 ) {
				self::$documents = array();
			}
			self::$documents[ $key ] = is_array( $doc ) ? $doc : null;
		}

		return self::$documents[ $key ];
	}

	/**
	 * Published maps as ID => name, for the pickers in the block and the Elementor widget.
	 */
	public static function choices(): array {
		$posts = get_posts(
			array(
				'post_type'              => self::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 100,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				// Titles only: without these, every map's document and SVG would be loaded into memory.
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$choices = array();
		foreach ( $posts as $post ) {
			$choices[ $post->ID ] = '' !== $post->post_title ? $post->post_title : sprintf( '#%d', $post->ID );
		}
		return $choices;
	}

	/**
	 * Returns a map's SVG from the cache, rebuilding it when the document or the plugin version changed.
	 *
	 * @param int $post_id Map post ID.
	 */
	public static function svg( int $post_id ): string {
		$doc = self::get( $post_id );
		if ( null === $doc ) {
			return '';
		}
		$svg = (string) get_post_meta( $post_id, self::META_SVG, true );
		if ( '' !== $svg && hash_equals( self::signature( $doc, $svg ), (string) get_post_meta( $post_id, self::META_HASH, true ) ) ) {
			return $svg;
		}
		return self::rebuild( $post_id );
	}

	/**
	 * Renders a map's SVG and stores it with the hash of the document it came from.
	 *
	 * @param int $post_id Map post ID.
	 */
	private static function rebuild( int $post_id ): string {
		$doc = self::get( $post_id );
		if ( null === $doc ) {
			return '';
		}
		$svg = Renderer::svg( $doc );
		update_post_meta( $post_id, self::META_SVG, wp_slash( $svg ) );
		update_post_meta( $post_id, self::META_HASH, self::signature( $doc, $svg ) );
		return $svg;
	}

	/**
	 * Signs a cached SVG together with the document it was drawn from.
	 *
	 * Keyed with this site's salts, so a cache written by anything but this plugin on this site
	 * (an import file, a copied database) does not match and is rebuilt instead of printed.
	 *
	 * @param array  $doc A sanitized document.
	 * @param string $svg The SVG rendered from it.
	 */
	private static function signature( array $doc, string $svg ): string {
		return wp_hash( Map_Document::hash( $doc ) . '|' . md5( $svg ), 'nonce' );
	}
}
