# MapPulse Hover Card (0.5.0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In MapPulse 0.5.0 pointing at an area, focusing it with the keyboard, or tapping it on a phone opens a card with a photo, a tagline, two facts and up to two buttons, set per area in the builder.

**Architecture:** Each area's card is stored on its zone in the map document and rendered by PHP as real HTML inside the map section, hidden until needed. The small front-end script that already syncs hover between map and list also shows and positions the card: beside the pointer's area on a desktop, as a sheet at the bottom of the screen on a phone. With JavaScript off the map still works: areas stay links. The builder's Zones and links step gains the card fields with a live preview of the card. The release also adds the Minimal Line preset, strengthens Brand Tint, and clears three carried-over issues.

**Tech Stack:** As 0.4.0. Plain JavaScript for the front end (no library, no build step for it), PHP 8.1, React through `@wordpress/element` and `@wordpress/components` in the builder, `@wordpress/media-utils` for the image picker (the WordPress media library), Jest, PHPUnit 9.6, Playwright.

**Spec:** [`docs/development-plan.md`](../../development-plan.md), sections 3 (Zones and links), 7, 8, 10 and 11.

This is the first plan of Phase 2. Phase 2 is delivered in three releases: 0.5.0 (this plan: hover card), 0.6.0 (the Decorate step: icons, text labels, compass, legend, landmarks, layers) and 0.7.0 (SVG import with the sanitizer).

**Not in this plan:** live listing numbers in the card, pan and zoom on a phone, area chips (Phase 3), the Decorate step, SVG import, Elementor style controls for the card beyond radius, shadow and an on/off switch.

## Global Constraints

- Version `0.5.0` in the plugin header, `MAPPULSE_VERSION`, `readme.txt` (`Stable tag`), `blocks/mappulse/block.json`, `blocks/mappulse/index.asset.php`, `mappulse/package.json`, `mappulse/src/geometry/index.js`, `CHANGELOG.md` and the README status line.
- The document stays `schema: 1`. A zone's card is the optional key `card` = `{ image: attachment ID, tagline: text up to 120 chars, stats: up to 2 of { label: up to 24 chars, value: up to 24 chars }, buttons: up to 2 of { label: up to 24 chars, url: http or https, post: post ID or 0 } }`. A card with no image, tagline, stat or button is not stored.
- A map with no cards renders byte-for-byte as it does in 0.4.0: no card markup, no new attribute, no new class.
- The card's first button defaults to the area's own link when the area has one and the card has no buttons, labeled "View area" (translatable).
- The card is real HTML in the page (headings, text, links), so crawlers and screen readers get it. Each area's link gets `aria-describedby` pointing at its card.
- The front-end script stays one file, under 15 KB gzipped, with no dependency and no request. The stylesheet stays under 5 KB gzipped. Both limits are tested.
- Images are WordPress attachments only, printed with `wp_get_attachment_image()` at the `medium_large` size with `loading="lazy"` and explicit width and height, so the card causes no layout shift. A deleted attachment prints no image and no broken tag.
- Opening rules: hover or keyboard focus opens the card; leaving closes it after 150 ms unless the pointer moved onto the card; Escape closes it and keeps focus where it was. On a touch pointer the first tap opens the card as a bottom sheet with a Close button and does not follow the link; the card's buttons follow links.
- `prefers-reduced-motion: reduce` removes the card's transitions.
- The card never covers the area it belongs to when there is room beside it, and never leaves the viewport.
- Card colors come from `--mappulse-card-*` custom properties with built-in defaults; the builder's Style step sets background, text and button colors; the Elementor widget sets radius and shadow and can switch cards off for one placement (`cards="no"` on the shortcode, `cards` attribute on the block).
- Every new REST input is validated; every output is escaped; every string is translatable with the `mappulse` text domain. PHPCS and ESLint stay clean.

## Review Focus

- A card with a very long tagline, a tall photo, or no photo: the card keeps a fixed width, the photo is cropped to a 16:9 box, and nothing overflows.
- An area near the right or bottom edge of the map, and a map inside a narrow column: the card flips to the side with room and stays inside the viewport.
- A keyboard user tabbing through 30 areas: each card opens on focus and closes on blur, its buttons are reachable with Tab before focus moves to the next area, and Escape works.
- A phone: the first tap opens the sheet and does not navigate; tapping outside or Close dismisses it; the page behind does not scroll while the sheet is open; rotating the phone keeps it usable.
- Two maps on one page, and a map inside an Elementor popup or a tab that starts hidden: each card belongs to its own map and is positioned correctly when the map becomes visible.
- An image attachment that is later deleted, and a button linked to a page that is later trashed: no broken image, and the button falls back to its stored address or is left out when it has none.

---

### Task 1: Carry-overs and presets

**Files:**
- Modify: `mappulse/src/builder/style/presets.js`, `store.js`, `mappulse/includes/class-importer.php`, `class-map-document.php` (`PRESETS`), `mappulse/src/builder/links/links-step.js`, and their tests

**Interfaces:**
- Produces: preset `minimal-line` (white land, no fill on areas, hairline borders in one ink color, a tinted hover); `brand-tint` with land, water and nearby areas at least 1.12 contrast apart from each other; `APPLY_PRESET` on a style whose colors differ from the current preset's keeps those hand-set colors unless `force: true` (the "Reset colors" link sends `force`); the importer drops `linkPost` and card `post` and `image` IDs from an imported file; a label's `dx`, `dy` are stored relative to the area's own center rather than the automatic label position, so adding an area later does not move hand-placed labels.

- [ ] **Step 1:** Write the tests: `applyPreset( 'minimal-line' )` returns valid hex for every color and `readable()` ok; for six brand colors `contrastRatio( land, water )` and `contrastRatio( land, context )` exceed 1.12; changing the brand color after hand-setting `water` keeps the hand-set `water`, and `force` replaces it; an imported document with `linkPost: 12` stores none; a hand-placed label keeps its frame position when a second area is added that shifts the automatic position.
- [ ] **Step 2:** Run `npm run test:js` and `wpunit`. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run all suites and linters. Expected: pass. Commit.

### Task 2: Card in the document and the renderer

**Files:**
- Modify: `mappulse/includes/class-map-document.php`, `class-renderer.php`, `functions.php`, `class-shortcode.php`, `blocks/mappulse/block.json`, `blocks/mappulse/render.php`
- Create: `mappulse/tests/test-card.php`

**Interfaces:**
- Produces: `Map_Document::sanitize()` keeps a zone's `card` under the Global Constraints rules. `Renderer::card( array $zone, string $map_uid ): string` returns `<div class="mappulse__card" id="{uid}-card-{zone id}" data-zone="{zone id}" hidden>` holding, in order, the image, `<h3 class="mappulse__card-title">` (the area name), the tagline `<p>`, a `<dl>` of stats, and a `<p class="mappulse__card-actions">` of button links; an empty string when the zone has nothing to show. `Renderer::section()` prints all cards in one `<div class="mappulse__cards">` after the map when `$args['cards']` is not `false`, and adds `aria-describedby` to each zone link and list link that has a card. `mappulse_render_map()` accepts `cards` (bool, default true). The shortcode accepts `cards="no"`; the block gains a boolean `cards` attribute.

- [ ] **Step 1:** Write the tests: the sample map's section is byte-identical to 0.4.0; a card with tagline, two stats and one button renders each part escaped, in order; markup in any text field is stripped; a third stat and a third button are dropped; a `javascript:` button URL is dropped; an image ID of a non-image attachment or a missing post prints no `<img>`; an image prints `loading="lazy"`, `width` and `height`; a card with no buttons on a linked area gets the "View area" button to the area's link; a button with `post` follows that page's permalink and falls back as area links do; `cards => false` prints no card and no `aria-describedby`; two maps rendered on one page have different card IDs.
- [ ] **Step 2:** Run `wpunit --filter Card`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `wpunit` and `wplint`. Expected: pass. Commit.

### Task 3: Front-end card behavior

**Files:**
- Modify: `mappulse/assets/js/mappulse.js`, `mappulse/assets/css/mappulse.css`
- Create: `mappulse/assets/js/test/card-position.test.js`, `mappulse/tests/test-budgets.php`

**Interfaces:**
- Produces: in `mappulse.js`, a pure function `place( anchor, card, viewport, gap = 12 ): { left, top, side }` (rectangles in viewport pixels; tries right, left, below, above in that order and returns the first that fits, else clamps inside the viewport), exported for tests when `module` exists. Behavior as the Global Constraints state. The card is moved to `document.body` while open (so no ancestor clips it) and returned on close. A touch pointer (`pointerType === 'touch'` or `(hover: none)`) uses the sheet: class `mappulse__card--sheet`, a Close button added once, `aria-modal` not used (the page stays reachable), focus moved to the card and returned on close.

- [ ] **Step 1:** Write `card-position.test.js`: an anchor at the left of a wide viewport places the card on the right; near the right edge it flips left; in a narrow viewport it goes below; when nothing fits it is clamped to stay fully inside; the card never overlaps the anchor when any side fits. Write `test-budgets.php`: `gzencode` of `mappulse.js` is under 15 000 bytes and of `mappulse.css` under 5 000.
- [ ] **Step 2:** Run `npm run test:js -- card-position` and `wpunit --filter Budgets`. Expected: the position tests FAIL (function missing); the budget tests pass today and must still pass after Step 3.
- [ ] **Step 3:** Implement the script and the card styles (fixed 280 px width, 16:9 image box with `object-fit: cover`, sheet layout under 600 px or on touch, reduced-motion rule).
- [ ] **Step 4:** Run all suites. Expected: pass. Add `assets/js/test` to the zip exclusions. Commit.

### Task 4: Card fields in the builder

**Files:**
- Create: `mappulse/src/builder/links/card-fields.js`, `card-preview.js`, `src/builder/test/card.test.js`
- Modify: `mappulse/src/builder/links/links-step.js`, `decorate.js`, `store.js`, `areas/plan.js`, `style/style-step.js`, `mappulse/includes/class-builder-screen.php` (enqueue the media library), `builder.scss`

**Interfaces:**
- Produces: `cleanCard( card ): card | null` (trims, drops empty stats and buttons, caps counts and lengths, null when empty); zones carry `card` through `LOADED`, `decorate()` and `drawSignature()`; `SET_ZONE` accepts `card`. Style keys `cardBackground`, `cardText`, `cardButton`, `cardButtonText` (hex, with defaults white, ink, the hover color, white) are added to the document's style, the Style step (a "Card" group) and the section's `--mappulse-doc-card-*` variables, written only when they differ from the defaults.

Behavior:
- Each row in Zones and links gains a "Card" disclosure (closed by default, with a dot when the area has a card): Photo (opens the WordPress media library, images only; shows a thumbnail, Replace and Remove), Tagline, two fact rows (label and value), two button rows (label and the same link field the area link uses).
- A count at the top: "5 of 12 areas have a card".
- The preview shows the selected row's card pinned open beside its area, drawn by the server like everything else.
- "Copy this card's layout to all areas" copies the fact labels and button labels (not values, photos or links) to areas without a card, so twelve cards are filled quickly.

- [ ] **Step 1:** Write `card.test.js`: `cleanCard` cases (empty card is null; a stat with only a label is kept, one with neither is dropped; a third stat is dropped; over-long text is cut); a card change alters `drawSignature` and not `signature`; `decorate` writes and removes `card`; `LOADED` restores it.
- [ ] **Step 2:** Run `npm run test:js -- card`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run all suites and linters, and `npm run build`. Expected: pass.
- [ ] **Step 5:** On the local site: give one area a photo, tagline, two facts and a button; save, reload; open the front-end page and hover the area. Read the browser console: no errors. Commit.

### Task 5: Elementor, block and shortcode options

**Files:**
- Modify: `mappulse/includes/integrations/class-elementor-widget.php`, `blocks/mappulse/index.js`, `mappulse/tests/test-elementor.php`, `test-block.php`, `test-shortcode.php`

**Interfaces:**
- Produces: Elementor content control "Hover cards" (switcher, default on) and style controls "Card radius" (slider, sets `--mappulse-card-radius`) and "Card shadow" (box shadow, sets `--mappulse-card-shadow`); block inspector toggle "Hover cards"; shortcode `cards="no"`.

- [ ] **Step 1:** Write the tests: each placement with cards off prints no card markup; the Elementor radius control writes the variable in the widget's CSS.
- [ ] **Step 2:** Run `wpunit`. Expected: the new tests FAIL (Elementor tests run in CI).
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run `wpunit` and `wplint`. Expected: pass. Commit.

### Task 6: End-to-end run and release

**Files:**
- Modify: `mappulse/tests/e2e/builder.spec.js`, `mappulse/tests/smoke.sh`, version locations, `README.md`, `mappulse/readme.txt`, `CHANGELOG.md`, `docs/development-plan.md` (section 12: the Phase 2 release split)

- [ ] **Step 1:** Extend the browser test: in Zones and links give the city a tagline, one fact and a button; publish; on the front-end page hover the city and expect its card visible, inside the viewport, not overlapping the area's label, with the tagline and a working button; press Escape and expect it hidden; Tab to the area and expect it open again; emulate a touch tap at 390 px width and expect the sheet with a Close button and no navigation; with JavaScript disabled expect the area still to be a link. No console error or failed same-site request.
- [ ] **Step 2:** Run it locally. Expected: FAIL until Tasks 1 to 5 are in the build, then PASS.
- [ ] **Step 3:** Add smoke check 17: a page with a map that has a card contains exactly one `.mappulse__card` for it, and the same map placed with `cards="no"` contains none.
- [ ] **Step 4:** Bump the version everywhere and update the documents listed above.
- [ ] **Step 5:** Run every suite and linter and `bash bin/build-zip.sh`. Expected: pass. Commit and push; all CI jobs green, including the live browser run.
