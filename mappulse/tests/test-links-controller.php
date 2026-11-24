<?php

class Test_Links_Controller extends MapPulse_Test_Case {

	private $ids = array();

	public function set_up() {
		parent::set_up();
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
		register_post_type( 'mp_hidden', array( 'public' => false, 'label' => 'Hidden things' ) );
		$make = function ( string $title, string $type = 'page', string $status = 'publish' ) {
			return self::factory()->post->create( array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status ) );
		};
		$this->ids = array(
			'page'    => $make( 'Aventura' ),
			'post'    => $make( 'Aventura', 'post' ),
			'guide'   => $make( 'Aventura Condos Guide', 'post' ),
			'draft'   => $make( 'Aventura Draft', 'page', 'draft' ),
			'private' => $make( 'Aventura Private', 'page', 'private' ),
			'hidden'  => $make( 'Aventura Hidden', 'mp_hidden' ),
			'stpete'  => $make( 'St Pete Beach' ),
			'body'    => self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'About us', 'post_content' => 'We love Aventura.' ) ),
		);
	}

	public function tear_down() {
		unregister_post_type( 'mp_hidden' );
		parent::tear_down();
	}

	private function call( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/mappulse/v1' . $route );
		if ( 'POST' === $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $params ) );
		} else {
			foreach ( $params as $key => $value ) {
				$request->set_param( $key, $value );
			}
		}
		return rest_get_server()->dispatch( $request );
	}

	private function sign_in( string $role ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	public function test_visitors_and_editors_are_refused() {
		foreach ( array( 0 => 401, 'editor' => 403 ) as $role => $status ) {
			if ( $role ) {
				$this->sign_in( $role );
			} else {
				wp_set_current_user( 0 );
			}
			$this->assertSame( $status, $this->call( 'GET', '/links/search', array( 'q' => 'aventura' ) )->get_status() );
			$this->assertSame( $status, $this->call( 'POST', '/links/match', array( 'names' => array( 'Aventura' ) ) )->get_status() );
		}
	}

	public function test_search_finds_published_public_posts_by_title_pages_first() {
		$this->sign_in( 'administrator' );
		$data = $this->call( 'GET', '/links/search', array( 'q' => 'aventura' ) )->get_data();
		$ids  = wp_list_pluck( $data, 'id' );

		$this->assertSame( $this->ids['page'], $ids[0] );
		$this->assertContains( $this->ids['post'], $ids );
		$this->assertContains( $this->ids['guide'], $ids );
		foreach ( array( 'draft', 'private', 'hidden', 'body' ) as $unwanted ) {
			$this->assertNotContains( $this->ids[ $unwanted ], $ids, $unwanted );
		}
		$this->assertSame( array( 'id', 'title', 'url', 'type' ), array_keys( $data[0] ) );
		$this->assertSame( get_permalink( $this->ids['page'] ), $data[0]['url'] );
		$this->assertSame( 'Page', $data[0]['type'] );
	}

	public function test_search_needs_two_to_sixty_characters() {
		$this->sign_in( 'administrator' );
		$this->assertSame( 400, $this->call( 'GET', '/links/search', array( 'q' => 'a' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/links/search', array( 'q' => str_repeat( 'a', 61 ) ) )->get_status() );
		$this->assertSame( 400, $this->call( 'GET', '/links/search' )->get_status() );
		$this->assertSame( array(), $this->call( 'GET', '/links/search', array( 'q' => 'zzzznothing' ) )->get_data() );
	}

	public function test_match_pairs_names_with_posts_of_the_same_title() {
		$this->sign_in( 'administrator' );
		$data = $this->call( 'POST', '/links/match', array( 'names' => array( 'Aventura', 'Nowhere', 'St. Pete Beach', 'aventura condos' ) ) )->get_data();

		$this->assertSame( array( $this->ids['page'], $this->ids['post'] ), wp_list_pluck( $data['Aventura'], 'id' ) );
		$this->assertSame( array(), $data['Nowhere'] );
		$this->assertSame( array( $this->ids['stpete'] ), wp_list_pluck( $data['St. Pete Beach'], 'id' ) );
		// A title that only starts with the name is not a match.
		$this->assertSame( array(), $data['aventura condos'] );
	}

	public function test_match_refuses_bad_input() {
		$this->sign_in( 'administrator' );
		$this->assertSame( 400, $this->call( 'POST', '/links/match', array( 'names' => 'Aventura' ) )->get_status() );
		$this->assertSame( 400, $this->call( 'POST', '/links/match', array( 'names' => array_fill( 0, MAPPULSE_MAX_ZONES + 1, 'x' ) ) )->get_status() );
		$data = $this->call( 'POST', '/links/match', array( 'names' => array( '<b>Aventura</b>', '', array( 'x' ) ) ) )->get_data();
		$this->assertSame( array( 'Aventura' ), array_keys( $data ) );
	}
}
