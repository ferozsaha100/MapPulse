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
}
