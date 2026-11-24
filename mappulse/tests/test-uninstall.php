<?php
use MapPulse\Boundaries\Cache;
use MapPulse\Boundaries\Sources;

class Test_Uninstall extends MapPulse_Boundary_Test_Case {

	public function test_uninstall_removes_the_capability_layers_and_cache_but_keeps_maps() {
		do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
		$this->http->fixture( 'MapServer/22?f=json', 'stpete-layer-info.json' );
		Sources::add_layer( 'https://egis.stpete.org/arcgis/rest/services/ServicesDOTS/NeighborhoodRelations/MapServer/22', 'St. Petersburg neighborhoods' );
		Cache::set( 'k', array( 1 ) );
		$map = \MapPulse\Map_Post_Type::save( $this->fixture() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'mappulse/mappulse.php' );
		}
		include MAPPULSE_DIR . 'uninstall.php';

		$this->assertFalse( get_role( 'administrator' )->has_cap( 'manage_mappulse_maps' ) );
		$this->assertFalse( get_option( 'mappulse_layers' ) );
		$this->assertNull( Cache::get( 'k' ) );
		$this->assertSame( 'mappulse_map', get_post_type( $map ) );
	}
}
