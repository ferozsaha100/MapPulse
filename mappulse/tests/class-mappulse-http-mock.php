<?php
/**
 * Answers every outgoing HTTP request from a list of URL fragments, and records the URLs asked for.
 * A request that matches nothing fails, so no test can reach the network.
 */
class MapPulse_Http_Mock {

	/** @var string[] URLs requested, in order. */
	public $requests = array();

	/** @var array[] Arguments of each request, in order. */
	public $args = array();

	/** @var array[] Each: fragment, body, status. First match wins. */
	private $routes = array();

	/** @var array[] Routes kept across reset(). */
	private $defaults = array();

	public function __construct() {
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
		add_filter( 'mappulse_request_spacing_ms', '__return_zero' );
	}

	/** Answer URLs containing $fragment with a body (string, or array to be JSON-encoded). */
	public function on( string $fragment, $body, int $status = 200 ): self {
		$this->routes[] = array( $fragment, is_string( $body ) ? $body : wp_json_encode( $body ), $status );
		return $this;
	}

	/** Answer URLs containing $fragment with a file from tests/fixtures/http/. */
	public function fixture( string $fragment, string $file ): self {
		return $this->on( $fragment, file_get_contents( __DIR__ . '/fixtures/http/' . $file ) );
	}

	/** Answer URLs containing $fragment with a fixture file, even after reset(), unless a route matches first. */
	public function fallback( string $fragment, string $file ): self {
		$this->defaults[] = array( $fragment, file_get_contents( __DIR__ . '/fixtures/http/' . $file ), 200 );
		return $this;
	}

	/** Forget every route, keep the request log. */
	public function reset(): self {
		$this->routes = array();
		return $this;
	}

	public function answer( $pre, $args, $url ) {
		$this->requests[] = $url;
		$this->args[]     = $args;
		foreach ( array_merge( $this->routes, $this->defaults ) as $route ) {
			if ( false !== strpos( $url, $route[0] ) ) {
				return array(
					'headers'  => array(),
					'body'     => $route[1],
					'response' => array(
						'code'    => $route[2],
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		}
		return new WP_Error( 'http_request_failed', 'No mock answer for ' . $url );
	}

	/** Requests whose URL contains $fragment. */
	public function count( string $fragment = '' ): int {
		return count(
			array_filter(
				$this->requests,
				static function ( $url ) use ( $fragment ) {
					return '' === $fragment || false !== strpos( $url, $fragment );
				}
			)
		);
	}
}
