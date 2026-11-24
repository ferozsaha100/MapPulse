<?php
/**
 * The one place MapPulse makes remote requests from.
 *
 * Only https, only hosts on the allowed list, with a timeout, a pause between requests to the
 * same host, a body size cap and two retries.
 *
 * @package MapPulse
 */

namespace MapPulse\Boundaries;

defined( 'ABSPATH' ) || exit;

/**
 * HTTP client for boundary sources.
 */
class Http {

	const TIMEOUT    = 20;
	const RETRIES    = 2;
	const MAX_BYTES  = 12582912;
	const SPACING_MS = 300;

	/**
	 * Hosts MapPulse may contact. Registered layers add theirs through the filter.
	 */
	public static function allowed_hosts(): array {
		$hosts = (array) apply_filters( 'mappulse_allowed_hosts', array( 'tigerweb.geo.census.gov' ) );
		return array_values( array_unique( array_map( 'strtolower', array_filter( $hosts, 'is_string' ) ) ) );
	}

	/**
	 * Requests a URL and returns its decoded JSON.
	 *
	 * @param string $url   An https URL on an allowed host.
	 * @param array  $query Query values, URL-encoded here.
	 * @return array|\WP_Error
	 */
	public static function get_json( string $url, array $query = array() ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'https' !== $scheme || '' === $host || ! in_array( $host, self::allowed_hosts(), true ) ) {
			return new \WP_Error( 'mappulse_host_not_allowed', __( 'MapPulse is not allowed to contact that address.', 'mappulse' ) );
		}

		if ( $query ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $query );
		}

		$max   = (int) apply_filters( 'mappulse_max_response_bytes', self::MAX_BYTES );
		$error = null;

		for ( $attempt = 0; $attempt <= self::RETRIES; $attempt++ ) {
			self::wait_for( $host );

			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => self::TIMEOUT,
					'user-agent'          => 'MapPulse/' . MAPPULSE_VERSION . '; ' . home_url(),
					'limit_response_size' => $max + 1,
					'headers'             => array( 'Accept' => 'application/json' ),
				)
			);

			if ( is_wp_error( $response ) ) {
				/* translators: 1: host name, 2: error message. */
				$error = new \WP_Error( 'mappulse_http', sprintf( __( '%1$s could not be reached: %2$s', 'mappulse' ), $host, $response->get_error_message() ) );
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				/* translators: 1: host name, 2: HTTP status code. */
				$error = new \WP_Error( 'mappulse_http', sprintf( __( '%1$s answered with HTTP %2$d.', 'mappulse' ), $host, $code ) );
				if ( $code >= 500 ) {
					continue;
				}
				return $error;
			}

			$body = (string) wp_remote_retrieve_body( $response );
			if ( strlen( $body ) > $max ) {
				return new \WP_Error( 'mappulse_too_large', __( 'The boundary source sent more data than MapPulse accepts. Try a smaller area.', 'mappulse' ) );
			}

			$data = json_decode( $body, true );
			if ( ! is_array( $data ) ) {
				/* translators: %s: host name. */
				return new \WP_Error( 'mappulse_bad_response', sprintf( __( '%s sent an answer MapPulse could not read.', 'mappulse' ), $host ) );
			}
			if ( isset( $data['error'] ) ) {
				$detail = is_array( $data['error'] ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : '';
				/* translators: 1: host name, 2: error message from the source. */
				return new \WP_Error( 'mappulse_source_error', sprintf( __( '%1$s reported an error: %2$s', 'mappulse' ), $host, sanitize_text_field( $detail ) ) );
			}

			return $data;
		}

		return $error;
	}

	/**
	 * Keeps a minimum pause between two requests to one host.
	 *
	 * @param string $host Host name.
	 */
	private static function wait_for( string $host ): void {
		$spacing = (int) apply_filters( 'mappulse_request_spacing_ms', self::SPACING_MS );
		$key     = 'mappulse_last_' . md5( $host );

		if ( $spacing > 0 ) {
			$last    = (float) get_transient( $key );
			$elapsed = ( microtime( true ) - $last ) * 1000;
			if ( $last > 0 && $elapsed >= 0 && $elapsed < $spacing ) {
				usleep( (int) ( ( $spacing - $elapsed ) * 1000 ) );
			}
		}

		set_transient( $key, microtime( true ), MINUTE_IN_SECONDS );
	}
}
