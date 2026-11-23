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
		$doc = json_decode( (string) get_post_meta( $post_id, self::META_DOC, true ), true );
		return is_array( $doc ) && isset( $doc['topology'], $doc['zones'], $doc['frame'], $doc['style'] ) ? $doc : null;
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
		if ( '' !== $svg && Map_Document::hash( $doc ) === get_post_meta( $post_id, self::META_HASH, true ) ) {
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
		update_post_meta( $post_id, self::META_HASH, Map_Document::hash( $doc ) );
		return $svg;
	}
}
