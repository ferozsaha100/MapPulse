<?php
/**
 * What every boundary source offers.
 *
 * @package MapPulse
 */

namespace MapPulse\Boundaries;

defined( 'ABSPATH' ) || exit;

/**
 * A boundary source: something that can find areas by name and return their shapes.
 */
interface Provider {

	/**
	 * Stable ID, for example "census" or "layer:st-petersburg-neighborhoods".
	 */
	public function id(): string;

	/**
	 * Name shown to people.
	 */
	public function label(): string;

	/**
	 * Area types this source offers: any of city, zip, county, neighborhood.
	 */
	public function types(): array;

	/**
	 * Finds areas by name.
	 *
	 * @param string $query Text typed by a person.
	 * @param array  $args  type (an area type or ''), state (postal code or ''), limit.
	 * @return array|\WP_Error List of results: source, record, name, type, parent, center.
	 */
	public function search( string $query, array $args );

	/**
	 * Returns one area's shape.
	 *
	 * @param string $record Record ID from a search result.
	 * @return array|\WP_Error A GeoJSON Feature in WGS 84 with source, record, name and type in its properties.
	 */
	public function fetch( string $record );
}
