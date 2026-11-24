<?php
/**
 * REST routes the builder uses to list, read, save, delete and preview maps.
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Maps routes under mappulse/v1. Every route needs the manage_mappulse_maps capability.
 */
class Maps_Controller {

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
		$can  = array( Rest_Controller::class, 'can' );
		$body = array(
			'document' => array(
				'required' => true,
				'type'     => 'object',
			),
			'status'   => array(
				'type'    => 'string',
				'enum'    => array( 'draft', 'publish' ),
				'default' => 'publish',
			),
		);
		$id   = array(
			'id' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);

		register_rest_route(
			Rest_Controller::ROUTE_NAMESPACE,
			'/maps',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'index' ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'save' ),
					'permission_callback' => $can,
					'args'                => $body,
				),
			)
		);
		register_rest_route(
			Rest_Controller::ROUTE_NAMESPACE,
			'/maps/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'read' ),
					'permission_callback' => $can,
					'args'                => $id,
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'save' ),
					'permission_callback' => $can,
					'args'                => $id + $body,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( self::class, 'delete' ),
					'permission_callback' => $can,
					'args'                => $id,
				),
			)
		);
		register_rest_route(
			Rest_Controller::ROUTE_NAMESPACE,
			'/render',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'render' ),
				'permission_callback' => $can,
				'args'                => array( 'document' => $body['document'] ),
			)
		);
	}

	/**
	 * Lists maps, newest change first, without their documents.
	 */
	public static function index() {
		$posts = get_posts(
			array(
				'post_type'      => Map_Post_Type::POST_TYPE,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 200,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$list = array();
		foreach ( $posts as $post ) {
			$doc    = Map_Post_Type::get( $post->ID );
			$list[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'status'   => $post->post_status,
				'zones'    => $doc ? count( $doc['zones'] ) : 0,
				'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
			);
		}
		return rest_ensure_response( $list );
	}

	/**
	 * Reads one map.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function read( \WP_REST_Request $request ) {
		$map = self::map( (int) $request['id'] );
		return is_wp_error( $map ) ? $map : rest_ensure_response( $map );
	}

	/**
	 * Creates a map, or replaces one when the route names it.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function save( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id > 0 ) {
			$found = self::find( $id );
			if ( is_wp_error( $found ) ) {
				return $found;
			}
		}

		$saved = Map_Post_Type::save( (array) $request['document'], $id, (string) $request['status'] );
		if ( is_wp_error( $saved ) ) {
			return self::refused( $saved );
		}

		$response = rest_ensure_response( self::map( $saved ) );
		$response->set_status( $id > 0 ? 200 : 201 );
		return $response;
	}

	/**
	 * Moves a map to the trash.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function delete( \WP_REST_Request $request ) {
		$found = self::find( (int) $request['id'] );
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( ! wp_trash_post( $found->ID ) ) {
			return new \WP_Error( 'mappulse_not_deleted', __( 'The map could not be moved to the trash.', 'mappulse' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Draws a document without storing it, for the builder preview.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function render( \WP_REST_Request $request ) {
		$doc = Map_Document::sanitize( (array) $request['document'] );
		if ( is_wp_error( $doc ) ) {
			return self::refused( $doc );
		}
		return rest_ensure_response( array( 'svg' => Renderer::svg( $doc ) ) );
	}

	/**
	 * Finds a map post that is not in the trash.
	 *
	 * @param int $id Post ID.
	 * @return \WP_Post|\WP_Error
	 */
	private static function find( int $id ) {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || Map_Post_Type::POST_TYPE !== $post->post_type || ! in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
			return new \WP_Error( 'mappulse_not_found', __( 'That map does not exist.', 'mappulse' ), array( 'status' => 404 ) );
		}
		return $post;
	}

	/**
	 * One map as the builder reads it.
	 *
	 * @param int $id Post ID.
	 * @return array|\WP_Error
	 */
	private static function map( int $id ) {
		$post = self::find( $id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$doc = Map_Post_Type::get( $id );
		if ( null === $doc ) {
			return new \WP_Error( 'mappulse_unreadable', __( 'This map\'s stored data cannot be read.', 'mappulse' ), array( 'status' => 500 ) );
		}
		return array(
			'id'        => $id,
			'title'     => get_the_title( $post ),
			'status'    => $post->post_status,
			'document'  => $doc,
			'shortcode' => '[mappulse id="' . $id . '"]',
		);
	}

	/**
	 * Marks a document error as a bad request.
	 *
	 * @param \WP_Error $error Error from the document sanitizer or the map store.
	 */
	private static function refused( \WP_Error $error ): \WP_Error {
		$error->add_data( array( 'status' => 'mappulse_not_found' === $error->get_error_code() ? 404 : 400 ) );
		return $error;
	}
}
