<?php
/**
 * A registered ArcGIS polygon layer, the way most cities publish their neighborhoods.
 *
 * @package MapPulse
 */

namespace MapPulse\Boundaries;

defined( 'ABSPATH' ) || exit;

/**
 * Boundary source backed by one registered layer.
 */
class Layer_Provider implements Provider {

	/**
	 * The stored entry: id, url, label, parent, name_field, id_field.
	 *
	 * @var array
	 */
	private $entry;

	/**
	 * Builds a provider from a stored entry.
	 *
	 * @param array $entry Entry from Sources::layers().
	 */
	public function __construct( array $entry ) {
		$this->entry = $entry;
	}

	/**
	 * Source ID.
	 */
	public function id(): string {
		return $this->entry['id'];
	}

	/**
	 * Source name.
	 */
	public function label(): string {
		return $this->entry['label'];
	}

	/**
	 * Area types offered.
	 */
	public function types(): array {
		return array( 'neighborhood' );
	}

	/**
	 * The layer's address.
	 */
	public function url(): string {
		return $this->entry['url'];
	}

	/**
	 * Start of this layer's cache keys. It holds the address, so a different layer registered later
	 * under the same name never reads this one's saved answers.
	 */
	private function cache_prefix(): string {
		return $this->id() . '|' . md5( strtolower( $this->entry['url'] ) );
	}

	/**
	 * Finds areas in the layer whose name contains the text.
	 *
	 * @param string $query Text typed by a person.
	 * @param array  $args  type, limit.
	 * @return array|\WP_Error
	 */
	public function search( string $query, array $args ) {
		$text = Arcgis::like( $query );
		if ( mb_strlen( str_replace( '%', '', $text ) ) < 2 ) {
			return new \WP_Error( 'mappulse_bad_query', __( 'Type at least two letters or digits.', 'mappulse' ) );
		}
		if ( ! empty( $args['type'] ) && 'neighborhood' !== $args['type'] ) {
			return array();
		}
		$limit = isset( $args['limit'] ) ? max( 1, min( 50, (int) $args['limit'] ) ) : 20;

		$key    = $this->cache_prefix() . '|search|' . $limit . '|' . $text;
		$cached = Cache::get( $key );
		if ( null !== $cached ) {
			return $cached;
		}

		$name = $this->entry['name_field'];
		$id   = $this->entry['id_field'];
		$data = Arcgis::query(
			$this->entry['url'],
			array(
				'where'             => 'UPPER(' . $name . ") LIKE '%" . $text . "%'",
				'outFields'         => $name . ',' . $id,
				'returnGeometry'    => 'false',
				'orderByFields'     => $name,
				'resultRecordCount' => $limit,
			),
			$limit,
			true
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$results = array();
		foreach ( $data['features'] as $feature ) {
			$properties = isset( $feature['properties'] ) && is_array( $feature['properties'] ) ? $feature['properties'] : array();
			if ( ! isset( $properties[ $id ] ) || ! is_numeric( $properties[ $id ] ) ) {
				continue;
			}
			$results[] = array(
				'source' => $this->id(),
				'record' => (string) (int) $properties[ $id ],
				'name'   => sanitize_text_field( (string) ( $properties[ $name ] ?? '' ) ),
				'type'   => 'neighborhood',
				'parent' => $this->entry['parent'],
				'center' => null,
			);
		}

		Cache::set( $key, $results, DAY_IN_SECONDS );
		return $results;
	}

	/**
	 * Returns one area's shape.
	 *
	 * @param string $record The layer's object ID, digits only.
	 * @return array|\WP_Error
	 */
	public function fetch( string $record ) {
		if ( ! preg_match( '/^\d{1,10}$/', $record ) ) {
			return new \WP_Error( 'mappulse_bad_record', __( 'That is not a valid record for this layer.', 'mappulse' ) );
		}

		$key    = $this->cache_prefix() . '|shape|' . $record;
		$cached = Cache::get( $key );
		if ( null !== $cached ) {
			return $cached;
		}

		$name = $this->entry['name_field'];
		$data = Arcgis::query(
			$this->entry['url'],
			array(
				'where'     => $this->entry['id_field'] . '=' . $record,
				'outFields' => $name . ',' . $this->entry['id_field'],
			),
			5
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['features'][0]['geometry'] ) ) {
			return new \WP_Error( 'mappulse_not_found', __( 'The layer has no shape for that record.', 'mappulse' ) );
		}

		$feature = array(
			'type'       => 'Feature',
			'properties' => array(
				'source' => $this->id(),
				'record' => $record,
				'name'   => sanitize_text_field( (string) ( $data['features'][0]['properties'][ $name ] ?? '' ) ),
				'type'   => 'neighborhood',
			),
			'geometry'   => $data['features'][0]['geometry'],
		);

		Cache::set( $key, $feature );
		return $feature;
	}
}
