<?php
/**
 * REST routes under mappulse/v1. Every route needs the manage_mappulse_maps capability.
 *
 * @package MapPulse
 */

namespace MapPulse;

use MapPulse\Boundaries\Service;
use MapPulse\Boundaries\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Boundary and source routes for the admin and the builder.
 */
class Rest_Controller {

	const ROUTE_NAMESPACE = 'mappulse/v1';

	/**
	 * Errors that mean a boundary source failed, as opposed to a bad request.
	 */
	const UPSTREAM_ERRORS = array( 'mappulse_http', 'mappulse_source_error', 'mappulse_bad_response' );

	/**
	 * Errors that mean the thing asked for does not exist.
	 */
	const MISSING_ERRORS = array( 'mappulse_unknown_source', 'mappulse_not_found' );

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
		$can  = array( self::class, 'can' );
		$text = array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/sources',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'list_sources' ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'add_source' ),
					'permission_callback' => $can,
					'args'                => array(
						'url'    => array(
							'type'     => 'string',
							'required' => true,
						),
						'label'  => $text,
						'parent' => $text,
					),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/sources/(?P<id>[a-z0-9:-]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( self::class, 'remove_source' ),
				'permission_callback' => $can,
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/boundaries/search',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'search' ),
				'permission_callback' => $can,
				'args'                => array(
					'q'     => $text + array( 'required' => true ),
					'type'  => array(
						'type' => 'string',
						'enum' => array( '', 'city', 'zip', 'county', 'neighborhood' ),
					),
					'state' => $text,
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/boundaries/fetch',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'fetch' ),
				'permission_callback' => $can,
				'args'                => array(
					'source' => $text + array( 'required' => true ),
					'record' => $text + array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/boundaries/base',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'base' ),
				'permission_callback' => $can,
				'args'                => array(
					'bbox'  => $text + array( 'required' => true ),
					'layer' => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), Service::BASE_LAYERS ),
					),
				),
			)
		);
	}

	/**
	 * Permission check shared by every route.
	 */
	public static function can(): bool {
		return current_user_can( Map_Post_Type::CAPABILITY );
	}

	/**
	 * GET /sources.
	 */
	public static function list_sources() {
		$sources = array();
		foreach ( Sources::all() as $provider ) {
			$sources[] = array(
				'id'    => $provider->id(),
				'label' => $provider->label(),
				'types' => $provider->types(),
			);
		}
		return rest_ensure_response( $sources );
	}

	/**
	 * POST /sources.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function add_source( \WP_REST_Request $request ) {
		$entry = Sources::add_layer( (string) $request['url'], (string) $request['label'], (string) $request['parent'] );
		if ( is_wp_error( $entry ) ) {
			return self::answer( $entry );
		}
		$response = rest_ensure_response( $entry );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * DELETE /sources/<id>.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function remove_source( \WP_REST_Request $request ) {
		if ( ! Sources::remove_layer( (string) $request['id'] ) ) {
			return self::answer( new \WP_Error( 'mappulse_unknown_source', __( 'That boundary source is not registered on this site.', 'mappulse' ) ) );
		}
		return rest_ensure_response( array( 'removed' => true ) );
	}

	/**
	 * GET /boundaries/search.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function search( \WP_REST_Request $request ) {
		return rest_ensure_response(
			Service::search(
				(string) $request['q'],
				array(
					'type'  => (string) $request['type'],
					'state' => (string) $request['state'],
				)
			)
		);
	}

	/**
	 * GET /boundaries/fetch.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function fetch( \WP_REST_Request $request ) {
		return self::answer( Service::fetch( (string) $request['source'], (string) $request['record'] ) );
	}

	/**
	 * GET /boundaries/base.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function base( \WP_REST_Request $request ) {
		$bbox = Service::parse_bbox( (string) $request['bbox'] );
		if ( is_wp_error( $bbox ) ) {
			return self::answer( $bbox );
		}

		// The builder asks for one layer at a time, which keeps each request small.
		$layer = (string) $request['layer'];
		if ( '' === $layer ) {
			return self::answer( Service::base( $bbox ) );
		}
		$data = Service::base_layer( $bbox, $layer );
		if ( is_wp_error( $data ) ) {
			return self::answer( $data );
		}
		$errors = isset( $data['errors'] ) ? $data['errors'] : array();
		unset( $data['errors'] );
		return self::answer(
			array(
				$layer   => $data,
				'errors' => $errors,
			)
		);
	}

	/**
	 * Turns a service result into a response: 502 when a source failed, 404 when missing, 400 otherwise.
	 *
	 * @param array|\WP_Error $result Service result.
	 */
	private static function answer( $result ) {
		if ( ! is_wp_error( $result ) ) {
			return rest_ensure_response( $result );
		}
		$status = 400;
		if ( in_array( $result->get_error_code(), self::UPSTREAM_ERRORS, true ) ) {
			$status = 502;
		} elseif ( in_array( $result->get_error_code(), self::MISSING_ERRORS, true ) ) {
			$status = 404;
		}
		$result->add_data( array( 'status' => $status ) );
		return $result;
	}
}
