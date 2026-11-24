<?php
/**
 * Map file import: the way maps get into MapPulse until the builder exists.
 *
 * @package MapPulse
 */

namespace MapPulse;

defined( 'ABSPATH' ) || exit;

/**
 * Imports map documents from JSON, from a file, from the admin screen and from the bundled sample.
 */
class Importer {

	const ACTION = 'mappulse_import';
	const PAGE   = 'mappulse-import';

	/**
	 * Path of the bundled sample map.
	 */
	public static function sample_path(): string {
		return MAPPULSE_DIR . 'samples/st-petersburg.map.json';
	}

	/**
	 * Admin URL of the Import screen.
	 */
	public static function page_url(): string {
		return admin_url( 'edit.php?post_type=' . Map_Post_Type::POST_TYPE . '&page=' . self::PAGE );
	}

	/**
	 * Prints an "Import a map" button at the top of the map list.
	 *
	 * @param array $views List table views, returned unchanged.
	 */
	public static function list_button( array $views ): array {
		printf(
			'<p><a class="page-title-action" href="%s">%s</a></p>',
			esc_url( self::page_url() ),
			esc_html__( 'Import a map', 'mappulse' )
		);
		return $views;
	}

	/**
	 * The prompt shown in an editor when a placement has no map chosen yet.
	 */
	public static function prompt_html(): string {
		return '<p class="mappulse-notice">' . esc_html__( 'Choose a map in the settings.', 'mappulse' )
			. ' <a href="' . esc_url( self::page_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'No maps in the list? Import one first.', 'mappulse' ) . '</a></p>';
	}

	/**
	 * Imports a map from a JSON string.
	 *
	 * @param string $json  Contents of a map file.
	 * @param string $title Optional name for the map. Defaults to the title inside the file.
	 * @return int|\WP_Error The new map's post ID.
	 */
	public static function from_json( string $json, string $title = '' ) {
		if ( strlen( $json ) > MAPPULSE_MAX_IMPORT_BYTES ) {
			return new \WP_Error( 'mappulse_too_large', __( 'The map file is larger than MapPulse accepts.', 'mappulse' ) );
		}
		$raw = json_decode( $json, true );
		if ( ! is_array( $raw ) ) {
			return new \WP_Error( 'mappulse_json', __( 'The file is not a valid MapPulse map file.', 'mappulse' ) );
		}
		if ( '' !== trim( $title ) ) {
			$raw['title'] = $title;
		}
		return Map_Post_Type::save( $raw );
	}

	/**
	 * Imports a map from a file on disk.
	 *
	 * @param string $path  Path to a map file.
	 * @param string $title Optional name for the map.
	 * @return int|\WP_Error The new map's post ID.
	 */
	public static function from_file( string $path, string $title = '' ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return new \WP_Error( 'mappulse_file', __( 'The map file could not be read.', 'mappulse' ) );
		}
		if ( filesize( $path ) > MAPPULSE_MAX_IMPORT_BYTES ) {
			return new \WP_Error( 'mappulse_too_large', __( 'The map file is larger than MapPulse accepts.', 'mappulse' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, not a remote request.
		return self::from_json( (string) file_get_contents( $path ), $title );
	}

	/**
	 * Adds the admin hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'views_edit-' . Map_Post_Type::POST_TYPE, array( self::class, 'list_button' ) );
	}

	/**
	 * Adds "Import" under the MapPulse menu.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Map_Post_Type::POST_TYPE,
			__( 'Import a map', 'mappulse' ),
			__( 'Import', 'mappulse' ),
			Map_Post_Type::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Prints the import screen.
	 */
	public function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import a map', 'mappulse' ); ?></h1>
			<p><?php esc_html_e( 'Until the map builder arrives, maps are added from a MapPulse map file (.map.json).', 'mappulse' ); ?></p>

			<h2><?php esc_html_e( 'From a map file', 'mappulse' ); ?></h2>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<p><input type="file" name="mappulse_file" accept=".json,application/json" required></p>
				<?php submit_button( __( 'Import map file', 'mappulse' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Sample map', 'mappulse' ); ?></h2>
			<p><?php esc_html_e( 'A real map of St. Petersburg, Florida with 16 neighborhoods and one city. Use it to try the block, the Elementor widget and the shortcode.', 'mappulse' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="mappulse_sample" value="1">
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Import the sample map', 'mappulse' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles a submitted import form, then redirects back with the result.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( Map_Post_Type::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to import maps.', 'mappulse' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		if ( ! empty( $_POST['mappulse_sample'] ) ) {
			$result = self::from_file( self::sample_path(), __( 'St. Petersburg, FL (sample)', 'mappulse' ) );
		} elseif ( isset( $_FILES['mappulse_file']['tmp_name'], $_FILES['mappulse_file']['error'] ) && UPLOAD_ERR_OK === (int) $_FILES['mappulse_file']['error'] ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- A server-generated temp path, checked with is_uploaded_file().
			$tmp_name = (string) $_FILES['mappulse_file']['tmp_name'];
			$result   = is_uploaded_file( $tmp_name )
				? self::from_file( $tmp_name )
				: new \WP_Error( 'mappulse_file', __( 'The map file could not be read.', 'mappulse' ) );
		} else {
			$result = new \WP_Error( 'mappulse_file', __( 'The map file could not be read.', 'mappulse' ) );
		}

		$list = admin_url( 'edit.php?post_type=' . Map_Post_Type::POST_TYPE );
		$url  = is_wp_error( $result )
			? add_query_arg(
				array(
					'page'           => self::PAGE,
					'mappulse_error' => $result->get_error_code(),
				),
				$list
			)
			: add_query_arg( 'mappulse_imported', (int) $result, $list );

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Shows the result of the last import on the MapPulse screens.
	 */
	public function notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || Map_Post_Type::POST_TYPE !== $screen->post_type || ! current_user_can( Map_Post_Type::CAPABILITY ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only display of a redirect result.
		if ( isset( $_GET['mappulse_imported'] ) ) {
			$map_id = absint( $_GET['mappulse_imported'] );
			if ( Map_Post_Type::POST_TYPE === get_post_type( $map_id ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s <code>[mappulse id="%d"]</code></p></div>',
					esc_html__( 'Map imported. Place it with the MapPulse block, the MapPulse Elementor widget, or this shortcode:', 'mappulse' ),
					(int) $map_id
				);
			}
		}

		if ( isset( $_GET['mappulse_error'] ) ) {
			$messages = array(
				'mappulse_too_large'      => __( 'The map file is larger than MapPulse accepts.', 'mappulse' ),
				'mappulse_json'           => __( 'The file is not a valid MapPulse map file.', 'mappulse' ),
				'mappulse_schema'         => __( 'This map file uses a schema version MapPulse cannot read.', 'mappulse' ),
				'mappulse_bad_path'       => __( 'The map file holds shape data MapPulse does not accept.', 'mappulse' ),
				'mappulse_duplicate_zone' => __( 'Two zones in the map file share one ID.', 'mappulse' ),
				'mappulse_zone_shape'     => __( 'A zone in the map file has no shape.', 'mappulse' ),
				'mappulse_frame'          => __( 'The map frame is missing or outside the allowed size.', 'mappulse' ),
				'mappulse_too_many_zones' => __( 'The map file holds more zones than MapPulse allows.', 'mappulse' ),
			);
			$code     = sanitize_key( wp_unslash( $_GET['mappulse_error'] ) );
			$message  = $messages[ $code ] ?? __( 'The map file could not be read.', 'mappulse' );
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		}
		// phpcs:enable
	}
}
