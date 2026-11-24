<?php
/**
 * WP-CLI commands for boundary sources.
 *
 * @package MapPulse
 */

namespace MapPulse;

use MapPulse\Boundaries\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Lists, adds and removes boundary sources.
 */
class CLI_Sources {

	/**
	 * Lists every boundary source.
	 *
	 * @subcommand list
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function list_( $args, $assoc_args ): void {
		$rows = array();
		foreach ( Sources::all() as $provider ) {
			$rows[] = array(
				'id'    => $provider->id(),
				'label' => $provider->label(),
				'types' => implode( ', ', $provider->types() ),
			);
		}
		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'label', 'types' ) );
	}

	/**
	 * Registers an ArcGIS polygon layer, for example a city's neighborhoods.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : The https address of the layer, ending in MapServer or FeatureServer and the layer number.
	 *
	 * --label=<label>
	 * : Name for the source.
	 *
	 * [--parent=<parent>]
	 * : The place the layer covers, shown beside results.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function add( $args, $assoc_args ): void {
		$entry = Sources::add_layer( (string) $args[0], (string) ( $assoc_args['label'] ?? '' ), (string) ( $assoc_args['parent'] ?? '' ) );
		if ( is_wp_error( $entry ) ) {
			\WP_CLI::error( $entry->get_error_message() );
		}
		\WP_CLI::success( sprintf( 'Registered %1$s. Names are read from the field %2$s.', $entry['id'], $entry['name_field'] ) );
	}

	/**
	 * Removes a registered layer.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Source ID from "wp mappulse sources list".
	 *
	 * @param array $args Positional arguments.
	 */
	public function remove( $args ): void {
		if ( ! Sources::remove_layer( (string) $args[0] ) ) {
			\WP_CLI::error( 'No registered layer has that ID.' );
		}
		\WP_CLI::success( 'Removed.' );
	}
}
