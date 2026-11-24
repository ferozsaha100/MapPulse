<?php
/**
 * WP-CLI commands.
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Manage MapPulse maps from the command line.
 */
class CLI {

	/**
	 * Imports a map file.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Path to a MapPulse map file (.map.json).
	 *
	 * [--sample]
	 * : Import the bundled sample map instead of a file.
	 *
	 * [--title=<title>]
	 * : Name for the new map. Defaults to the title inside the file.
	 *
	 * [--porcelain]
	 * : Print only the new map ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mappulse import --sample
	 *     wp mappulse import ./downtown.map.json --title="Downtown areas" --porcelain
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function import( $args, $assoc_args ): void {
		$sample = ! empty( $assoc_args['sample'] );
		if ( ! $sample && empty( $args[0] ) ) {
			\WP_CLI::error( 'Give a map file path, or use --sample.' );
		}

		$title  = isset( $assoc_args['title'] ) ? (string) $assoc_args['title'] : '';
		$result = Importer::from_file( $sample ? Importer::sample_path() : (string) $args[0], $title );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		if ( ! empty( $assoc_args['porcelain'] ) ) {
			\WP_CLI::line( (string) $result );
			return;
		}
		\WP_CLI::success( sprintf( 'Imported map %1$d. Shortcode: [mappulse id="%1$d"]', $result ) );
	}

	/**
	 * Finds real boundaries by name: cities, ZIP areas, counties and registered neighborhoods.
	 *
	 * ## OPTIONS
	 *
	 * <text>
	 * : A place name or a ZIP code.
	 *
	 * [--type=<type>]
	 * : Only this kind of area: city, zip, county or neighborhood.
	 *
	 * [--state=<state>]
	 * : Two-letter state code, to narrow city and county results.
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mappulse find gulfport --state=FL
	 *     wp mappulse find 33701
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function find( $args, $assoc_args ): void {
		$found = Boundaries\Service::search(
			(string) $args[0],
			array(
				'type'  => isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : '',
				'state' => isset( $assoc_args['state'] ) ? (string) $assoc_args['state'] : '',
			)
		);
		foreach ( $found['errors'] as $source => $message ) {
			\WP_CLI::warning( $source . ': ' . $message );
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $found['results'], array( 'name', 'type', 'parent', 'source', 'record' ) );
	}

	/**
	 * Fetches one boundary and reports what it holds.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Source ID from "wp mappulse find", for example census.
	 *
	 * <record>
	 * : Record ID from "wp mappulse find", for example place:1228175.
	 *
	 * @param array $args Positional arguments.
	 */
	public function shape( $args ): void {
		$feature = Boundaries\Service::fetch( (string) $args[0], (string) ( $args[1] ?? '' ) );
		if ( is_wp_error( $feature ) ) {
			\WP_CLI::error( $feature->get_error_message() );
		}
		$facts = Boundaries\Service::describe( $feature );
		\WP_CLI::line( 'name: ' . $feature['properties']['name'] );
		\WP_CLI::line( 'type: ' . $feature['properties']['type'] );
		\WP_CLI::line( 'rings: ' . $facts['rings'] );
		\WP_CLI::line( 'points: ' . $facts['points'] );
		\WP_CLI::line( 'bbox: ' . ( $facts['bbox'] ? implode( ',', $facts['bbox'] ) : 'none' ) );
	}

	/**
	 * Manages the boundary cache.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : flush removes every cached boundary answer.
	 *
	 * @param array $args Positional arguments.
	 */
	public function cache( $args ): void {
		if ( 'flush' !== ( $args[0] ?? '' ) ) {
			\WP_CLI::error( 'Unknown action. Use: wp mappulse cache flush' );
		}
		\WP_CLI::success( sprintf( 'Removed %d cached answers.', Boundaries\Cache::flush() ) );
	}
}
