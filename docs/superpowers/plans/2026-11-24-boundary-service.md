# MapPulse Boundary Service (0.2.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** MapPulse 0.2.0 finds real US city, ZIP, county and neighborhood boundaries by name from inside WordPress, shows each shape for confirmation, and serves boundaries and base-map layers to the coming builder through `mappulse/v1`.

**Architecture:** PHP boundary providers sit behind one interface: a built-in Census provider and one provider per registered ArcGIS layer (how a city publishes its neighborhoods). Every remote call goes through one HTTP client (allowed hosts, timeout, spacing, size cap) and one file cache. A service class merges providers; REST routes, a WP-CLI command and a "Sources" admin screen are thin callers of that service.

**Tech Stack:** PHP 8.1+, WordPress 6.8+, `wp_safe_remote_get`, PHPUnit 9.6 with every HTTP call answered by fixtures through the `pre_http_request` filter, the existing wp-env workflow, plain PHP admin screen (no build step in this plan).

**Spec:** [`docs/development-plan.md`](../../development-plan.md), sections 4, 6, 10 and 11, and [`docs/data-sources.md`](../../data-sources.md).

This is plan B of three for Phase 1. Plan A (0.1.0) is merged. Plan C (0.3.0) is the builder: it moves the geometry pipeline into the plugin and adds the `/maps`, `/links/search` and `/render` routes.

## Global Constraints

- Version `0.2.0` in the plugin header, `MAPPULSE_VERSION`, `readme.txt` (`Stable tag`), `blocks/mappulse/block.json`, `blocks/mappulse/index.asset.php` and `CHANGELOG.md`.
- New PHP lives in `mappulse/includes/boundaries/`, namespace `MapPulse\Boundaries`, one class per file, WordPress file naming.
- Every remote request goes through `Http::get_json()`. Nothing else calls `wp_remote_*`.
- Remote hosts are limited to `tigerweb.geo.census.gov` plus the hosts of registered layers. Only `https` URLs.
- Request timeout 20 seconds. At least 300 ms between two requests to one host. Response body cap 12 MB. Two retries on a timeout or a 5xx answer.
- Cache: JSON files under `wp_upload_dir()['basedir'] . '/mappulse/cache/'`, default lifetime 30 days. An error is never cached.
- Search text is 2 to 60 characters. A value placed in an ArcGIS `where` clause is upper-cased, reduced to letters, digits, spaces, hyphens, periods and apostrophes, and has every apostrophe doubled.
- A bounding box is `west,south,east,north` in degrees, each side at most 1.5 degrees.
- Every REST route and admin action requires `manage_mappulse_maps`. REST namespace `mappulse/v1`.
- Area types are exactly `city`, `zip`, `county`, `neighborhood`.
- A search result is `array( 'source' => string, 'record' => string, 'name' => string, 'type' => string, 'parent' => string, 'center' => array( lon, lat ) | null )`.
- A fetched shape is a GeoJSON Feature in WGS 84 with `properties` holding `source`, `record`, `name`, `type`.
- Front-end output and the stored map document do not change in this plan.
- Every user-facing string is translatable with the `mappulse` text domain. PHPCS stays clean.

## Review Focus

1. A source that is down, slow, or answers with HTML or an ArcGIS error object (`{"error":{…}}` with HTTP 200): the caller gets a `WP_Error`, the screen still loads and says which source failed, and nothing is cached. Tasks 2, 6 and 8.
2. Search text with quotes, `%`, `_`, SQL keywords, 500 characters, or nothing: escaped or rejected, never placed raw in a `where` clause. Tasks 3 and 4.
3. A registered layer URL that is `http`, a private or local address, not an ArcGIS layer, or a point or line layer: rejected before anything is stored. Task 5.
4. An oversized answer (a wide bounding box, thousands of features, a body over the cap): rejected or stopped at the cap with a clear error, not a memory failure. Tasks 2, 3 and 6.
5. A cache folder that cannot be written, or a cache file that is corrupt: the service still answers, uncached, and replaces the bad file. Task 2.

---

### Task 1: Admin fixes from the first site test

**Files:**
- Modify: `mappulse/includes/class-map-post-type.php`, `mappulse/includes/class-importer.php`, `mappulse/includes/integrations/class-elementor-widget.php`
- Test: `mappulse/tests/test-admin.php`

**Interfaces:**
- Produces: `MapPulse\Importer::page_url(): string` (admin URL of the Import screen). The Maps list gains a "Shortcode" column and an "Import a map" button beside the page title. The post type's `not_found` text and the widget's empty notice both name the Import screen; the widget notice links to it.

- [ ] **Step 1: Write the failing tests**

```php
public function test_maps_list_has_a_shortcode_column() {
	$columns = apply_filters( 'manage_mappulse_map_posts_columns', array( 'cb' => '', 'title' => 'Title', 'date' => 'Date' ) );
	$this->assertSame( array( 'cb', 'title', 'mappulse_shortcode', 'date' ), array_keys( $columns ) );
	$id = Map_Post_Type::save( $this->fixture() );
	ob_start();
	do_action( 'manage_mappulse_map_posts_custom_column', 'mappulse_shortcode', $id );
	$this->assertStringContainsString( '[mappulse id=&quot;' . $id . '&quot;]', ob_get_clean() );
}
public function test_import_page_url_points_at_the_import_screen() {
	$this->assertStringContainsString( 'edit.php?post_type=mappulse_map&page=mappulse-import', Importer::page_url() );
}
```

- [ ] **Step 2: Run and see them fail.** Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/mappulse vendor/bin/phpunit --filter Test_Admin`. Expected: FAIL, column missing and method undefined.
- [ ] **Step 3: Implement.** The button is printed on the Maps list screen through the `views_edit-mappulse_map` filter as a `page-title-action` link. The shortcode is printed inside a read-only `<input class="code">` so it can be selected in one click.
- [ ] **Step 4: Run and see them pass.** Expected: 2 tests pass.
- [ ] **Step 5: Commit** (`Add Import button, linked empty states and a Shortcode column`).

### Task 2: HTTP client and file cache

**Files:**
- Create: `mappulse/includes/boundaries/class-http.php`, `mappulse/includes/boundaries/class-cache.php`, `mappulse/tests/class-mappulse-http-mock.php` (test helper: answers `pre_http_request` from a map of URL fragments to fixture bodies or errors, and records every requested URL)
- Test: `mappulse/tests/test-http-cache.php`

**Interfaces:**
- Produces: `Http::get_json( string $url, array $query = array() ): array|\WP_Error` with error codes `mappulse_host_not_allowed`, `mappulse_http`, `mappulse_too_large`, `mappulse_bad_response`, `mappulse_source_error` (an ArcGIS error object; its message is passed on). `Http::allowed_hosts(): array` (filter `mappulse_allowed_hosts`). `Cache::get( string $key ): ?array`, `Cache::set( string $key, array $value, int $ttl = 30 * DAY_IN_SECONDS ): bool`, `Cache::flush(): int`, `Cache::dir(): string`.

- [ ] **Step 1: Write the failing tests** (each is one behavior):
  - `test_json_from_an_allowed_host_is_returned`: a fixture body `{"features":[]}` on `tigerweb.geo.census.gov` returns `array( 'features' => array() )`.
  - `test_other_hosts_are_refused_without_a_request`: `https://example.com/x` returns `mappulse_host_not_allowed` and the mock recorded 0 requests.
  - `test_http_urls_are_refused`: `http://tigerweb.geo.census.gov/x` returns `mappulse_host_not_allowed`.
  - `test_server_errors_are_retried_twice_then_reported`: three 503 answers give `mappulse_http` and the mock recorded 3 requests.
  - `test_html_answers_are_rejected`: body `<html>` gives `mappulse_bad_response`.
  - `test_arcgis_error_objects_are_errors`: body `{"error":{"code":400,"message":"Invalid query"}}` gives `mappulse_source_error` whose message contains `Invalid query`.
  - `test_bodies_over_the_cap_are_rejected`: with the filter `mappulse_max_response_bytes` set to 10, a longer body gives `mappulse_too_large`.
  - `test_cache_round_trip_and_expiry`: `set( 'k', array( 'a' => 1 ), 60 )` then `get( 'k' )` is `array( 'a' => 1 )`; after the file's stored expiry is moved into the past, `get( 'k' )` is `null`.
  - `test_corrupt_cache_file_is_ignored`: a cache file holding `{broken` makes `get()` return `null`.
  - `test_unwritable_cache_folder_does_not_break_set`: with `Cache::dir()` filtered to a path under a regular file, `set()` returns `false` and raises nothing.
- [ ] **Step 2: Run and see them fail** (`--filter Test_Http_Cache`). Expected: FAIL, classes not found.
- [ ] **Step 3: Implement.** `Http` builds the query string with `http_build_query` (so values are URL-encoded), calls `wp_safe_remote_get` with the timeout and a `User-Agent` of `MapPulse/<version>; <home_url>`, spaces requests per host with a transient holding the last request time, and decodes with `json_decode( …, true )`. `Cache` names files `sha1( $key ) . '.json'`, stores `{ "expires": <timestamp>, "value": … }`, and creates its folder with an empty `index.html`.
- [ ] **Step 4: Run and see them pass.** Expected: 10 tests pass.
- [ ] **Step 5: Commit** (`Add boundary HTTP client and file cache`).

### Task 3: ArcGIS query helper

**Files:**
- Create: `mappulse/includes/boundaries/class-arcgis.php`, `mappulse/tests/fixtures/http/` (hand-made ArcGIS answers: `layers.json`, `layer-polygon.json`, `layer-point.json`, `places-page-1.json` with `"exceededTransferLimit": true`, `places-page-2.json`)
- Test: `mappulse/tests/test-arcgis.php`

**Interfaces:**
- Consumes: `Http::get_json()`.
- Produces: `Arcgis::clean( string $text ): string` (the `where` value rule from Global Constraints), `Arcgis::layers( string $service_url ): array|\WP_Error` (id and name of each layer, cached one day), `Arcgis::find_layer( string $service_url, string $name_pattern, ?int $fallback_id ): int|\WP_Error`, `Arcgis::layer_info( string $layer_url ): array|\WP_Error` (`name`, `geometryType`, `fields` as name => type), `Arcgis::query( string $layer_url, array $params, int $max_features = 2000 ): array|\WP_Error` (a GeoJSON FeatureCollection; adds `f=geojson`, `outSR=4326`, `geometryPrecision=6`; follows `exceededTransferLimit` with `resultOffset`; error `mappulse_too_many_features` past the cap).

- [ ] **Step 1: Write the failing tests:**

```php
public function test_clean_escapes_and_strips() {
	$this->assertSame( "ST. PETE''S", Arcgis::clean( "st. pete's" ) );
	$this->assertSame( "O''BRIEN", Arcgis::clean( "O'Brien" ) );
	$this->assertSame( 'A B', Arcgis::clean( 'a%_;" b' ) );
	$this->assertSame( 60, strlen( Arcgis::clean( str_repeat( 'x', 500 ) ) ) );
}
public function test_layer_is_found_by_name_then_by_fallback() {
	$this->assertSame( 28, Arcgis::find_layer( self::SERVICE, '/^incorporated places$/i', null ) );
	$this->assertSame( 7, Arcgis::find_layer( self::SERVICE, '/^no such layer$/i', 7 ) );
	$this->assertWPError( Arcgis::find_layer( self::SERVICE, '/^no such layer$/i', null ) );
}
public function test_query_follows_paging_and_merges_features() {
	$result = Arcgis::query( self::SERVICE . '/28', array( 'where' => "BASENAME LIKE 'GULF%'" ) );
	$this->assertCount( 3, $result['features'] );
	$this->assertStringContainsString( 'resultOffset=2', $this->http->requests[1] );
}
public function test_query_stops_at_the_feature_cap() {
	$this->assertSame( 'mappulse_too_many_features', Arcgis::query( self::SERVICE . '/28', array( 'where' => '1=1' ), 2 )->get_error_code() );
}
```

- [ ] **Step 2: Run and see them fail** (`--filter Test_Arcgis`). Expected: FAIL, class not found.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run and see them pass.** Expected: 4 tests pass.
- [ ] **Step 5: Commit** (`Add ArcGIS query helper`).

### Task 4: Census provider

**Files:**
- Create: `mappulse/includes/boundaries/interface-provider.php`, `mappulse/includes/boundaries/class-census-provider.php`, `mappulse/includes/boundaries/states.php` (returns postal code => array( FIPS code, name ) for the 50 states, DC and PR), fixtures `census-places-gulfport.json`, `census-zcta-33701.json`, `census-counties-pinellas.json`, `census-place-shape.json`
- Test: `mappulse/tests/test-census-provider.php`

**Interfaces:**
- Consumes: `Arcgis`, `Cache`.
- Produces: `interface Provider { id(): string; label(): string; types(): array; search( string $query, array $args ): array|\WP_Error; fetch( string $record ): array|\WP_Error; }` where `$args` holds `type` (an area type or `''`), `state` (postal code or `''`) and `limit` (default 20). `Census_Provider` with `id()` `'census'`, types `city`, `zip`, `county`. Records are `place:<GEOID>`, `zcta:<ZCTA5>`, `county:<GEOID>`. Service `https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/tigerWMS_Current/MapServer`; layers found by name: `/^incorporated places$/i` (fallback 28), `/zip code tabulation areas$/i` (fallback 2), `/^counties$/i` (fallback 82). Fields: `BASENAME`, `NAME`, `STATE` (FIPS), `GEOID`, `ZCTA5`, `CENTLON`, `CENTLAT`.

- [ ] **Step 1: Write the failing tests:**
  - `test_city_search_returns_typed_results_with_state_as_parent`: `search( 'gulfport', array( 'state' => 'FL' ) )` returns one result equal to `array( 'source' => 'census', 'record' => 'place:1228175', 'name' => 'Gulfport', 'type' => 'city', 'parent' => 'Florida', 'center' => array( -82.7104, 27.7462 ) )`, and the request URL contains `UPPER%28BASENAME%29+LIKE+%27GULFPORT%25%27` and `STATE%3D%2712%27`.
  - `test_five_digits_search_zip_areas_only`: `search( '33701', array() )` returns one `zip` result with record `zcta:33701`, and exactly one layer was queried.
  - `test_type_filter_limits_the_layers_queried`: `search( 'pinellas', array( 'type' => 'county' ) )` queries only the counties layer and returns `Pinellas County` with type `county`.
  - `test_unknown_state_is_rejected`: `search( 'gulfport', array( 'state' => 'ZZ' ) )` is a `WP_Error` with code `mappulse_bad_state`.
  - `test_short_or_empty_text_is_rejected`: `search( 'g', array() )` and `search( '  ', array() )` give `mappulse_bad_query` and send no request.
  - `test_fetch_returns_a_feature_and_caches_it`: `fetch( 'place:1228175' )` returns a Feature with `properties` `source` `census`, `record` `place:1228175`, `name` `Gulfport`, `type` `city`; a second call sends no new request.
  - `test_fetch_rejects_a_malformed_record`: `fetch( "place:12'; DROP" )` and `fetch( 'road:1' )` give `mappulse_bad_record` and send no request.
- [ ] **Step 2: Run and see them fail** (`--filter Test_Census_Provider`). Expected: FAIL, class not found.
- [ ] **Step 3: Implement.** Search results are cached one day by provider, type, state and cleaned text. Shapes are cached 30 days. Names match case-insensitively with `UPPER(BASENAME) LIKE '<text>%'` first; when that returns nothing, with `LIKE '%<text>%'`.
- [ ] **Step 4: Run and see them pass.** Expected: 7 tests pass.
- [ ] **Step 5: Commit** (`Add Census boundary provider`).

### Task 5: Registered layers and the source registry

**Files:**
- Create: `mappulse/includes/boundaries/class-layer-provider.php`, `mappulse/includes/boundaries/class-sources.php`, fixtures `stpete-layer-info.json`, `stpete-search-kenwood.json`, `stpete-shape.json`
- Test: `mappulse/tests/test-sources.php`

**Interfaces:**
- Consumes: `Arcgis`, `Cache`, `Provider`.
- Produces: `Sources::all(): Provider[]` (Census first), `Sources::get( string $id ): ?Provider`, `Sources::add_layer( string $url, string $label, string $parent = '' ): array|\WP_Error` (returns the stored entry `id`, `url`, `label`, `parent`, `name_field`, `id_field`), `Sources::remove_layer( string $id ): bool`, `Sources::layers(): array`. Stored in the option `mappulse_layers` (not autoloaded). A layer's provider id is `layer:<slug of label>`; its type is `neighborhood`; its records are the layer's object ID as digits. `Layer_Provider` picks the name field as the first string field matching `/name/i`, else the first string field. Error codes: `mappulse_bad_layer_url`, `mappulse_not_polygons`, `mappulse_no_name_field`, `mappulse_duplicate_layer`. `Sources` adds the hosts of registered layers to the allowed list through the `mappulse_allowed_hosts` filter.

- [ ] **Step 1: Write the failing tests:**
  - `test_adding_a_polygon_layer_stores_it_and_allows_its_host`: adding `https://egis.stpete.org/arcgis/rest/services/ServicesDOTS/NeighborhoodRelations/MapServer/22` with label `St. Petersburg neighborhoods` and parent `St. Petersburg, FL` returns an entry with `id` `layer:st-petersburg-neighborhoods`, `name_field` `NANAME`, `id_field` `OBJECTID`; `Http::allowed_hosts()` now contains `egis.stpete.org`.
  - `test_layer_urls_must_be_https_arcgis_layers`: `http://…/MapServer/22`, `https://egis.stpete.org/arcgis/rest/services/X/MapServer` (no layer number), `https://localhost/arcgis/rest/services/X/MapServer/1` and `https://example.com/file.json` each give `mappulse_bad_layer_url`, and nothing is stored.
  - `test_point_layers_are_rejected`: a layer whose info says `esriGeometryPoint` gives `mappulse_not_polygons`.
  - `test_the_same_layer_cannot_be_added_twice`: the second add gives `mappulse_duplicate_layer`.
  - `test_layer_search_returns_neighborhood_results`: `Sources::get( 'layer:st-petersburg-neighborhoods' )->search( 'kenwood', array() )` returns results with type `neighborhood`, parent `St. Petersburg, FL`, names taken from `NANAME`, and the request URL contains `UPPER%28NANAME%29+LIKE+%27%25KENWOOD%25%27`.
  - `test_layer_fetch_returns_a_feature`: `fetch( '41' )` returns a Feature with `properties.type` `neighborhood`; `fetch( '41 OR 1=1' )` gives `mappulse_bad_record`.
  - `test_removing_a_layer_removes_its_host`: after `remove_layer()`, `Sources::get()` is `null` and the host is no longer allowed.
- [ ] **Step 2: Run and see them fail** (`--filter Test_Sources`). Expected: FAIL, class not found.
- [ ] **Step 3: Implement.** `add_layer()` checks the URL shape with `/^https:\/\/[^\/]+\/.+\/(MapServer|FeatureServer)\/\d+$/i`, refuses a host that is `localhost`, has no dot, or is an IP address, then temporarily allows that one host to read the layer info through `Http` (which uses `wp_safe_remote_get`, so local and private addresses are refused), and stores the entry only when every check passes.
- [ ] **Step 4: Run and see them pass.** Expected: 7 tests pass.
- [ ] **Step 5: Commit** (`Add registered ArcGIS layers and the source registry`).

### Task 6: The boundary service

**Files:**
- Create: `mappulse/includes/boundaries/class-service.php`, fixtures `base-water.json`, `base-roads-primary.json`, `base-roads-secondary.json`, `base-counties.json`, `base-places.json`
- Test: `mappulse/tests/test-service.php`

**Interfaces:**
- Consumes: `Sources`, `Provider`, `Arcgis`, `Cache`.
- Produces: `Service::search( string $query, array $args = array() ): array` returning `array( 'results' => Result[], 'errors' => array<string,string> )` (errors keyed by provider id; one failing provider never hides the others' results; results ordered exact name match, then starts-with, then contains, then by name). `Service::fetch( string $source, string $record ): array|\WP_Error` (`mappulse_unknown_source` for an unknown id). `Service::base( array $bbox ): array|\WP_Error` returning FeatureCollections under `land`, `cities`, `areas`, `water`, `roadsMajor`, `roadsMinor` (`mappulse_bad_bbox` when malformed or over 1.5 degrees a side). `Service::parse_bbox( string $text ): array|\WP_Error`. Base layers: counties as `land`, incorporated places as `cities`, every registered layer as `areas`, TIGERweb `Hydro` service layer `/^areal hydrography$/i` (fallback 1) with `maxAllowableOffset=0.00004` as `water`, `Transportation` service `/^primary roads$/i` (fallback 2) and `/^secondary roads/i` (fallback 6). Cached 30 days by the box rounded outward to 0.01 degree.

- [ ] **Step 1: Write the failing tests:**
  - `test_search_merges_providers_and_orders_exact_matches_first`.
  - `test_a_failing_provider_is_reported_and_the_rest_still_answer`: with the layer host answering 503, `results` still holds the Census results and `errors` has the key `layer:st-petersburg-neighborhoods`.
  - `test_type_filter_skips_providers_that_do_not_offer_it`: `type` `neighborhood` sends no request to the Census host.
  - `test_fetch_from_an_unknown_source_is_an_error`.
  - `test_base_returns_all_six_layers_for_a_valid_box`.
  - `test_base_rejects_malformed_and_oversized_boxes`: `'a,b,c,d'`, `'-82.9,27.6,-82.5'`, a box with west greater than east, and `'-84,27,-82,28'` each give `mappulse_bad_bbox` and send no request.
  - `test_base_is_cached_by_rounded_box`: two boxes that round to the same 0.01 degree box send requests once.
- [ ] **Step 2: Run and see them fail** (`--filter Test_Service`). Expected: FAIL, class not found.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run and see them pass.** Expected: 7 tests pass.
- [ ] **Step 5: Commit** (`Add boundary service: merged search, shape fetch, base layers`).

### Task 7: REST routes and CLI commands

**Files:**
- Create: `mappulse/includes/class-rest-controller.php`
- Modify: `mappulse/includes/class-plugin.php` (register routes on `rest_api_init`), `mappulse/includes/class-cli.php`, `mappulse/mappulse.php` (require the new files)
- Test: `mappulse/tests/test-rest.php`

**Interfaces:**
- Consumes: `Service`, `Sources`.
- Produces, all under `mappulse/v1`, all with a permission callback requiring `manage_mappulse_maps`:
  - `GET /sources` returns each provider's `id`, `label`, `types`; `POST /sources` (`url`, `label`, `parent`) adds a layer; `DELETE /sources/(?P<id>[a-z0-9:-]+)` removes one.
  - `GET /boundaries/search` (`q` required, `type`, `state`) returns `results` and `errors`.
  - `GET /boundaries/fetch` (`source`, `record` required) returns the Feature.
  - `GET /boundaries/base` (`bbox` required) returns the six layers.
  - A `WP_Error` from the service becomes HTTP 400, except `mappulse_http` and `mappulse_source_error`, which become 502.
- CLI: `wp mappulse find <text> [--type=<type>] [--state=<state>] [--format=<table|json>]`, `wp mappulse shape <source> <record>` (prints name, type, ring and point counts, and the bounding box), `wp mappulse sources list`, `wp mappulse sources add <url> --label=<label> [--parent=<parent>]`, `wp mappulse cache flush`.

- [ ] **Step 1: Write the failing tests:**
  - `test_routes_are_registered`: the server's routes include `/mappulse/v1/boundaries/search`, `/mappulse/v1/boundaries/fetch`, `/mappulse/v1/boundaries/base`, `/mappulse/v1/sources`.
  - `test_visitors_and_editors_are_refused`: a logged-out request gets 401 and an editor gets 403 on each of the four routes.
  - `test_manager_search_returns_results`: an administrator's `GET /boundaries/search?q=gulfport&state=FL` returns 200 with one result named `Gulfport`.
  - `test_missing_query_is_a_400`.
  - `test_source_failure_is_a_502`: with every host answering 503, `GET /boundaries/fetch?source=census&record=place:1228175` returns 502.
  - `test_layer_can_be_added_and_removed_over_rest`.
- [ ] **Step 2: Run and see them fail** (`--filter Test_Rest`). Expected: FAIL, routes missing.
- [ ] **Step 3: Implement** the controller and the CLI commands.
- [ ] **Step 4: Run the whole suite.** Expected: all tests pass, none skipped except the five Elementor tests where Elementor is absent.
- [ ] **Step 5: Commit** (`Add mappulse/v1 boundary routes and CLI commands`).

### Task 8: The Sources screen

**Files:**
- Create: `mappulse/includes/class-sources-screen.php`, `mappulse/assets/css/mappulse-admin.css`
- Modify: `mappulse/includes/class-plugin.php` (register the screen in admin)
- Test: `mappulse/tests/test-sources-screen.php`

**Interfaces:**
- Consumes: `Service`, `Sources`.
- Produces: submenu "Sources" under MapPulse (`page=mappulse-sources`), three parts on one page:
  1. **Sources:** a table of providers (label, what it offers, address) with a Remove action on registered layers, and an "Add a neighborhood layer" form (`url`, `label`, `parent`) posting to `admin-post.php?action=mappulse_add_layer` with nonce `mappulse_sources`. The form's help text explains where to find such a URL (a city's open-data or GIS site, an ArcGIS REST "MapServer" or "FeatureServer" layer page) and gives the St. Petersburg layer as an example.
  2. **Find an area:** a GET form (`q`, `type`, `state`) whose results table shows name, type, parent and source, each row with a "Show boundary" link (`shape=<source>|<record>`). Provider failures are listed above the table by source name.
  3. **Boundary:** for a chosen result, an inline SVG outline (`Sources_Screen::outline_svg( array $feature, int $size = 420 ): string`, equirectangular projection scaled by the cosine of the middle latitude), its point count and bounding box, and a link that opens the same box on openstreetmap.org for a street-level check.
  Also a "Clear the boundary cache" button (`action=mappulse_flush_cache`).

- [ ] **Step 1: Write the failing tests:**
  - `test_outline_svg_draws_one_path_inside_the_box`: for a square Feature the result matches `/^<svg[^>]+viewBox="0 0 420 420"/`, holds exactly one `<path`, and every number in `d` is between 0 and 420.
  - `test_outline_svg_handles_multipolygons_and_holes`: a MultiPolygon with a hole yields a path with three `M` commands and `fill-rule="evenodd"`.
  - `test_search_results_are_listed_with_boundary_links`: with `$_GET` holding `q` `gulfport` and `state` `FL`, the rendered page contains `Gulfport`, `Florida` and `shape=census%7Cplace%3A1228175`.
  - `test_a_failing_source_is_named_on_the_page_and_the_page_still_renders`.
  - `test_adding_a_layer_needs_the_capability_and_the_nonce` (same structure as the import request tests: an editor is refused with "not allowed", a bad nonce is refused, a manager's valid request redirects with `mappulse_layer_added=1`).
  - `test_search_text_is_escaped_in_the_page`: `q` set to `"><script>x</script>` does not appear unescaped.
- [ ] **Step 2: Run and see them fail** (`--filter Test_Sources_Screen`). Expected: FAIL, class not found.
- [ ] **Step 3: Implement** the screen and its small stylesheet (loaded on this screen only).
- [ ] **Step 4: Run and see them pass**, then open the screen in a browser against a WordPress with the HTTP mock replaced by real requests where the network allows it, and confirm the page reads correctly at 1280 px and 782 px wide.
- [ ] **Step 5: Commit** (`Add the Sources screen`).

### Task 9: Live check, docs and 0.2.0

**Files:**
- Modify: `mappulse/tests/smoke.sh`, `mappulse/tests/smoke-setup.php`, `.github/workflows/plugin.yml`, `mappulse/mappulse.php`, `mappulse/readme.txt`, `mappulse/blocks/mappulse/block.json`, `mappulse/blocks/mappulse/index.asset.php`, `bin/build-zip.sh` (required-file list), `README.md`, `CHANGELOG.md`, `docs/data-sources.md`

- [ ] **Step 1: Add live checks to `smoke.sh`**, run before the uninstall check and skipped with a `SKIP` line when `MAPPULSE_LIVE` is not `1`:
  10. `wp mappulse find gulfport --state=FL --format=json` contains a result named `Gulfport` whose record starts with `place:12`.
  11. `wp mappulse shape census <that record>` reports at least 100 points and a bounding box whose west edge is between -82.8 and -82.6.
  12. `wp mappulse sources add <St. Petersburg layer URL> --label="St. Petersburg neighborhoods" --parent="St. Petersburg, FL"` succeeds, and `wp mappulse find kenwood --type=neighborhood --format=json` contains a name matching `KENWOOD`.
  13. An authenticated request to `/wp-json/mappulse/v1/boundaries/base?bbox=-82.75,27.72,-82.60,27.82` returns the six layers, with at least one feature in `water` and in `roadsMajor`. `smoke-setup.php` gains a `base` mode that makes this request in-process with `rest_do_request()` as an administrator and prints `base water=<n> roadsMajor=<n>`.
- [ ] **Step 2: Set `MAPPULSE_LIVE=1` in the end-to-end job** and push. Expected: checks 10 to 13 `PASS` on GitHub Actions; every earlier check still passes.
- [ ] **Step 3: Raise the version to 0.2.0** in every place named in Global Constraints, update `README.md` (a "Find boundaries" section and the status line), `readme.txt` and `CHANGELOG.md`, and add the allowed-host and cache notes to `docs/data-sources.md`.
- [ ] **Step 4: Build the zip** with `bash bin/build-zip.sh`, install it on a clean WordPress with `wp plugin install`, and run `smoke.sh` against it. Expected: all checks pass or skip; none fail.
- [ ] **Step 5: Commit** (`MapPulse 0.2.0`), push, confirm every job is green, and open the pull request.

## Not in this plan

- The map builder, the geometry pipeline inside the plugin, and the `/maps`, `/links/search` and `/render` routes (Plan C).
- OpenStreetMap as a source and uploaded boundary files (hand-drawn areas). Both sit behind the same `Provider` interface and come with the builder.
- Street tiles in the admin (Leaflet). The Sources screen links out to openstreetmap.org instead.
- The fourteen minor findings deferred from the 0.1.0 review, except the missing shortcode in the map list, which Task 1 covers.
