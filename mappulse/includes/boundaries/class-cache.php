<?php
/**
 * File cache for boundary data.
 *
 * Boundary answers are large and change rarely, so they are kept as JSON files in the uploads
 * folder instead of the options table. Everything here is public data.
 *
 * @package MapPulse
 */

namespace MapPulse\Boundaries;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and reads cached boundary answers.
 */
class Cache {

	/**
	 * The cache folder.
	 */
	public static function dir(): string {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( (string) apply_filters( 'mappulse_cache_dir', trailingslashit( $uploads['basedir'] ) . 'mappulse/cache' ) );
	}

	/**
	 * Reads an entry. Expired, missing and unreadable entries all return null.
	 *
	 * @param string $key Cache key.
	 */
	public static function get( string $key ): ?array {
		$path = self::path( $key );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache file.
		$entry = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $entry ) || ! isset( $entry['expires'], $entry['value'] ) || ! is_array( $entry['value'] ) || (int) $entry['expires'] < time() ) {
			wp_delete_file( $path );
			return null;
		}

		return $entry['value'];
	}

	/**
	 * Writes an entry. Returns false, without raising anything, when the folder cannot be written.
	 *
	 * @param string $key   Cache key.
	 * @param array  $value Data to keep.
	 * @param int    $ttl   Lifetime in seconds.
	 */
	public static function set( string $key, array $value, int $ttl = 2592000 ): bool {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			return false;
		}
		if ( ! is_file( $dir . '/index.html' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small local file.
			file_put_contents( $dir . '/index.html', '' );
		}

		$entry = wp_json_encode(
			array(
				'expires' => time() + $ttl,
				'value'   => $value,
			)
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local cache file.
		return false !== $entry && false !== file_put_contents( self::path( $key ), $entry, LOCK_EX );
	}

	/**
	 * Deletes every entry and returns how many were removed.
	 */
	public static function flush(): int {
		$count = 0;
		foreach ( (array) glob( self::dir() . '/*.json' ) as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * File path of an entry.
	 *
	 * @param string $key Cache key.
	 */
	private static function path( string $key ): string {
		return self::dir() . '/' . sha1( $key ) . '.json';
	}
}
