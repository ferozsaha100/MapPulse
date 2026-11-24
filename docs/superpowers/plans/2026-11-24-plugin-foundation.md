# MapPulse Plugin Foundation (0.1.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An installable MapPulse 0.1.0 that stores a map, renders it as inline SVG, and places it on a page through a shortcode, a Gutenberg block and an Elementor widget, with maps loaded from a map file until the builder exists.

**Architecture:** The Phase 0 spike exports a map document (JSON, `schema: 1`). The plugin validates that document, stores it in a private `mappulse_map` post, and one PHP renderer turns it into the section HTML. The three placements are thin wrappers that all call `mappulse_render_map()`.

**Tech Stack:** PHP 8.1+, WordPress 6.8+, PHPUnit 9.6 inside `@wordpress/env`, PHPCS with WordPress Coding Standards, plain JavaScript (no build step in this plan), Node 22 with `node --test` for the spike, GitHub Actions.

**Spec:** [`docs/development-plan.md`](../../development-plan.md), sections 4, 5, 7, 8, 9, 11 and 13.

This is the first of three plans for Phase 1. Each ends in working software:

| Plan | Delivers | Version |
| --- | --- | --- |
| A. Plugin foundation (this plan) | Map store, renderer, shortcode, block, Elementor widget, map file import | 0.1.0 |
| B. Boundary service | `mappulse/v1` REST routes, boundary providers with cache and throttle, the pipeline moved into the plugin with unit tests | 0.2.0 |
| C. Builder | Admin builder steps: Areas, base map, Style, Zones and links | 0.3.0 |

## Global Constraints

- Plugin folder and text domain: `mappulse`. Main file: `mappulse/mappulse.php`. PHP namespace: `MapPulse`.
- Header: `Requires at least: 6.8`, `Requires PHP: 8.1`, `Version: 0.1.0`, `License: GPL-2.0-or-later`. Constant `MAPPULSE_VERSION` equals the header version.
- Post type `mappulse_map`. Post meta `_mappulse_map` (document), `_mappulse_svg` (rendered SVG), `_mappulse_hash` (hash of the document the SVG came from).
- One capability, `manage_mappulse_maps`, granted to `administrator` on activation.
- Public function: `mappulse_render_map( int $map_id, array $args = array() ): string`.
- Shortcode `[mappulse id="12" layout="list-left"]`. Block name `agentview/mappulse`, `apiVersion: 3`. Elementor widget name `mappulse`.
- Layouts: `list-left` (default), `list-right`, `list-below`, `map-only`.
- All CSS targets classes that start with `mappulse`. Colors and sizes are CSS custom properties named `--mappulse-*`. No CSS depends on an Elementor or block wrapper.
- The SVG contains no `id` attributes, no `<style>`, no `<script>` and no event-handler attributes.
- Every attribute and URL is escaped on output. Zone text is plain text.
- Front end makes no third-party request. Front-end script is 15 KB or less gzipped, stylesheet 5 KB or less gzipped.
- Every user-facing string is translatable with the `mappulse` text domain.
- The version number is raised in the plugin header, `MAPPULSE_VERSION`, `readme.txt` and `CHANGELOG.md` whenever plugin code changes.

## Review Focus

1. The same map placed twice on one page: both must render and hover independently. Covered in Task 3.
2. A hostile or broken map file (script in a name, `javascript:` link, non-path characters in path data, wrong `schema`, oversized file): rejected or cleaned, never rendered raw. Covered in Tasks 2 and 5.
3. A map ID that does not exist, is a draft, or was trashed after being placed: visitors see nothing, a user with `manage_mappulse_maps` sees a short notice, and the page still loads. Covered in Task 4.
4. Elementor missing, deactivated, or running with atomic elements on: no fatal error, the other placements keep working. Covered in Tasks 7 and 8.
5. A zone with no link, or with a name far longer than its shape: still drawn, still listed, not a dead link. Covered in Task 3.

## Map document, schema 1 (as used by this plan)

The renderer draws only from `topology`. Source geometry (`zones[].geometry`, `base`) arrives with Plan B and is ignored here.

```json
{
  "schema": 1,
  "title": "St. Petersburg, FL",
  "frame": { "width": 1000, "height": 966 },
  "style": {
    "water": "#DCE8EA", "coast": "#B4C9CF", "land": "#F6F1E6",
    "context": "#EEE7D8", "contextStroke": "#FFFFFF",
    "zone": "#DCCFB6", "zoneStroke": "#FFFFFF", "zoneHover": "#1F3A5F",
    "label": "#2B2F36", "labelHover": "#FFFFFF", "waterLabel": "#3F6470",
    "road": "#FFFFFF", "roadCasing": "#BDB198",
    "labelFont": "Inter, Helvetica, Arial, sans-serif", "labelSize": 11.5
  },
  "zones": [
    { "id": "old-northeast", "name": "Old Northeast", "type": "neighborhood",
      "source": { "layer": "neighborhoods", "record": "HISTORIC OLD NORTHEAST NBRHD ASSN" },
      "link": "", "text": "" }
  ],
  "topology": {
    "layers": { "land": "M…Z", "cities": "M…Z", "areas": "M…Z", "water": "M…Z", "roadsMinor": "M…", "roadsMajor": "M…" },
    "zones": { "old-northeast": { "d": "M…Z", "label": { "x": 745.2, "y": 336.1, "size": 11.5, "lines": ["OLD", "NORTHEAST"], "anchor": null } } },
    "waterLabels": [ { "text": "TAMPA BAY", "x": 866, "y": 826, "vertical": false } ]
  },
  "meta": { "generator": "mappulse-spike/0.0.5", "synthetic": false }
}
```

`label.anchor` is `null` or `{ "x": n, "y": n }` when the label was moved off its zone and needs a leader line.

---

### Task 1: The spike exports the map document

**Files:**
- Create: `spike/src/export.mjs`, `spike/test/export.test.mjs`
- Modify: `spike/scripts/build.mjs` (write the file next to the SVG), `spike/package.json` (version `0.0.5`, script `"test": "node --test test/"`), `.github/workflows/spike.yml` (run `npm test` in the dry-run job; copy `out/st-petersburg.map.json` into `spike/results/st-petersburg/`), `CHANGELOG.md`

**Interfaces:**
- Produces: `toMapDocument( m ) -> object` in the shape above, where `m` is the object `build.mjs` already passes to `renderSvg()` plus `generator`. Output file: `spike/out/<slug>.map.json`.

- [ ] **Step 1: Write the failing test** in `spike/test/export.test.mjs`

```js
import test from 'node:test';
import assert from 'node:assert/strict';
import { toMapDocument } from '../src/export.mjs';

const m = {
  title: 'Test', frame: { width: 1000, height: 800 }, generator: 'mappulse-spike/0.0.5', synthetic: true,
  style: { water: '#DCE8EA', zone: '#DCCFB6', labelSize: 11.5 },
  land: [{ mp: [[[[0, 0], [10, 0], [10, 10], [0, 0]]]] }], contextBase: [], context: [],
  water: [], waterDrawn: [], roadsPrimary: [], roadsSecondary: [[[0, 0], [5, 5]]], waterLabels: [],
  zones: [{ name: 'Old Northeast', slug: 'old-northeast', type: 'neighborhood', layer: 'neighborhoods',
    sourceName: 'HISTORIC OLD NORTHEAST NBRHD ASSN', mp: [[[[1, 1], [9, 1], [9, 9], [1, 1]]]],
    lines: ['OLD', 'NORTHEAST'], fontSize: 10, label: { x: 5, y: 5 }, anchor: { x: 5, y: 5 }, leader: false }],
};

test('document has schema 1 and one topology entry per zone', () => {
  const doc = toMapDocument(m);
  assert.equal(doc.schema, 1);
  assert.deepEqual(doc.frame, { width: 1000, height: 800 });
  assert.deepEqual(doc.zones.map((z) => z.id), ['old-northeast']);
  assert.equal(doc.topology.zones['old-northeast'].d, 'M1 1L9 1L9 9Z');
  assert.deepEqual(doc.topology.zones['old-northeast'].label, { x: 5, y: 5, size: 10, lines: ['OLD', 'NORTHEAST'], anchor: null });
  assert.equal(doc.topology.layers.roadsMinor, 'M0 0L5 5');
  assert.equal(doc.meta.synthetic, true);
});

test('path data uses only path commands, digits, spaces, dots and minus signs', () => {
  const doc = toMapDocument(m);
  for (const d of [...Object.values(doc.topology.layers), doc.topology.zones['old-northeast'].d]) assert.match(d, /^[MLZ0-9 .\-]*$/);
});
```

- [ ] **Step 2: Run it and see it fail.** Run: `cd spike && node --test test/`. Expected: FAIL, `Cannot find module '../src/export.mjs'`.
- [ ] **Step 3: Implement `toMapDocument( m )` in `spike/src/export.mjs`**, reusing `pathOfMP` and `pathOfLines` from `svg.mjs`. Layer mapping: `land` from `m.land`, `cities` from `m.contextBase`, `areas` from `m.context`, `water` from `m.waterDrawn`, `roadsMinor` from `m.roadsSecondary`, `roadsMajor` from `m.roadsPrimary`. `label.anchor` is the zone's `anchor` only when `leader` is true. `link` and `text` are empty strings.
- [ ] **Step 4: Call it from `build.mjs`** and write `out/<slug>.map.json`. Run: `npm test && npm run sample && npm run build && node -e "JSON.parse(require('fs').readFileSync('out/st-petersburg.map.json','utf8'))"`. Expected: 2 tests pass, build exits 0, the file parses.
- [ ] **Step 5: Commit** (`Spike 0.0.5: export the map document`), push, and confirm the real-data job commits `spike/results/st-petersburg/st-petersburg.map.json`.

### Task 2: Plugin bootstrap and the document sanitizer

**Files:**
- Create: `mappulse/mappulse.php`, `mappulse/includes/class-plugin.php`, `mappulse/includes/class-map-document.php`, `mappulse/uninstall.php`
- Create (test harness): `mappulse/composer.json` (dev: `phpunit/phpunit ^9.6`, `yoast/phpunit-polyfills ^1.1`, `wp-coding-standards/wpcs ^3.1`; scripts `test`, `lint`), `mappulse/phpunit.xml.dist`, `mappulse/phpcs.xml.dist` (ruleset `WordPress-Extra`, text domain `mappulse`, prefixes `mappulse`, `MapPulse`), `mappulse/tests/bootstrap.php` (standard wp-env bootstrap that loads `mappulse.php` on `muplugins_loaded`), `mappulse/tests/fixtures/sample.map.json` (title `Test Market`, frame 1000 by 800, three zones in this order: `downtown` with a link, `old-northeast` named "Old Northeast" with an empty `link`, `historic-kenwood` with a 60-character name), `.wp-env.json` (plugins: `./mappulse` and the Elementor stable zip), `package.json` at the repo root (dev: `@wordpress/env`), `.github/workflows/plugin.yml` (jobs: `php -l` on PHP 8.1 and 8.4, PHPCS, PHPUnit in wp-env)
- Test: `mappulse/tests/test-map-document.php`

**Interfaces:**
- Produces: `MapPulse\Map_Document::sanitize( array $raw ): array|\WP_Error`, `Map_Document::defaults(): array` (the `style` block above), `Map_Document::hash( array $doc ): string` (md5 of `wp_json_encode( $doc )` plus `MAPPULSE_VERSION`), constants `MAPPULSE_VERSION`, `MAPPULSE_FILE`, `MAPPULSE_DIR`, `MAPPULSE_URL`, `MAPPULSE_MAX_IMPORT_BYTES` (2 MB), `MAPPULSE_MAX_ZONES` (200).

- [ ] **Step 1: Write the failing tests** (`Test_Map_Document extends WP_UnitTestCase`; `fixture()` returns the decoded sample file)

```php
public function test_valid_document_passes_and_keeps_zone_order() {
	$doc = Map_Document::sanitize( $this->fixture() );
	$this->assertIsArray( $doc );
	$this->assertSame( array( 'downtown', 'old-northeast', 'historic-kenwood' ), wp_list_pluck( $doc['zones'], 'id' ) );
}
public function test_wrong_schema_is_rejected() {
	$raw = $this->fixture(); $raw['schema'] = 2;
	$this->assertSame( 'mappulse_schema', Map_Document::sanitize( $raw )->get_error_code() );
}
public function test_path_data_with_other_characters_is_rejected() {
	$raw = $this->fixture(); $raw['topology']['zones']['downtown']['d'] = 'M0 0L5 5Z"/><script>alert(1)</script>';
	$this->assertSame( 'mappulse_bad_path', Map_Document::sanitize( $raw )->get_error_code() );
}
public function test_text_and_links_are_cleaned() {
	$raw = $this->fixture();
	$raw['zones'][0]['name'] = 'Down<script>x</script>town';
	$raw['zones'][0]['link'] = 'javascript:alert(1)';
	$raw['style']['zone']    = 'red;background:url(x)';
	$doc = Map_Document::sanitize( $raw );
	$this->assertSame( 'Downtown', $doc['zones'][0]['name'] );
	$this->assertSame( '', $doc['zones'][0]['link'] );
	$this->assertSame( '#DCCFB6', $doc['style']['zone'] );
}
public function test_duplicate_zone_ids_are_rejected() {
	$raw = $this->fixture(); $raw['zones'][1]['id'] = 'downtown';
	$this->assertSame( 'mappulse_duplicate_zone', Map_Document::sanitize( $raw )->get_error_code() );
}
public function test_zone_without_shape_is_rejected() {
	$raw = $this->fixture(); unset( $raw['topology']['zones']['downtown'] );
	$this->assertSame( 'mappulse_zone_shape', Map_Document::sanitize( $raw )->get_error_code() );
}
public function test_frame_outside_limits_is_rejected() {
	$raw = $this->fixture(); $raw['frame']['width'] = 90000;
	$this->assertSame( 'mappulse_frame', Map_Document::sanitize( $raw )->get_error_code() );
}
```

- [ ] **Step 2: Run and see them fail.** Run from the repo root: `npm install && (cd mappulse && composer install) && npx wp-env start && npx wp-env run tests-cli --env-cwd=wp-content/plugins/mappulse vendor/bin/phpunit --filter Test_Map_Document`. Expected: FAIL, class `MapPulse\Map_Document` not found.
- [ ] **Step 3: Implement** the bootstrap (header, constants, `require` of the classes, `MapPulse\Plugin::instance()` on `plugins_loaded`) and `Map_Document`. Rules: `schema` must be `1`; `frame.width` and `frame.height` between 100 and 4000; path data must match `/^[MmLlHhVvCcSsQqTtAaZz0-9 ,.\-]*$/`; colors through `sanitize_hex_color()` with the default on failure; text through `sanitize_text_field()`; links through `esc_url_raw()` limited to `http`, `https` and site-relative paths; zone ids through `sanitize_title()` and unique; label `lines` at most 4, each at most 40 characters; label `size` between 6 and 40; at most `MAPPULSE_MAX_ZONES` zones; unknown keys dropped.
- [ ] **Step 4: Run and see them pass.** Same command. Expected: 7 tests, 0 failures. Then `cd mappulse && composer lint`. Expected: no errors.
- [ ] **Step 5: Commit** (`Add plugin bootstrap and map document sanitizer`).

### Task 3: The renderer, stylesheet and view script

**Files:**
- Create: `mappulse/includes/class-renderer.php`, `mappulse/assets/css/mappulse.css`, `mappulse/assets/js/mappulse.js`, `mappulse/tests/fixtures/sample.svg` (snapshot, written once with `MAPPULSE_UPDATE_SNAPSHOTS=1` and reviewed by eye before it is committed)
- Modify: `mappulse/includes/class-plugin.php` (register style and script handles, both named `mappulse`, versioned with `MAPPULSE_VERSION`; the script loads in the footer with `defer`)
- Test: `mappulse/tests/test-renderer.php`

**Interfaces:**
- Consumes: a document returned by `Map_Document::sanitize()`.
- Produces: `MapPulse\Renderer::svg( array $doc ): string` (the `<svg>` element only, cacheable) and `Renderer::section( array $doc, string $svg, array $args ): string`. `$args`: `layout` (one of the four layouts), `heading` (string), `intro` (string), `show_list` (bool, default true; forced off by `map-only`).

Markup contract:

```html
<section class="mappulse mappulse--list-left" data-mappulse style="--mappulse-zone:#DCCFB6;…">
  <h2 class="mappulse__heading">…</h2><p class="mappulse__intro">…</p>
  <ul class="mappulse__list"><li><a href="…" data-zone="downtown">Downtown</a></li><li><span data-zone="old-northeast">Old Northeast</span></li></ul>
  <div class="mappulse__map"><svg class="mappulse__svg" viewBox="0 0 1000 966" role="group" aria-label="…">…</svg></div>
</section>
```

SVG group order, each a `<g class="mappulse__{name}">`: `land`, `cities`, `areas`, `zones`, `water`, `roads`, `leaders`, `labels`, `water-labels`. A linked zone is `<a class="mappulse__zone" href data-zone aria-label>` around its `<path>`. A zone with no link is `<g class="mappulse__zone mappulse__zone--static" tabindex="0" role="img" data-zone aria-label>`. Each label is `<text class="mappulse__label" data-zone>` with one `<tspan>` per line.

- [ ] **Step 1: Write the failing tests**

```php
public function test_svg_has_viewbox_and_one_shape_per_zone() {
	$svg = Renderer::svg( $this->doc() );
	$this->assertStringContainsString( 'viewBox="0 0 1000 800"', $svg );
	$this->assertSame( 3, substr_count( $svg, 'class="mappulse__zone' ) );
}
public function test_svg_has_no_ids_styles_scripts_or_handlers() {
	$svg = Renderer::svg( $this->doc() );
	$this->assertDoesNotMatchRegularExpression( '/\sid=|<style|<script|\son[a-z]+=/i', $svg );
}
public function test_groups_are_in_draw_order() {
	$svg = Renderer::svg( $this->doc() );
	$pos = array_map( fn( $g ) => strpos( $svg, 'class="mappulse__' . $g . '"' ), array( 'land', 'cities', 'areas', 'zones', 'water', 'roads', 'labels' ) );
	$sorted = $pos; sort( $sorted );
	$this->assertSame( $sorted, $pos );
	$this->assertNotContains( false, $pos, true );
}
public function test_zone_without_link_is_not_an_anchor() {
	$svg = Renderer::svg( $this->doc() );
	$this->assertStringContainsString( '<g class="mappulse__zone mappulse__zone--static" tabindex="0" role="img" data-zone="old-northeast"', $svg );
	$html = Renderer::section( $this->doc(), $svg, array() );
	$this->assertStringContainsString( '<span data-zone="old-northeast">Old Northeast</span>', $html );
}
public function test_layout_and_style_variables_are_on_the_section() {
	$html = Renderer::section( $this->doc(), Renderer::svg( $this->doc() ), array( 'layout' => 'list-right' ) );
	$this->assertStringContainsString( 'class="mappulse mappulse--list-right"', $html );
	$this->assertStringContainsString( '--mappulse-zone-hover:#1F3A5F', $html );
}
public function test_unknown_layout_falls_back_and_map_only_hides_the_list() {
	$svg = Renderer::svg( $this->doc() );
	$this->assertStringContainsString( 'mappulse--list-left', Renderer::section( $this->doc(), $svg, array( 'layout' => 'x"><b' ) ) );
	$this->assertStringNotContainsString( 'mappulse__list', Renderer::section( $this->doc(), $svg, array( 'layout' => 'map-only' ) ) );
}
public function test_two_sections_on_one_page_share_no_ids() {
	$svg  = Renderer::svg( $this->doc() );
	$page = Renderer::section( $this->doc(), $svg, array() ) . Renderer::section( $this->doc(), $svg, array() );
	$this->assertSame( 2, substr_count( $page, 'data-mappulse' ) );
	$this->assertDoesNotMatchRegularExpression( '/\sid=/', $page );
}
public function test_svg_matches_the_stored_snapshot() {
	$file = __DIR__ . '/fixtures/sample.svg';
	if ( getenv( 'MAPPULSE_UPDATE_SNAPSHOTS' ) ) { file_put_contents( $file, Renderer::svg( $this->doc() ) ); }
	$this->assertSame( file_get_contents( $file ), Renderer::svg( $this->doc() ) );
}
public function test_heading_and_long_names_are_escaped_not_cut() {
	$html = Renderer::section( $this->doc(), Renderer::svg( $this->doc() ), array( 'heading' => 'A <b>& B' ) );
	$this->assertStringContainsString( 'A &lt;b&gt;&amp; B', $html );
	$this->assertStringContainsString( $this->doc()['zones'][2]['name'], $html );
}
```

- [ ] **Step 2: Run and see them fail.** Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/mappulse vendor/bin/phpunit --filter Test_Renderer`. Expected: FAIL, class `MapPulse\Renderer` not found.
- [ ] **Step 3: Implement `Renderer`** to the markup contract. Style keys map to variables by kebab case (`zoneHover` to `--mappulse-zone-hover`).
- [ ] **Step 4: Write `mappulse.css`.** Rules use the variables only. `.mappulse__svg{display:block;width:100%;height:auto;max-height:var(--mappulse-max-height,none)}`; the list sits beside the map from 782 px up and below it under that; hover and `:focus-visible` on `.mappulse__zone` use `--mappulse-zone-hover`; `.is-active` gives the same look; `prefers-reduced-motion` removes transitions.
- [ ] **Step 5: Write `mappulse.js`** (no dependencies, no globals). For every `[data-mappulse]` root: on `pointerenter`, `pointerleave`, `focusin` and `focusout` of any `[data-zone]` element, toggle `is-active` on all elements in that root with the same `data-zone` value. Nothing else in this plan.
- [ ] **Step 6: Run and see them pass.** Expected: 9 tests, 0 failures. Check the budgets: `gzip -c mappulse/assets/js/mappulse.js | wc -c` is under 15360 and the stylesheet under 5120.
- [ ] **Step 7: Commit** (`Add renderer, stylesheet and view script`).

### Task 4: Map store and the public function

**Files:**
- Create: `mappulse/includes/class-map-post-type.php`, `mappulse/includes/functions.php`
- Modify: `mappulse/mappulse.php` (activation hook grants the capability), `mappulse/uninstall.php` (removes the capability; maps are kept)
- Test: `mappulse/tests/test-map-store.php`

**Interfaces:**
- Produces: `MapPulse\Map_Post_Type::register(): void` (private post type, `show_ui` true under a top-level "MapPulse" menu, supports `title` and `revisions`, every capability mapped to `manage_mappulse_maps`), `Map_Post_Type::save( array $doc, int $post_id = 0, string $status = 'publish' ): int|\WP_Error` (sanitizes, stores the three meta keys, returns the post ID), `Map_Post_Type::get( int $post_id ): ?array`, `Map_Post_Type::svg( int $post_id ): string` (returns the cached SVG, rebuilding it when `_mappulse_hash` no longer matches), and `mappulse_render_map( int $map_id, array $args = array() ): string`, which also enqueues the `mappulse` style and script.

- [ ] **Step 1: Write the failing tests**

```php
public function test_save_stores_document_svg_and_hash() {
	$id = Map_Post_Type::save( $this->fixture() );
	$this->assertSame( 'mappulse_map', get_post_type( $id ) );
	$this->assertStringStartsWith( '<svg', get_post_meta( $id, '_mappulse_svg', true ) );
	$this->assertSame( Map_Document::hash( Map_Post_Type::get( $id ) ), get_post_meta( $id, '_mappulse_hash', true ) );
}
public function test_invalid_document_creates_no_post() {
	$raw = $this->fixture(); $raw['schema'] = 9;
	$this->assertWPError( Map_Post_Type::save( $raw ) );
	$this->assertCount( 0, get_posts( array( 'post_type' => 'mappulse_map', 'post_status' => 'any' ) ) );
}
public function test_stale_svg_is_rebuilt() {
	$id = Map_Post_Type::save( $this->fixture() );
	update_post_meta( $id, '_mappulse_hash', 'stale' );
	update_post_meta( $id, '_mappulse_svg', '<svg>old</svg>' );
	$this->assertStringContainsString( 'mappulse__zone', Map_Post_Type::svg( $id ) );
}
public function test_render_outputs_section_and_enqueues_assets() {
	$html = mappulse_render_map( Map_Post_Type::save( $this->fixture() ) );
	$this->assertStringContainsString( 'data-mappulse', $html );
	$this->assertTrue( wp_style_is( 'mappulse', 'enqueued' ) );
	$this->assertTrue( wp_script_is( 'mappulse', 'enqueued' ) );
}
public function test_missing_draft_and_trashed_maps_render_nothing_for_visitors() {
	$draft = Map_Post_Type::save( $this->fixture(), 0, 'draft' );
	$gone  = Map_Post_Type::save( $this->fixture() ); wp_trash_post( $gone );
	foreach ( array( 999999, $draft, $gone, 0 ) as $id ) { $this->assertSame( '', mappulse_render_map( $id ) ); }
}
public function test_map_managers_see_a_notice_instead() {
	wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	$this->assertStringContainsString( 'class="mappulse-notice"', mappulse_render_map( 999999 ) );
}
public function test_only_administrators_get_the_capability() {
	do_action( 'activate_' . plugin_basename( MAPPULSE_FILE ) );
	$this->assertTrue( get_role( 'administrator' )->has_cap( 'manage_mappulse_maps' ) );
	$this->assertFalse( get_role( 'editor' )->has_cap( 'manage_mappulse_maps' ) );
}
```

- [ ] **Step 2: Run and see them fail** (`--filter Test_Map_Store`). Expected: FAIL, class not found.
- [ ] **Step 3: Implement** the post type, the store methods and `mappulse_render_map()`.
- [ ] **Step 4: Run the whole suite.** Run: `npx wp-env run tests-cli --env-cwd=wp-content/plugins/mappulse vendor/bin/phpunit`. Expected: 23 tests, 0 failures.
- [ ] **Step 5: Commit** (`Add map store and mappulse_render_map()`).

### Task 5: Import a map file

**Files:**
- Create: `mappulse/includes/class-importer.php`, `mappulse/includes/class-cli.php`, `mappulse/samples/st-petersburg.map.json` (copied from `spike/results/st-petersburg/st-petersburg.map.json` produced in Task 1)
- Modify: `mappulse/includes/class-plugin.php` (admin submenu "Import" under "MapPulse"; register the CLI command when `WP_CLI` is defined)
- Test: `mappulse/tests/test-importer.php`

**Interfaces:**
- Consumes: `Map_Post_Type::save()`.
- Produces: `MapPulse\Importer::from_json( string $json, string $title = '' ): int|\WP_Error`, `Importer::from_file( string $path, string $title = '' ): int|\WP_Error`, `Importer::sample_path(): string`, `Importer::handle_request(): void` (the `admin_post_mappulse_import` handler; checks the capability, then the nonce, then the upload). Admin screen with a file field and an "Import the sample map" button, both posting to `admin-post.php?action=mappulse_import` with nonce `mappulse_import`. After a successful import it redirects to the map list with a notice that shows the shortcode. CLI: `wp mappulse import <file> [--title=<title>] [--porcelain]` and `wp mappulse import --sample`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_sample_map_imports_and_renders() {
	$id = Importer::from_file( Importer::sample_path() );
	$this->assertIsInt( $id );
	$this->assertGreaterThanOrEqual( 16, substr_count( mappulse_render_map( $id ), 'class="mappulse__zone' ) );
}
public function test_title_defaults_to_the_document_title() {
	$this->assertSame( 'Test Market', get_the_title( Importer::from_json( wp_json_encode( $this->fixture() ) ) ) );
}
public function test_broken_json_is_rejected() {
	$this->assertSame( 'mappulse_json', Importer::from_json( '{"schema":1,' )->get_error_code() );
}
public function test_oversized_file_is_rejected() {
	$this->assertSame( 'mappulse_too_large', Importer::from_json( str_repeat( ' ', MAPPULSE_MAX_IMPORT_BYTES + 1 ) )->get_error_code() );
}
public function test_import_request_needs_capability_and_nonce() {
	wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	$_POST = array( 'mappulse_sample' => '1', '_wpnonce' => wp_create_nonce( 'mappulse_import' ) );
	$this->expectException( 'WPDieException' );
	( new Importer() )->handle_request();
}
```

- [ ] **Step 2: Run and see them fail** (`--filter Test_Importer`). Expected: FAIL, class not found.
- [ ] **Step 3: Implement** the importer, the admin screen and the CLI command.
- [ ] **Step 4: Run and see them pass**, then check the CLI: `npx wp-env run cli wp mappulse import --sample --porcelain`. Expected: 5 tests pass; the command prints a post ID.
- [ ] **Step 5: Commit** (`Add map file import with a sample map`).

### Task 6: Shortcode and Gutenberg block

**Files:**
- Create: `mappulse/includes/class-shortcode.php`, `mappulse/blocks/mappulse/block.json`, `mappulse/blocks/mappulse/render.php`, `mappulse/blocks/mappulse/index.js`, `mappulse/blocks/mappulse/index.asset.php`
- Modify: `mappulse/includes/class-plugin.php` (register both on `init`)
- Test: `mappulse/tests/test-placements.php`

**Interfaces:**
- Consumes: `mappulse_render_map()`.
- Produces: shortcode attributes `id`, `layout`, `heading`, `intro`, `list` (`yes` or `no`). Block attributes `mapId` (number), `layout` (string), `heading`, `intro` (strings), `showList` (boolean, default true); supports `align` wide and full, `spacing` margin and padding, `anchor`; `style` and `viewScript` point at the `mappulse` handles. The editor script is hand-written JavaScript with no JSX and no build step: a map picker (`SelectControl` fed by `wp_add_inline_script` data listing published maps), a layout picker, heading and intro fields, a list toggle, and `ServerSideRender` for the preview. It moves into the `wp-scripts` build in Plan C.

- [ ] **Step 1: Write the failing tests**

```php
public function test_shortcode_renders_the_map_with_its_layout() {
	$html = do_shortcode( '[mappulse id="' . $this->map_id . '" layout="list-below"]' );
	$this->assertStringContainsString( 'mappulse--list-below', $html );
}
public function test_shortcode_without_a_valid_id_outputs_nothing() {
	$this->assertSame( '', do_shortcode( '[mappulse]' ) );
	$this->assertSame( '', do_shortcode( '[mappulse id="abc"]' ) );
}
public function test_block_is_registered_with_server_render() {
	$block = WP_Block_Type_Registry::get_instance()->get_registered( 'agentview/mappulse' );
	$this->assertNotNull( $block );
	$this->assertSame( 3, $block->api_version );
	$this->assertArrayHasKey( 'mapId', $block->attributes );
}
public function test_block_markup_renders_the_same_section_as_the_shortcode() {
	$block = do_blocks( '<!-- wp:agentview/mappulse {"mapId":' . $this->map_id . ',"layout":"map-only","align":"wide"} /-->' );
	$this->assertStringContainsString( 'mappulse--map-only', $block );
	$this->assertStringContainsString( 'alignwide', $block );
	$this->assertSame( substr_count( do_shortcode( '[mappulse id="' . $this->map_id . '"]' ), 'mappulse__zone' ), substr_count( $block, 'mappulse__zone' ) );
}
```

- [ ] **Step 2: Run and see them fail** (`--filter Test_Placements`). Expected: FAIL, shortcode output is the raw tag and the block is not registered.
- [ ] **Step 3: Implement** the shortcode and the block. `render.php` wraps the section in a `<div>` carrying `get_block_wrapper_attributes()`.
- [ ] **Step 4: Run and see them pass.** Expected: 4 tests pass. Then open the block editor in wp-env (`http://localhost:8888/wp-admin/post-new.php`), insert "MapPulse Map", pick the sample map, and confirm the preview appears with no console error.
- [ ] **Step 5: Commit** (`Add shortcode and Gutenberg block`).

### Task 7: Elementor widget

**Files:**
- Create: `mappulse/includes/integrations/class-elementor.php` (the loader), `mappulse/includes/integrations/class-elementor-widget.php`
- Modify: `mappulse/includes/class-plugin.php` (hook the loader to `elementor/widgets/register`)
- Test: `mappulse/tests/test-elementor.php` (skipped with a message when Elementor is not loaded)

**Interfaces:**
- Consumes: `mappulse_render_map()`.
- Produces: `MapPulse\Integrations\Elementor_Widget extends \Elementor\Widget_Base` with `get_name(): 'mappulse'`, title "MapPulse Map", icon `eicon-google-maps`, category `general`, keywords `map`, `neighborhood`, `area`, `real estate`, and `get_style_depends()` and `get_script_depends()` both returning `array( 'mappulse' )`. Content controls: `map_id` (SELECT of published maps), `layout` (SELECT), `heading` (TEXT), `intro` (TEXTAREA), `show_list` (SWITCHER, default `yes`). Style controls, each a COLOR control whose selector `{{WRAPPER}} .mappulse` sets one variable: `zone_color` to `--mappulse-zone`, `zone_hover_color` to `--mappulse-zone-hover`, `label_color` to `--mappulse-label`, `water_color` to `--mappulse-water`, `land_color` to `--mappulse-land`; plus `max_height` (SLIDER, units `px` and `vh`) setting `--mappulse-max-height`. `render()` echoes `mappulse_render_map()`; with no map chosen it prints a prompt in the editor and nothing on the front end. The widget file is required only inside the `elementor/widgets/register` callback.

- [ ] **Step 1: Write the failing tests**

```php
public function test_widget_is_registered() {
	$this->assertArrayHasKey( 'mappulse', \Elementor\Plugin::$instance->widgets_manager->get_widget_types() );
}
public function test_widget_declares_the_shared_assets() {
	$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( 'mappulse' );
	$this->assertSame( array( 'mappulse' ), $widget->get_style_depends() );
	$this->assertSame( array( 'mappulse' ), $widget->get_script_depends() );
}
public function test_widget_class_is_not_loaded_before_elementor_asks_for_it() {
	$this->assertFalse( $GLOBALS['mappulse_widget_loaded_early'] );
}
```

- [ ] **Step 2: Load Elementor in the test bootstrap** when it is installed (`WP_PLUGIN_DIR . '/elementor/elementor.php'` on `muplugins_loaded`, after MapPulse), and on `plugins_loaded` at priority 999 set `$GLOBALS['mappulse_widget_loaded_early']` to `class_exists( 'MapPulse\\Integrations\\Elementor_Widget', false )`.
- [ ] **Step 3: Run and see them fail** (`--filter Test_Elementor`). Expected: FAIL, key `mappulse` missing.
- [ ] **Step 4: Implement** the loader and the widget.
- [ ] **Step 5: Run and see them pass.** Expected: 3 tests pass. Then open a page in the Elementor editor in wp-env, drop "MapPulse Map", pick the sample map, change the hover color, and confirm the preview updates.
- [ ] **Step 6: Commit** (`Add Elementor widget`).

### Task 8: End-to-end check, packaging and release

**Files:**
- Create: `mappulse/tests/smoke.sh`, `mappulse/readme.txt`, `mappulse/.distignore` (excludes `tests`, `vendor`, `composer.*`, `phpunit.xml.dist`, `phpcs.xml.dist`, `.distignore`), `.github/workflows/release.yml`
- Modify: `.github/workflows/plugin.yml` (add the smoke job and a `mappulse-plugin` zip artifact on every push), `README.md` (status, install steps, the three placements), `CHANGELOG.md` (0.1.0)

**Interfaces:**
- Produces: `mappulse.zip` with a single top-level `mappulse/` folder. `release.yml` runs on tags matching `v*`, builds the zip, and publishes a GitHub pre-release with the zip attached.

- [ ] **Step 1: Write `smoke.sh`** against a running wp-env. It exits non-zero on the first failed check and prints one `PASS` or `FAIL` line per check:
  1. `wp plugin is-active mappulse`.
  2. `wp mappulse import --sample --porcelain` returns a number.
  3. A page with `[mappulse id="N"]`, fetched with `curl`, returns HTTP 200 and contains `data-mappulse` and at least 16 `mappulse__zone`.
  4. A page whose content is the block comment for `agentview/mappulse` passes the same check.
  5. A page with `_elementor_edit_mode` set to `builder` and `_elementor_data` holding one container with one `mappulse` widget passes the same check.
  6. Check 5 again after turning on Elementor's atomic elements experiment (read its key from `wp elementor experiments status` and record it in the script as `ATOMIC_EXPERIMENT`).
  7. The page from check 3 contains the string `mappulse.css` exactly once and no `<script` inside the `<svg>`.
  8. After `wp plugin deactivate elementor`, the pages from checks 3 and 4 still pass and `wp eval 'echo 1;'` prints `1` with no PHP error in `wp-content/debug.log`.
  9. After `wp plugin deactivate mappulse && wp plugin uninstall mappulse --skip-delete`, the `administrator` role no longer has `manage_mappulse_maps`.
- [ ] **Step 2: Run it.** Run: `npx wp-env start && bash mappulse/tests/smoke.sh`. Expected: nine `PASS` lines, exit code 0.
- [ ] **Step 3: Add the CI jobs and the release workflow**, push, and confirm every job on the commit reports `success`.
- [ ] **Step 4: Build and inspect the zip.** Download the `mappulse-plugin` artifact (or run the workflow's zip step locally) and run `unzip -l mappulse.zip`. Expected: every path starts with `mappulse/`; no `tests/` or `vendor/` entries; `mappulse/samples/st-petersburg.map.json` present.
- [ ] **Step 5: Update `README.md`, `readme.txt` and `CHANGELOG.md`**, commit (`MapPulse 0.1.0`), open the pull request, and tag `v0.1.0` once it is merged.

## Not in this plan

- The admin builder, REST routes and boundary providers (Plans B and C).
- Hover cards, phone pan and zoom, the bottom sheet (Phase 3).
- Decoration, the icon library and SVG artwork upload (Phase 2).
- Typography and list color controls, and the card and phone options listed for the widget and block in the spec, which depend on those features.
