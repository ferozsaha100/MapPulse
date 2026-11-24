<?php
/**
 * The Sources screen: see and add boundary sources, find an area and check its real boundary.
 *
 * @package MapPulse
 */

namespace MapPulse;

use MapPulse\Boundaries\Cache;
use MapPulse\Boundaries\Census_Provider;
use MapPulse\Boundaries\Layer_Provider;
use MapPulse\Boundaries\Service;
use MapPulse\Boundaries\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen under MapPulse, Sources.
 */
class Sources_Screen {

	const PAGE  = 'mappulse-sources';
	const NONCE = 'mappulse_sources';

	/**
	 * A layer address shown as an example in the add form.
	 */
	const EXAMPLE_LAYER = 'https://egis.stpete.org/arcgis/rest/services/ServicesDOTS/NeighborhoodRelations/MapServer/22';

	/**
	 * Adds the admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_mappulse_add_layer', array( $this, 'handle_add' ) );
		add_action( 'admin_post_mappulse_remove_layer', array( $this, 'handle_remove' ) );
		add_action( 'admin_post_mappulse_flush_cache', array( $this, 'handle_flush' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Admin URL of this screen.
	 *
	 * @param array $args Extra query arguments.
	 */
	public static function page_url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . Map_Post_Type::POST_TYPE . '&page=' . self::PAGE ) );
	}

	/**
	 * Adds "Sources" under the MapPulse menu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Map_Post_Type::POST_TYPE,
			__( 'Boundary sources', 'mappulse' ),
			__( 'Sources', 'mappulse' ),
			Map_Post_Type::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the screen's stylesheet on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ): void {
		if ( is_string( $hook ) && str_ends_with( $hook, '_page_' . self::PAGE ) ) {
			wp_enqueue_style( 'mappulse-admin', MAPPULSE_URL . 'assets/css/mappulse-admin.css', array(), MAPPULSE_VERSION );
		}
	}

	/**
	 * Draws a shape as a small outline, so a person can see whether it is the area they meant.
	 *
	 * @param array $feature A GeoJSON Feature with a Polygon or MultiPolygon geometry.
	 * @param int   $size    Width and height of the drawing.
	 */
	public static function outline_svg( array $feature, int $size = 420 ): string {
		$box = Service::describe( $feature )['bbox'];
		if ( null === $box ) {
			return '';
		}

		// Degrees of longitude shrink toward the poles. Scale them by the cosine of the middle latitude.
		$squeeze = max( 0.01, cos( deg2rad( ( $box[1] + $box[3] ) / 2 ) ) );
		$width   = max( 1e-9, ( $box[2] - $box[0] ) * $squeeze );
		$height  = max( 1e-9, $box[3] - $box[1] );
		$pad     = 12;
		$scale   = ( $size - 2 * $pad ) / max( $width, $height );
		$left    = ( $size - $width * $scale ) / 2;
		$top     = ( $size - $height * $scale ) / 2;

		$type     = $feature['geometry']['type'] ?? '';
		$coords   = $feature['geometry']['coordinates'] ?? array();
		$polygons = 'Polygon' === $type ? array( $coords ) : $coords;
		$d        = '';
		foreach ( $polygons as $polygon ) {
			foreach ( $polygon as $ring ) {
				$points = array();
				foreach ( $ring as $point ) {
					if ( isset( $point[0], $point[1] ) && is_numeric( $point[0] ) && is_numeric( $point[1] ) ) {
						$points[] = round( $left + ( (float) $point[0] - $box[0] ) * $squeeze * $scale, 1 ) . ' ' . round( $top + ( $box[3] - (float) $point[1] ) * $scale, 1 );
					}
				}
				if ( count( $points ) > 2 ) {
					$d .= 'M' . implode( 'L', $points ) . 'Z';
				}
			}
		}
		if ( '' === $d ) {
			return '';
		}

		$name = isset( $feature['properties']['name'] ) ? (string) $feature['properties']['name'] : '';
		return '<svg class="mappulse-outline" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '" role="img" aria-label="'
			/* translators: %s: area name. */
			. esc_attr( sprintf( __( 'Outline of %s', 'mappulse' ), $name ) ) . '"><path d="' . esc_attr( $d ) . '" fill-rule="evenodd"/></svg>';
	}

	/**
	 * Prints the screen.
	 */
	public function render_page(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A read-only search form; nothing is changed.
		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$type  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$state = isset( $_GET['state'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['state'] ) ) ) : '';
		$shape = isset( $_GET['shape'] ) ? sanitize_text_field( wp_unslash( $_GET['shape'] ) ) : '';
		$added = ! empty( $_GET['mappulse_layer_added'] );
		$gone  = ! empty( $_GET['mappulse_layer_removed'] );
		$flush = isset( $_GET['mappulse_flushed'] ) ? absint( $_GET['mappulse_flushed'] ) : null;
		$error = isset( $_GET['mappulse_error'] ) ? sanitize_key( wp_unslash( $_GET['mappulse_error'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap mappulse-sources">';
		echo '<h1>' . esc_html__( 'Boundary sources', 'mappulse' ) . '</h1>';
		echo '<p>' . esc_html__( 'MapPulse draws maps from real boundary data. Cities, ZIP areas and counties come from the US Census Bureau. Neighborhoods come from layers that cities publish, which you register here.', 'mappulse' ) . '</p>';

		if ( $added ) {
			self::notice( 'success', __( 'Layer registered. Its areas can now be found below.', 'mappulse' ) );
		}
		if ( $gone ) {
			self::notice( 'success', __( 'Layer removed.', 'mappulse' ) );
		}
		if ( null !== $flush ) {
			/* translators: %d: number of cached answers removed. */
			self::notice( 'success', sprintf( __( 'Boundary cache cleared: %d saved answers removed.', 'mappulse' ), $flush ) );
		}
		if ( '' !== $error ) {
			self::notice( 'error', self::error_message( $error ) );
		}

		$this->render_find( $query, $type, $state );
		if ( '' !== $shape ) {
			$this->render_shape( $shape );
		}
		$this->render_sources();
		echo '</div>';
	}

	/**
	 * Handles the "add a layer" form.
	 */
	public function handle_add(): void {
		$this->guard();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$url   = isset( $_POST['mappulse_layer_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['mappulse_layer_url'] ) ), array( 'https', 'http' ) ) : '';
		$label = isset( $_POST['mappulse_layer_label'] ) ? sanitize_text_field( wp_unslash( $_POST['mappulse_layer_label'] ) ) : '';
		$place = isset( $_POST['mappulse_layer_place'] ) ? sanitize_text_field( wp_unslash( $_POST['mappulse_layer_place'] ) ) : '';
		// phpcs:enable

		$entry = Sources::add_layer( $url, $label, $place );
		$this->back( is_wp_error( $entry ) ? array( 'mappulse_error' => $entry->get_error_code() ) : array( 'mappulse_layer_added' => 1 ) );
	}

	/**
	 * Handles the "remove" button of a registered layer.
	 */
	public function handle_remove(): void {
		$this->guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in guard().
		$id = isset( $_POST['mappulse_layer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['mappulse_layer_id'] ) ) : '';
		$this->back( Sources::remove_layer( $id ) ? array( 'mappulse_layer_removed' => 1 ) : array( 'mappulse_error' => 'mappulse_unknown_source' ) );
	}

	/**
	 * Handles the "clear the boundary cache" button.
	 */
	public function handle_flush(): void {
		$this->guard();
		$this->back( array( 'mappulse_flushed' => Cache::flush() ) );
	}

	/**
	 * Stops a request that lacks the capability or the nonce.
	 */
	private function guard(): void {
		if ( ! current_user_can( Map_Post_Type::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage boundary sources.', 'mappulse' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE );
	}

	/**
	 * Redirects back to the screen.
	 *
	 * @param array $args Query arguments that carry the result.
	 */
	private function back( array $args ): void {
		wp_safe_redirect( self::page_url( $args ) );
		exit;
	}

	/**
	 * The "Find an area" form and its results.
	 *
	 * @param string $query Search text.
	 * @param string $type  Area type or ''.
	 * @param string $state State postal code or ''.
	 */
	private function render_find( string $query, string $type, string $state ): void {
		$types = self::type_labels();

		echo '<h2>' . esc_html__( 'Find an area', 'mappulse' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'edit.php' ) ) . '" class="mappulse-find">';
		echo '<input type="hidden" name="post_type" value="' . esc_attr( Map_Post_Type::POST_TYPE ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<label><span>' . esc_html__( 'Name or ZIP code', 'mappulse' ) . '</span><input type="search" name="q" value="' . esc_attr( $query ) . '" maxlength="60" required></label>';

		echo '<label><span>' . esc_html__( 'Kind of area', 'mappulse' ) . '</span><select name="type"><option value="">' . esc_html__( 'Any', 'mappulse' ) . '</option>';
		foreach ( $types as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $type, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';

		echo '<label><span>' . esc_html__( 'State', 'mappulse' ) . '</span><select name="state"><option value="">' . esc_html__( 'All states', 'mappulse' ) . '</option>';
		foreach ( Census_Provider::states() as $code => $info ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( $state, $code, false ) . '>' . esc_html( $info[1] ) . '</option>';
		}
		echo '</select></label>';
		submit_button( __( 'Find', 'mappulse' ), 'primary', '', false );
		echo '</form>';

		if ( '' === $query ) {
			return;
		}

		$found = Service::search(
			$query,
			array(
				'type'  => isset( $types[ $type ] ) ? $type : '',
				'state' => $state,
			)
		);

		$labels = array();
		foreach ( Sources::all() as $provider ) {
			$labels[ $provider->id() ] = $provider->label();
		}
		foreach ( $found['errors'] as $source => $message ) {
			self::notice( 'error', isset( $labels[ $source ] ) ? $labels[ $source ] . ': ' . $message : $message );
		}

		if ( ! $found['results'] ) {
			if ( ! $found['errors'] ) {
				echo '<p>' . esc_html__( 'Nothing found. Try fewer letters, another kind of area, or all states. Neighborhoods are found only in layers you have registered.', 'mappulse' ) . '</p>';
			}
			return;
		}

		echo '<table class="widefat striped mappulse-results"><thead><tr>';
		foreach ( array( __( 'Name', 'mappulse' ), __( 'Kind', 'mappulse' ), __( 'Where', 'mappulse' ), __( 'Source', 'mappulse' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $found['results'] as $result ) {
			$link = self::page_url(
				array(
					'q'     => rawurlencode( $query ),
					'type'  => rawurlencode( $type ),
					'state' => rawurlencode( $state ),
					'shape' => rawurlencode( $result['source'] . '|' . $result['record'] ),
				)
			);
			echo '<tr><td><strong>' . esc_html( $result['name'] ) . '</strong></td>';
			echo '<td>' . esc_html( $types[ $result['type'] ] ?? $result['type'] ) . '</td>';
			echo '<td>' . esc_html( $result['parent'] ) . '</td>';
			echo '<td>' . esc_html( $labels[ $result['source'] ] ?? $result['source'] ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $link ) . '#mappulse-boundary">' . esc_html__( 'Show boundary', 'mappulse' ) . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * The chosen area's outline and facts.
	 *
	 * @param string $shape "source|record".
	 */
	private function render_shape( string $shape ): void {
		$parts   = explode( '|', $shape, 2 );
		$feature = 2 === count( $parts ) ? Service::fetch( $parts[0], $parts[1] ) : new \WP_Error( 'mappulse_bad_record', __( 'That is not a valid record.', 'mappulse' ) );

		echo '<h2 id="mappulse-boundary">' . esc_html__( 'Boundary', 'mappulse' ) . '</h2>';
		if ( is_wp_error( $feature ) ) {
			self::notice( 'error', $feature->get_error_message() );
			return;
		}

		$facts = Service::describe( $feature );
		$types = self::type_labels();
		echo '<div class="mappulse-boundary">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from numbers and escaped text in outline_svg().
		echo self::outline_svg( $feature );
		echo '<div><h3>' . esc_html( $feature['properties']['name'] ) . '</h3><ul>';
		echo '<li>' . esc_html( $types[ $feature['properties']['type'] ] ?? $feature['properties']['type'] ) . '</li>';
		/* translators: 1: number of points, 2: number of separate outlines. */
		echo '<li>' . esc_html( sprintf( _n( '%1$s points in %2$s outline', '%1$s points in %2$s outlines', $facts['rings'], 'mappulse' ), number_format_i18n( $facts['points'] ), number_format_i18n( $facts['rings'] ) ) ) . '</li>';
		if ( $facts['bbox'] ) {
			$osm = add_query_arg(
				array(
					'minlon' => $facts['bbox'][0],
					'minlat' => $facts['bbox'][1],
					'maxlon' => $facts['bbox'][2],
					'maxlat' => $facts['bbox'][3],
					'box'    => 'yes',
				),
				'https://www.openstreetmap.org/'
			);
			echo '<li><a href="' . esc_url( $osm ) . '" target="_blank" rel="noopener">' . esc_html__( 'Check this area on a street map', 'mappulse' ) . '</a></li>';
		}
		echo '</ul><p class="description">' . esc_html__( 'This is the real boundary MapPulse will draw. If it is not the area you meant, pick another result.', 'mappulse' ) . '</p></div></div>';
	}

	/**
	 * The table of sources, the add form and the cache button.
	 */
	private function render_sources(): void {
		$types = self::type_labels();

		echo '<h2>' . esc_html__( 'Sources', 'mappulse' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Source', 'mappulse' ), __( 'Offers', 'mappulse' ), __( 'Address', 'mappulse' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( Sources::all() as $provider ) {
			$offers = array();
			foreach ( $provider->types() as $key ) {
				$offers[] = $types[ $key ] ?? $key;
			}
			echo '<tr><td><strong>' . esc_html( $provider->label() ) . '</strong></td><td>' . esc_html( implode( ', ', $offers ) ) . '</td>';
			if ( $provider instanceof Layer_Provider ) {
				echo '<td><code>' . esc_html( $provider->url() ) . '</code></td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mappulse_remove_layer">';
				echo '<input type="hidden" name="mappulse_layer_id" value="' . esc_attr( $provider->id() ) . '">';
				wp_nonce_field( self::NONCE );
				submit_button( __( 'Remove', 'mappulse' ), 'small delete', '', false );
				echo '</form></td></tr>';
			} else {
				echo '<td><code>tigerweb.geo.census.gov</code></td><td>' . esc_html__( 'Built in', 'mappulse' ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Add a neighborhood layer', 'mappulse' ) . '</h3>';
		echo '<p>' . esc_html__( 'Most cities publish their neighborhoods as a map layer on their open-data or GIS site. Open the layer\'s page (an "ArcGIS REST" address that ends in MapServer or FeatureServer and a number) and paste that address here.', 'mappulse' ) . '</p>';
		echo '<p>' . esc_html__( 'Example, St. Petersburg, Florida:', 'mappulse' ) . ' <code>' . esc_html( self::EXAMPLE_LAYER ) . '</code></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mappulse-add-layer">';
		echo '<input type="hidden" name="action" value="mappulse_add_layer">';
		wp_nonce_field( self::NONCE );
		echo '<label><span>' . esc_html__( 'Layer address', 'mappulse' ) . '</span><input type="url" name="mappulse_layer_url" class="regular-text code" placeholder="https://" required></label>';
		echo '<label><span>' . esc_html__( 'Name for this source', 'mappulse' ) . '</span><input type="text" name="mappulse_layer_label" class="regular-text" maxlength="80" required></label>';
		echo '<label><span>' . esc_html__( 'Place it covers', 'mappulse' ) . '</span><input type="text" name="mappulse_layer_place" class="regular-text" maxlength="80" placeholder="' . esc_attr__( 'City, ST', 'mappulse' ) . '"></label>';
		submit_button( __( 'Add layer', 'mappulse' ), 'secondary', '', false );
		echo '</form>';

		echo '<h3>' . esc_html__( 'Saved answers', 'mappulse' ) . '</h3>';
		echo '<p>' . esc_html__( 'Boundary answers are saved on this site for 30 days so the sources are not asked twice. Clear them if a boundary has changed.', 'mappulse' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mappulse_flush_cache">';
		wp_nonce_field( self::NONCE );
		submit_button( __( 'Clear the boundary cache', 'mappulse' ), 'secondary', '', false );
		echo '</form>';
	}

	/**
	 * Area type labels.
	 */
	private static function type_labels(): array {
		return array(
			'city'         => __( 'City', 'mappulse' ),
			'zip'          => __( 'ZIP area', 'mappulse' ),
			'county'       => __( 'County', 'mappulse' ),
			'neighborhood' => __( 'Neighborhood', 'mappulse' ),
		);
	}

	/**
	 * Prints an admin notice.
	 *
	 * @param string $kind    success or error.
	 * @param string $message Plain text.
	 */
	private static function notice( string $kind, string $message ): void {
		printf( '<div class="notice notice-%s inline"><p>%s</p></div>', 'success' === $kind ? 'success' : 'error', esc_html( $message ) );
	}

	/**
	 * The message for an error code carried in the URL.
	 *
	 * @param string $code Error code.
	 */
	private static function error_message( string $code ): string {
		$messages = array(
			'mappulse_bad_layer_url'   => __( 'Enter the https address of one ArcGIS layer, ending in MapServer or FeatureServer and the layer number.', 'mappulse' ),
			'mappulse_not_polygons'    => __( 'That layer does not hold area shapes. Choose a polygon layer.', 'mappulse' ),
			'mappulse_no_name_field'   => __( 'That layer has no text field to search by name.', 'mappulse' ),
			'mappulse_duplicate_layer' => __( 'That layer, or one with the same name, is already registered.', 'mappulse' ),
			'mappulse_unknown_source'  => __( 'That boundary source is not registered on this site.', 'mappulse' ),
			'mappulse_http'            => __( 'The layer\'s server could not be reached. Check the address and try again.', 'mappulse' ),
			'mappulse_bad_response'    => __( 'That address did not answer like an ArcGIS layer.', 'mappulse' ),
			'mappulse_source_error'    => __( 'The layer\'s server reported an error for that address.', 'mappulse' ),
		);
		return $messages[ $code ] ?? __( 'That did not work. Please try again.', 'mappulse' );
	}
}
