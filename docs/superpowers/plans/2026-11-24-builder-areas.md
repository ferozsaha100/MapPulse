# MapPulse Builder, Part 1: Areas (0.3.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In MapPulse 0.3.0 a site admin builds a map inside WordPress: search real areas, confirm each on a live street map, see the finished map drawn, save it, and place it with the existing widget, block or shortcode.

**Architecture:** The geometry pipeline from `spike/src/pipeline.mjs` moves into the plugin as a tested JavaScript library that runs in the browser and turns confirmed boundaries plus base-map layers into the same schema 1 map document the renderer already draws. A React admin app (the builder) calls the existing boundary routes, runs that library, and saves through new `/maps` routes. PHP keeps doing only two things: store the document and render it. A new clean-outline step turns fragmented Census shapes into one solid outline before a boundary becomes a zone.

**Tech Stack:** `@wordpress/scripts` 37 (webpack, Jest, ESLint), React through `@wordpress/element`, `@wordpress/components`, `@wordpress/api-fetch`, Leaflet 1.9 bundled from npm (admin only), `polygon-clipping`, `topojson-server`/`-client`/`-simplify`, `polylabel`, `clipper-lib` 6.4 (polygon offsetting), PHP 8.1, PHPUnit 9.6, Playwright for one end-to-end run.

**Spec:** [`docs/development-plan.md`](../../development-plan.md), sections 2, 5, 6, 10, 11 and 13, and [`docs/data-sources.md`](../../data-sources.md).

This is plan C1. Plans A (0.1.0) and B (0.2.1) are merged. The builder has five steps in the spec; this plan delivers the shell and the first step, Areas, including the base map. Plan C2 (0.4.0) adds the Style step and the Zones and links step with `/links/search`.

**Not in this plan:** drawing a zone by hand, uploading GeoJSON or KML, editing points, snapping shared borders (pipeline step 5), the Style, Decorate, Zones and links and Publish steps, style presets. A map built in 0.3.0 uses the default style and its zones have no links yet: it shows zones, labels and the area list. Links and styling arrive in 0.4.0.

## Global Constraints

- Version `0.3.0` in the plugin header, `MAPPULSE_VERSION`, `readme.txt` (`Stable tag`), `blocks/mappulse/block.json`, `blocks/mappulse/index.asset.php`, `CHANGELOG.md` and the README status line.
- JavaScript source lives in `mappulse/src/` (`geometry/` for the library, `builder/` for the app). Built files go to `mappulse/build/`, which is git-ignored and built by CI and by `bin/build-zip.sh`. The zip ships `build/` and never `src/`, `node_modules/` or package files.
- The geometry library has no WordPress, DOM or network dependency. It is plain functions over arrays, so Jest runs it in Node.
- The front end gains no JavaScript, no CSS and no third-party request. Leaflet and street tiles load only on the builder screen.
- Street tiles come from `https://tile.openstreetmap.org/{z}/{x}/{y}.png` with the "© OpenStreetMap contributors" credit shown, filterable through `mappulse_tile_url` and `mappulse_tile_attribution`.
- Every new REST route is under `mappulse/v1`, requires `manage_mappulse_maps`, and validates its input. The builder sends the REST nonce through `@wordpress/api-fetch`.
- The stored document stays `schema: 1`. New keys are optional, so every 0.1.0 and 0.2.x map still loads and renders byte-for-byte the same.
- Source geometry is never altered in storage. Clean outline and every other pipeline step write only to `topology`.
- Limits: at most `MAPPULSE_MAX_ZONES` zones; one zone's stored source geometry at most 400 KB of JSON; a whole document at most 4 MB. Over a limit, the save is refused with a message that names the zone.
- Default priority order: `neighborhood`, `city`, `zip`, `county`. Area types stay exactly those four.
- Every user-facing string is translatable with the `mappulse` text domain. PHPCS and ESLint stay clean.

## Review Focus

- A place whose Census shape is many separate pieces (Fisher Island, FL: 14 polygons, a hollow frame with blocks inside): the zone must come out as one solid shape, and a normal city must come out unchanged within 1% of its area.
- A real group of islands (several parts far apart): clean outline must not bridge open water between parts wider than the closing distance.
- Two confirmed zones where one fully contains the other (a ZIP area and a neighborhood inside it): the lower-priority zone keeps drawing with the hole, and marking it a backdrop draws it whole underneath.
- A boundary source that fails or times out while the builder is open: the zone list and unsaved work survive, and the error is shown next to the action that failed.
- A user without `manage_mappulse_maps` (an Editor): no builder menu, and every new route answers 403.

---

### Task 1: JavaScript toolchain

**Files:**
- Create: `mappulse/package.json`, `mappulse/.eslintrc.json`, `mappulse/src/geometry/index.js`, `mappulse/src/geometry/test/smoke.test.js`
- Modify: `.gitignore`, `mappulse/.distignore`, `bin/build-zip.sh`, `.github/workflows/plugin.yml`

**Interfaces:**
- Produces: `npm run build` (in `mappulse/`) writes `build/builder.js`, `build/builder.css`, `build/builder.asset.php`; `npm run test:js` runs Jest; `npm run lint:js` runs ESLint. Entry point `src/builder/index.js` (created empty here, filled in Task 7).

- [ ] **Step 1:** Write `src/geometry/test/smoke.test.js`: `expect( typeof require( '../index' ).VERSION ).toBe( 'string' )`.
- [ ] **Step 2:** Run `npm run test:js`. Expected: FAIL, no `package.json` script, then after adding the script, "Cannot find module '../index'".
- [ ] **Step 3:** Add `package.json` (scripts `build`: `wp-scripts build src/builder/index.js --output-path=build`, `start`, `test:js`: `wp-scripts test-unit-js`, `lint:js`: `wp-scripts lint-js src`; dependencies listed in Tech Stack), `src/geometry/index.js` exporting `VERSION`, an empty `src/builder/index.js`.
- [ ] **Step 4:** Run `npm run test:js && npm run lint:js && npm run build`. Expected: 1 test passes, lint clean, `build/builder.asset.php` exists.
- [ ] **Step 5:** `bin/build-zip.sh` runs `npm ci && npm run build` in `mappulse/` first, requires `mappulse/build/builder.js` and `builder.asset.php` in the zip, and fails if the zip holds `src/`, `node_modules/` or `package.json`. Add those to `.distignore`; add `mappulse/build/` and `mappulse/node_modules/` to `.gitignore`.
- [ ] **Step 6:** Add a CI job "JavaScript" (Node 22: `npm ci`, `lint:js`, `test:js`, `build`). The PHPUnit and end-to-end jobs run `npm ci && npm run build` in `mappulse/` before `wp-env start`.
- [ ] **Step 7:** Run `bash bin/build-zip.sh`. Expected: "Built ... mappulse.zip". Commit.

### Task 2: Geometry library, ported from the spike

**Files:**
- Create: `mappulse/src/geometry/shapes.js`, `project.js`, `overlaps.js`, `simplify.js`, `labels.js`, `paths.js`, and `test/*.test.js` beside them; `mappulse/src/geometry/test/fixtures/st-pete-zones.json` (three real St. Petersburg shapes cut from `spike/data` sample output), `squares.json`
- Reference only: `spike/src/pipeline.mjs`, `spike/src/svg.mjs`, `spike/test/`

**Interfaces:**
- Produces (a multipolygon `MP` is `number[][][][]`, GeoJSON MultiPolygon coordinates):
  - `shapes.js`: `toMP( geometry ): MP`, `toLines( geometry ): number[][][]`, `mapMP( mp, fn ): MP`, `mpArea( mp ): number`, `mpBbox( mp ): [w,s,e,n]`, `boxesTouch( a, b ): boolean`, `unionAll( mps ): MP`, `subtract( mp, clips ): MP`, `clipToFrame( mp, width, height ): MP`
  - `project.js`: `makeProjection( lat0 ): ( [lon,lat] ) => [x,y]`, `fitFrame( box, { width = 1000, padding = 0.07, minAspect = 1, maxAspect = 1.6 } ): { width, height, scale, toFrame( [x,y] ) }`
  - `overlaps.js`: `resolveOverlaps( zones, priority ): zones` (each zone gains `cutShare`, `parts`; a zone with `backdrop: true` is neither cut nor used to cut)
  - `simplify.js`: `simplifyTogether( layers, { quantization = 1e5, minAreaPx = 0.9 } )`
  - `labels.js`: `labelPoint( mp ): {x,y}`, `labelLines( name ): string[]`, `separateLabels( items, { width, height } )`
  - `paths.js`: `pathOfMP( mp ): string`, `pathOfLines( lines ): string`. Output must match `Map_Document::PATH_PATTERN` and use one decimal place.

- [ ] **Step 1:** Write the tests first, one file per module. Required cases: two overlapping unit squares with types `neighborhood` and `zip` leave the `zip` square with area 0.5 and `cutShare` 0.5; the same pair with `backdrop: true` on the `zip` square leaves both at area 1; `simplifyTogether` on two squares sharing an edge returns shapes whose shared edge has identical coordinates in both; `pathOfMP` output matches `/^[MLZ0-9 .\-]*$/`; `fitFrame` on a 2:1 box returns `width` 1000 and `height` 625 (aspect capped at 1.6); `labelPoint` of an L-shape lies inside it; the three St. Petersburg fixtures after `resolveOverlaps` have pairwise intersection area 0.
- [ ] **Step 2:** Run `npm run test:js`. Expected: FAIL, modules missing.
- [ ] **Step 3:** Port each function from the spike into its module, as CommonJS-free ES modules. Add the `backdrop` rule to `resolveOverlaps`. No behavior change otherwise.
- [ ] **Step 4:** Run `npm run test:js && npm run lint:js`. Expected: all pass. Commit.

### Task 3: Clean outline

**Files:**
- Create: `mappulse/src/geometry/clean.js`, `test/clean.test.js`, `test/fixtures/fisher-island.json` (the live Census record `cdp:1222375`, layer 30, 5 decimal places: 14 polygons), `test/fixtures/gulfport.json` (live `place:1228175`), `test/fixtures/two-islands.json` (two squares 600 m apart)

**Interfaces:**
- Consumes: `shapes.js`, `project.js`
- Produces: `cleanOutline( mp, { closeMeters = 60, minHoleShare = 1 } = {} ): { mp: MP, changed: boolean, partsBefore: number, partsAfter: number }`. Input and output are in degrees. Method: project to meters, offset outward by `closeMeters` and back inward by the same distance with `clipper-lib` (round joins), union, then drop every hole whose area is under `minHoleShare` of the shape's area (default drops all holes), unproject. `needsCleaning( mp ): boolean` is true when the shape has more than one part or any hole.

- [ ] **Step 1:** Write the tests: Fisher Island returns `partsAfter` 1, no holes, `changed` true, and an area between 1.0 and 1.6 times the input's summed area; Gulfport returns area within 1% of the input and the same part count; `two-islands` keeps 2 parts; a square with a square hole returns no hole; `needsCleaning` is true for Fisher Island and false for a plain square; an empty input returns `{ mp: [], changed: false, partsBefore: 0, partsAfter: 0 }`.
- [ ] **Step 2:** Run `npm run test:js -- clean`. Expected: FAIL, "Cannot find module '../clean'".
- [ ] **Step 3:** Implement `clean.js`.
- [ ] **Step 4:** Run `npm run test:js`. Expected: all pass. If Fisher Island does not close at 60 m, raise the default until it does and Gulfport and `two-islands` still pass, and record the value and why in the ledger. Commit.

### Task 4: Document builder

**Files:**
- Create: `mappulse/src/geometry/build-document.js`, `test/build-document.test.js`, `test/fixtures/base-small.json` (a small `/boundaries/base` answer)
- Create: `mappulse/tests/fixtures/built-document.json` (written by the Jest test when `UPDATE_FIXTURES=1`), `mappulse/tests/test-built-document.php`

**Interfaces:**
- Consumes: Tasks 2 and 3. Base layers as `Service::base()` returns them: `{ land, cities, areas, water, roadsMajor, roadsMinor }`, each a GeoJSON FeatureCollection.
- Produces: `buildDocument( { title, zones, base, priority, style } ): { doc, warnings }` where each input zone is `{ id, name, type, source, record, feature, backdrop, clean }` (`feature` is the fetched GeoJSON Feature) and `doc` is a schema 1 document: `frame`, `style`, `zones[]` (`id`, `name`, `type`, `source: { layer, record }`, `link: ''`, `text: ''`, plus new optional `geometry`, `backdrop`, `clean`), `topology` (`layers`, `zones`, `waterLabels`) shaped exactly as `spike/src/export.mjs` writes it, `meta: { generator: 'mappulse-builder/0.3.0' }`. Order of work: clean (when `zone.clean`), project, fit frame to zones, resolve overlaps, clip to frame and to land, simplify together, place and separate labels, write paths. `warnings[]` items are `{ zone, code, message }` with codes `in_pieces` (more than one part after cutting), `mostly_cut` (`cutShare` over 0.8), `tiny` (under 60 square frame units).
- Produces: `bboxForBase( zones ): [w,s,e,n]`, the zones' box grown by 15% and capped at 1.5 degrees a side.

- [ ] **Step 1:** Write the Jest tests: three St. Petersburg zones plus `base-small` give a doc with 3 `topology.zones`, each `d` non-empty and matching the path pattern, every label point inside the frame, `frame.width` 1000; a ZIP zone fully covering a neighborhood yields no warning for the neighborhood and the ZIP zone still has a non-empty `d`; a zone cut to nothing yields `mostly_cut`; Fisher Island with `clean: true` has a `d` with exactly one `M`; with `clean: false` it has 14 or more; no zones returns `{ doc: null, warnings: [] }`.
- [ ] **Step 2:** Write `test-built-document.php`: `Map_Document::sanitize()` accepts `fixtures/built-document.json` without error, and `Renderer::svg()` of it contains 3 zone groups.
- [ ] **Step 3:** Run both suites. Expected: FAIL (module missing; fixture missing).
- [ ] **Step 4:** Implement `build-document.js`; write the fixture with `UPDATE_FIXTURES=1 npm run test:js`.
- [ ] **Step 5:** Run `npm run test:js` and `wpunit`. Expected: all pass. Commit.

### Task 5: Document schema accepts source geometry

**Files:**
- Modify: `mappulse/includes/class-map-document.php`, `mappulse/tests/test-map-document.php`

**Interfaces:**
- Produces: `Map_Document::sanitize()` keeps, per zone, optional `geometry` (a GeoJSON `Polygon` or `MultiPolygon` object: only `type` and numeric `coordinates` survive, at most 400 KB encoded), `backdrop` (bool), `clean` (bool), and at the top level optional `priority` (a permutation of the four area types; anything else becomes the default). Returns `WP_Error( 'mappulse_zone_too_large' )` naming the zone when over the limit, and `WP_Error( 'mappulse_too_large' )` when the encoded document is over 4 MB. `Map_Document::hash()` ignores none of the new keys.

- [ ] **Step 1:** Write tests: a zone geometry with an extra `"crs"` key and a string coordinate loses the key and is rejected for the string; a 500 KB geometry returns `mappulse_zone_too_large` with the zone name in the message; `backdrop: "yes"` becomes `true`; `priority: ["zip","zip"]` becomes the default order; the sample map sanitizes to the same hash as before this change.
- [ ] **Step 2:** Run `wpunit --filter Map_Document`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `wpunit` and `wplint`. Expected: all pass, 0 errors. Commit.

### Task 6: Maps REST routes

**Files:**
- Create: `mappulse/includes/class-maps-controller.php`, `mappulse/tests/test-maps-controller.php`
- Modify: `mappulse/includes/class-plugin.php`, `mappulse/mappulse.php` (require list)

**Interfaces:**
- Consumes: `Map_Post_Type::save( array $doc, int $post_id = 0, string $status = 'publish' )`, `::get( int $post_id ): ?array`, `Map_Document::sanitize()`, `Renderer::svg( array $doc )`.
- Produces, all under `mappulse/v1`, all `manage_mappulse_maps`:
  - `GET /maps` → `[ { id, title, status, zones: int, modified } ]`
  - `POST /maps` body `{ document, status: 'draft'|'publish' }` → 201 `{ id, title, status, document, shortcode }`
  - `GET /maps/<id>` → the same shape; 404 for a missing or non-map post
  - `PUT /maps/<id>` → 200, same shape
  - `DELETE /maps/<id>` → moves the map to the trash, `{ deleted: true }`
  - `POST /render` body `{ document }` → `{ svg }` for an unsaved document, nothing stored
  - A document that fails `sanitize()` answers 400 with the `WP_Error` code and message.

- [ ] **Step 1:** Write tests: an Editor gets 403 on every route and a logged-out request 401; an Administrator creates a map from `fixtures/built-document.json` and reads it back with the same hash; `PUT` with a changed title updates the post title; a document with a bad zone path returns 400 carrying the error code `sanitize()` returns for it; `DELETE` trashes rather than deletes; `/render` returns an SVG and creates no post; `GET /maps/<id of a page>` is 404.
- [ ] **Step 2:** Run `wpunit --filter Maps_Controller`. Expected: FAIL, class missing.
- [ ] **Step 3:** Implement the controller and register it.
- [ ] **Step 4:** Run `wpunit` and `wplint`. Expected: all pass. Commit.

### Task 7: Builder shell

**Files:**
- Create: `mappulse/includes/class-builder-screen.php`, `mappulse/tests/test-builder-screen.php`, `mappulse/src/builder/index.js`, `app.js`, `store.js`, `api.js`, `style.scss`, `src/builder/test/store.test.js`
- Modify: `mappulse/includes/class-map-post-type.php` (the "Add New" and row "Edit" links open the builder), `class-plugin.php`, `mappulse.php`

**Interfaces:**
- Produces (PHP): admin page `edit.php?post_type=mappulse_map&page=mappulse-builder[&map=<id>]`, capability `manage_mappulse_maps`; `Builder_Screen::url( int $map_id = 0 ): string`; enqueues `build/builder.js` and `.css` only on that page with `window.mappulseBuilder = { mapId, restUrl, listUrl, tiles: { url, attribution }, states, types, maxZones }`; prints a notice instead of the app when `build/builder.asset.php` is missing.
- Produces (JS): `store.js` is a reducer `reduce( state, action )` with state `{ mapId, title, status, zones: [], priority, base, baseBbox, doc, warnings, dirty, saving, error }` and actions `ADD_ZONE`, `REMOVE_ZONE`, `MOVE_ZONE` (`from`, `to`), `SET_ZONE` (`id`, `patch`), `SET_TITLE`, `SET_PRIORITY`, `SET_BASE`, `SET_DOC`, `LOADED`, `SAVED`, `FAILED`. `ADD_ZONE` ignores a zone whose `source` and `record` are already present and sets `clean` from `needsCleaning()`. `api.js` wraps the routes: `searchAreas( q, type, state )`, `fetchArea( source, record )`, `fetchBase( bbox )`, `loadMap( id )`, `saveMap( id, document, status )`.

- [ ] **Step 1:** Write `store.test.js` (adding the same record twice keeps one zone; `MOVE_ZONE` reorders; `REMOVE_ZONE` and every zone change set `dirty`; `SAVED` clears it; zone ids are unique slugs, `fisher-island` then `fisher-island-2`) and `test-builder-screen.php` (an Editor has no builder menu; the script is enqueued on the builder page and not on the map list or the front end; `Builder_Screen::url( 7 )` ends with `&map=7`; the "Add New" link points at the builder).
- [ ] **Step 2:** Run both suites. Expected: FAIL.
- [ ] **Step 3:** Implement. `app.js` renders a header (title field, step tabs with only "Areas" enabled and the other four shown as "Coming in 0.4"), a status line, and "Save draft" and "Publish" buttons; it warns before leaving with unsaved work.
- [ ] **Step 4:** Run `npm run test:js`, `npm run lint:js`, `wpunit`, `wplint`. Expected: all pass. Commit.

### Task 8: Areas step

**Files:**
- Create: `mappulse/src/builder/areas/search-panel.js`, `street-preview.js`, `zone-list.js`, `map-preview.js`, `use-document.js`, `src/builder/test/use-document.test.js`
- Modify: `mappulse/src/builder/app.js`, `style.scss`

**Interfaces:**
- Consumes: `api.js`, `store.js`, `buildDocument`, `bboxForBase`, `cleanOutline`, `needsCleaning`.
- Produces: `use-document.js` exports `planRebuild( prev, next ): { refetchBase: boolean, rebuild: boolean }` (refetch the base only when the zones' base box moved outside the box last fetched; rebuild on any zone, order, backdrop or clean change) and the hook that runs it, debounced 300 ms.

Behavior:
- Search panel: name or ZIP field, kind (City, ZIP area, County, Neighborhood), state. Results list name, kind, where and source, as the Sources screen does, with a "Preview" button each. Errors from one source show under the results without hiding the others.
- Street preview: Leaflet map with street tiles and the fetched outline on top, fitted to it. When `needsCleaning()` is true it shows both outlines (raw dashed, cleaned solid) and a checked "Use clean outline" box with one sentence of explanation. Buttons: "Add to map", "Not this one".
- Zone list: one row per zone with name (editable), kind, source, an up and down button (keyboard reachable), "Backdrop" and "Clean outline" toggles, "Remove", and any warning from `buildDocument` on its row.
- Map preview: the SVG from `POST /render` of the current document, redrawn after each rebuild, with a busy state and the last good map kept on failure.
- Saving sends `doc` with each zone's `geometry`, `backdrop`, `clean` and the `priority`; opening a saved map restores the zone list from those fields without refetching boundaries. A map made before 0.3.0 (no `geometry`) opens with its zones listed read-only and a note that its areas cannot be edited.

- [ ] **Step 1:** Write `use-document.test.js`: adding a zone inside the fetched box rebuilds without refetching; adding one outside refetches; toggling backdrop rebuilds; a title change does neither.
- [ ] **Step 2:** Run `npm run test:js -- use-document`. Expected: FAIL.
- [ ] **Step 3:** Implement the four components and the hook; wire them into `app.js`.
- [ ] **Step 4:** Run `npm run test:js`, `npm run lint:js`, `npm run build`. Expected: all pass, build succeeds.
- [ ] **Step 5:** On the local site with the mock sources: add two zones, reorder, save, reload the builder, and confirm the list returns. Read the browser console: no errors. Commit.

### Task 9: End-to-end run and release

**Files:**
- Create: `mappulse/tests/e2e/builder.spec.js`, `mappulse/tests/e2e/playwright.config.js`
- Modify: `.github/workflows/plugin.yml`, `mappulse/tests/smoke.sh`, version locations, `README.md`, `mappulse/readme.txt`, `CHANGELOG.md`, `docs/development-plan.md` (section 6: clean outline as a pipeline step; section 12: the C1 and C2 split)

- [ ] **Step 1:** Write `builder.spec.js`: log in to wp-env, open the builder, search "Gulfport" (City, Florida), preview, add; search "Fisher Island", preview, confirm the clean-outline box is checked, add; publish; open a page holding `[mappulse id=N]` and expect 2 zones and both names in the list; reopen the builder and expect 2 rows; expect no console error throughout. It runs against the live Census server only when `MAPPULSE_LIVE=1`.
- [ ] **Step 2:** Run it locally against the mock sources. Expected: FAIL until Tasks 1 to 8 are merged into the build, then PASS.
- [ ] **Step 3:** Add the run to the end-to-end CI job (Playwright with Chromium, after the smoke test, with `MAPPULSE_LIVE=1`). Add smoke check 15: the zip installs and `build/builder.js` is present.
- [ ] **Step 4:** Bump the version everywhere; update the documents listed above.
- [ ] **Step 5:** Run `npm run test:js`, `npm run lint:js`, `wpunit`, `wplint`, `bash bin/build-zip.sh`. Expected: all pass. Commit and push; all CI jobs green, including the live builder run.
