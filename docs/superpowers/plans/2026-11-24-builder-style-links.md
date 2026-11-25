# MapPulse Builder, Part 2: Style, Zones and Links (0.4.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In MapPulse 0.4.0 a map built in WordPress can be styled to the client's brand and every area can link to a page, with its label worded and positioned by hand, so the map is ready for a live client site.

**Architecture:** The builder gains two steps on top of the Areas step from 0.3.0. Style edits the document's `style` block; the server redraws without rebuilding geometry. Zones and links edits each zone's `link`, `text` and a new `label` override; a new `/links/search` route finds pages, and auto-match pairs areas with pages by name. The renderer and stylesheet learn three new style values (border width, border style, hover effect). Everything still flows through the one schema 1 document and the one PHP renderer, so the Elementor widget, block and shortcode pick the changes up with no change of their own.

**Tech Stack:** As 0.3.0: `@wordpress/scripts` 30, React through `@wordpress/element` and `@wordpress/components`, Jest, PHP 8.1, PHPUnit 9.6, Playwright. No new runtime dependency.

**Spec:** [`docs/development-plan.md`](../../development-plan.md), sections 3 (Style; Zones and links), 5, 7, 8, 10 and 11.

This is plan C2, the last plan of Phase 1. Plans A (0.1.0), B (0.2.1) and C1 (0.3.0) are merged.

**Not in this plan:** the hover card (image, tagline, stat rows, buttons), the Decorate step (icons, compass, legend), the Minimal Line preset, the Publish step (layout and phone settings stay on the widget, block and shortcode), drawing or uploading an area, border snapping. Each moves to Phase 2 or 3 as the spec lists them.

## Global Constraints

- Version `0.4.0` in the plugin header, `MAPPULSE_VERSION`, `readme.txt` (`Stable tag`), `blocks/mappulse/block.json`, `blocks/mappulse/index.asset.php`, `mappulse/package.json`, `CHANGELOG.md` and the README status line.
- The document stays `schema: 1`. Every new key is optional with a default that reproduces today's output, so every existing map renders byte-for-byte the same. The stored sample SVG test must keep passing unchanged.
- New style keys, exactly: `preset` (`atlas` | `brand-tint`, default `atlas`), `zoneStrokeWidth` (number 0 to 4, default 1.6), `zoneStrokeStyle` (`solid` | `dashed` | `dotted`, default `solid`), `hoverEffect` (`fill` | `outline`, default `fill`), `brand` (hex color, default empty; used only by `brand-tint`).
- New zone key, exactly: `label` = `{ text: string up to 60 chars, dx: number, dy: number, hidden: bool }`. `text` empty means "use the name". `dx`, `dy` are offsets in frame units, each clamped to plus or minus the frame's larger side.
- A zone link is an `http` or `https` URL, as today. A link chosen from the page search also stores `linkPost` (post ID); on render the URL is the post's current permalink when the post is still published, otherwise the stored URL.
- Elementor widget style controls keep overriding document colors through the existing `--mappulse-*` variables. New values follow the same pattern: the section sets `--mappulse-doc-*`, the stylesheet reads `var(--mappulse-x, var(--mappulse-doc-x))`.
- The front end gains no JavaScript and no request. A site font is used by name only; MapPulse loads no font file.
- Every new REST route is under `mappulse/v1` and requires `manage_mappulse_maps`. `/links/search` returns only posts the current user can read and whose type is public.
- Colors pass `sanitize_hex_color`. Text passes `sanitize_text_field`. Nothing typed in the builder reaches the page unescaped.
- Text contrast: the builder warns when label color against zone color falls under 4.5:1 (WCAG AA) and does not block saving.
- Every user-facing string is translatable with the `mappulse` text domain, including the zone warnings that 0.3.0 left in English. PHPCS and ESLint stay clean.

## Review Focus

- An area linked to a page whose slug later changes, or which is trashed: the map follows the new address, and falls back to the stored URL (never a broken empty link) when the page is gone.
- Two areas whose names match the same page in auto-match, and an area whose name matches two pages (a page and a post both titled "Aventura"): neither silently wins; the person chooses.
- A label dragged outside the frame or onto another label: it stays inside the frame, and the leader line appears when the label leaves its area.
- A map styled in the builder and then restyled in the Elementor widget: the widget's colors win, and removing them returns the builder's.
- A brand color so light or dark that the tint ramp produces labels nobody can read: the builder shows the contrast warning.
- A user who can edit pages but lacks `manage_mappulse_maps`: `/links/search` answers 403, and private or draft pages never appear in results for anyone who cannot read them.

---

### Task 1: Carry-overs from 0.3.0

**Files:**
- Modify: `mappulse/src/geometry/build-document.js`, `overlaps.js`, `mappulse/src/builder/store.js`, `app.js`, `areas/zone-list.js`, `mappulse/includes/class-map-post-type.php`, and their tests
- Create: `mappulse/src/builder/messages.js`, `src/builder/test/messages.test.js`

**Interfaces:**
- Produces: `warningText( code ): string` (translated text for `no_shape`, `mostly_cut`, `in_pieces`, `tiny`, and new `under_water`, `too_small`); `buildDocument` warnings carry `code` only. Among zones marked `first`, the smaller area is cut first. `Map_Post_Type::save()` returns `WP_Error( 'mappulse_not_stored' )` when the document meta does not read back. After a failed open the builder shows the error and renders no editor.

- [ ] **Step 1:** Write the tests: two cleaned zones where the larger is listed first leave the smaller whole (Fisher Island fixture inside a cleaned square); `warningText` returns a non-empty string for each code and for an unknown code; a zone erased by water yields `under_water`, one lost to simplification `too_small`; `FAILED` during loading sets `blocked: true` and `saveActions().canSave` is false; `save()` with the meta write filtered to fail returns `mappulse_not_stored`.
- [ ] **Step 2:** Run `npm run test:js` and `wpunit`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run all suites and linters. Expected: pass. Commit.

### Task 2: Style and label keys in the document, renderer and stylesheet

**Files:**
- Modify: `mappulse/includes/class-map-document.php`, `class-renderer.php`, `mappulse/assets/css/mappulse.css`, `mappulse/tests/test-map-document.php`, `test-renderer.php`

**Interfaces:**
- Produces: `Map_Document::sanitize()` keeps the five style keys and the zone `label` and `linkPost` keys under the Global Constraints rules; `Map_Document::defaults()` gains the five style defaults. `Renderer::section()` adds `--mappulse-doc-zone-stroke-width`, `--mappulse-doc-zone-dash` (`none`, `6 4` or `1.5 3.5`) and the class `mappulse--hover-outline` when asked. `Renderer::svg()` uses `label.text` (split into lines by the existing rule: at most two lines, broken at the space nearest the middle) in place of the name, adds `dx`, `dy` to the label position, clamps to the frame, draws the leader when the moved label's box no longer covers its anchor, and omits a hidden label. `Renderer::link( array $zone ): string` resolves `linkPost`.

- [ ] **Step 1:** Write the tests: the sample map's SVG and section are byte-identical to the stored fixtures; a document with `zoneStrokeStyle: 'dashed'` and `zoneStrokeWidth: 9` renders `--mappulse-doc-zone-dash:6 4` and width `4`; `preset: 'evil'` becomes `atlas`; `label.text` "Fisher Island Club" renders two lines; `label.dx: 99999` is clamped and the label stays inside the frame; `label.hidden` removes the label and keeps the zone; a zone with `linkPost` of a published page renders that page's permalink after its slug changes, and the stored URL after the page is trashed; `linkPost` of a draft or of another site's ID renders the stored URL; `label.text` with markup is stripped.
- [ ] **Step 2:** Run `wpunit`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement. In the stylesheet, zone stroke width and dash read the new variables with today's values as fallbacks; `.mappulse--hover-outline` changes the hover and focus state from a fill change to a 3-unit stroke in the hover color.
- [ ] **Step 4:** Run `wpunit` and `wplint`. Expected: pass. Commit.

### Task 3: Presets and site colors

**Files:**
- Create: `mappulse/src/builder/style/presets.js`, `contrast.js`, `src/builder/test/presets.test.js`, `contrast.test.js`
- Modify: `mappulse/includes/class-builder-screen.php`, `mappulse/tests/test-builder-screen.php`

**Interfaces:**
- Produces (JS): `PRESETS` (`atlas`, `brand-tint`, each `{ label, description }`); `applyPreset( name, style, brand ): style` returning a full style block (`atlas` returns the document defaults; `brand-tint` derives zone, hover, label, context and water colors from one brand color by mixing it with white and near-black in fixed steps, and sets `brand`); `contrastRatio( hexA, hexB ): number`; `readable( style ): { ok, ratio }` for label on zone and labelHover on zoneHover.
- Produces (PHP): `Builder_Screen::settings()` gains `siteColors` (`[ { name, color } ]`, from the theme's `theme.json` palette and, when Elementor is active, its kit's global colors; hex values only, at most 24, no duplicates) and `siteFonts` (`[ { name, family } ]`, from the theme's font families and the Elementor kit's typography, plus four system stacks), and `styleDefaults` (`Map_Document::defaults()`).

- [ ] **Step 1:** Write the tests: `applyPreset( 'atlas' )` equals the defaults; `applyPreset( 'brand-tint', {}, '#1F3A5F' )` returns valid hex for every color key, `brand` set, and `readable()` ok; brand `#FFFFFF` and `#000000` still return valid hex and `readable()` reports honestly; `contrastRatio( '#000000', '#FFFFFF' )` is 21; `siteColors` holds the test theme's palette, skips a `var(...)` value, and `siteFonts` always includes the system stacks.
- [ ] **Step 2:** Run both suites. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run all suites and linters. Expected: pass. Commit.

### Task 4: Links routes

**Files:**
- Create: `mappulse/includes/class-links-controller.php`, `mappulse/tests/test-links-controller.php`
- Modify: `mappulse/includes/class-plugin.php`, `mappulse/mappulse.php`

**Interfaces:**
- Produces: `GET /links/search?q=<2 to 60 chars>` → up to 20 of `{ id, title, url, type: <post type label> }`, published posts of public types, title matches first. `POST /links/match` body `{ names: string[] (at most MAPPULSE_MAX_ZONES) }` → `{ <name>: [ { id, title, url, type } ] }`, the published posts whose title equals the name after lower-casing and removing punctuation, pages before other types, at most 5 each. An empty list means no match.

- [ ] **Step 1:** Write the tests: an Editor and a visitor are refused; a draft, a private page and a non-public post type never appear; "aventura" finds the page "Aventura"; `match` for `[ 'Aventura', 'Nowhere' ]` with a page and a post both titled "Aventura" returns both for the first (page first) and an empty list for the second; "St. Pete Beach" matches a page titled "St Pete Beach"; a one-character query answers 400.
- [ ] **Step 2:** Run `wpunit --filter Links_Controller`. Expected: FAIL, class missing.
- [ ] **Step 3:** Implement and register.
- [ ] **Step 4:** Run `wpunit` and `wplint`. Expected: pass. Commit.

### Task 5: Builder steps and state

**Files:**
- Modify: `mappulse/src/builder/store.js`, `app.js`, `areas/rebuilder.js`, `areas/plan.js`, `api.js`, `builder.scss`, and their tests

**Interfaces:**
- Produces: state gains `step` (`areas` | `style` | `links`), `style` (full style block). Actions `SET_STEP`, `SET_STYLE` (`patch`), `APPLY_PRESET` (`name`, `brand`), and `SET_ZONE` now also accepts `link`, `linkPost`, `text`, `label`. Zones keep `link`, `linkPost`, `text`, `label` through `LOADED` and into the built document. `signature()` splits in two: `geometrySignature` (what 0.3.0 hashed) and `drawSignature` (geometry plus style, links and labels). The rebuilder rebuilds geometry only when the first changes and only asks the server to redraw when just the second does. `api.js` gains `searchLinks( q )`, `matchLinks( names )`. Steps 1 to 3 are tabs (buttons with `aria-current`); Style and Zones and links are disabled until the map has an area.

- [ ] **Step 1:** Write the tests: a style change redraws without calling `build` or `fetchBaseLayer`; a `labelSize` change does rebuild (label boxes depend on it); a link change redraws and marks the map changed; `LOADED` then `toDocument` round-trips `style`, `link`, `linkPost`, `text`, `label`; `APPLY_PRESET` replaces colors and keeps `labelFont` and `labelSize`; the step cannot be set to `style` with no areas.
- [ ] **Step 2:** Run `npm run test:js`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run all suites and linters, and `npm run build`. Expected: pass. Commit.

### Task 6: Style step

**Files:**
- Create: `mappulse/src/builder/style/style-step.js`, `color-field.js`
- Modify: `mappulse/src/builder/app.js`, `builder.scss`

Behavior:
- Preset cards (Atlas, Brand Tint), each with a small swatch strip. Brand Tint shows one "Brand color" field; choosing it or changing the color recolors the map.
- Color fields in three groups (Areas: fill, border, hover, label, label on hover. Surroundings: land, nearby areas, their borders. Water and roads: water, coastline, water names, road, road edge). Each is a swatch button opening a popover with the site's colors first and a custom picker.
- Label font (site fonts and system stacks) and label size (8 to 20).
- Border width (0 to 4) and style (solid, dashed, dotted). Hover effect (fill or outline).
- A contrast warning under the label colors when `readable()` is not ok.
- "Reset to preset" restores the chosen preset's values.
- The preview on the right is the same component as in Areas and updates after each change.

- [ ] **Step 1:** Implement the components and wire them to the store.
- [ ] **Step 2:** Run `npm run lint:js` and `npm run build`. Expected: clean.
- [ ] **Step 3:** On the local site: choose Brand Tint with `#1F3A5F`, set a dashed border, save, reload; the choices return and the front-end page shows them. Read the browser console: no errors. Commit.

### Task 7: Zones and links step

**Files:**
- Create: `mappulse/src/builder/links/links-step.js`, `link-field.js`, `label-mover.js`, `auto-match.js`, `src/builder/test/auto-match.test.js`
- Modify: `mappulse/src/builder/app.js`, `areas/map-preview.js`, `builder.scss`

**Interfaces:**
- Produces: `planMatches( zones, answer ): { apply: [ { id, link, linkPost } ], ask: [ { id, name, choices } ], none: [ id ] }` (one match and no existing link → apply; several matches, or one page matched by two zones → ask; a zone that already has a link is left alone).

Behavior:
- One row per area: name, "Label on the map" (placeholder: the name), "Hide label" toggle, "Link" field, "Short description" (the existing `text`, shown in the area list on the site).
- Link field: type to search pages and posts (results show title and type), or paste any URL, including an IDX search URL. A chosen page shows its title with a "Remove" button; a pasted URL shows as typed. An invalid URL is flagged and not stored.
- "Match areas to pages" button: runs auto-match, applies the clear matches, lists the unclear ones with a choice each, and reports how many areas have no page.
- Moving a label: selecting a row highlights its area and label in the preview. The label can be dragged with the pointer, or moved with four arrow buttons in the row (8 units a press, 1 with Shift) so it works by keyboard. "Reset position" clears the offset.
- A count at the top: "9 of 12 areas have a link", with a filter to show only those without.

- [ ] **Step 1:** Write `auto-match.test.js`: one clear match applies; a page and a post with one title go to `ask` with the page first; two zones matching one page both go to `ask`; a zone with a link is untouched; no match goes to `none`.
- [ ] **Step 2:** Run `npm run test:js -- auto-match`. Expected: FAIL.
- [ ] **Step 3:** Implement the components.
- [ ] **Step 4:** Run all suites and linters, and `npm run build`. Expected: pass.
- [ ] **Step 5:** On the local site: link one area by search and one by pasted URL, move a label by keyboard, hide another, save, reload; all return, and the front-end page has two working links. Commit.

### Task 8: End-to-end run and release

**Files:**
- Modify: `mappulse/tests/e2e/builder.spec.js`, `mappulse/tests/smoke.sh`, version locations, `README.md`, `mappulse/readme.txt`, `CHANGELOG.md`, `docs/development-plan.md` (section 12: Phase 1 complete)

- [ ] **Step 1:** Extend the browser test: after adding two areas, open Style, choose Brand Tint, and expect the preview zone fill to change; open Zones and links, create a page titled like the first area through the REST API, run "Match areas to pages", expect that area to show the page; set a custom label on the second area and move it right twice; publish; on the front-end page expect one zone to be a link to the created page, the custom label text to be present, and the zone fill to be the tinted color; reopen the builder and expect the same state. No console error or failed same-site request throughout.
- [ ] **Step 2:** Run it locally against the mock sources. Expected: FAIL until Tasks 1 to 7 are in the build, then PASS.
- [ ] **Step 3:** Add smoke check 16: the `/links/search` and `/links/match` routes are registered.
- [ ] **Step 4:** Bump the version everywhere and update the documents listed above.
- [ ] **Step 5:** Run every suite and linter and `bash bin/build-zip.sh`. Expected: pass. Commit and push; all CI jobs green, including the live browser run.
