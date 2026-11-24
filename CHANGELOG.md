# Changelog

All notable changes to MapPulse are recorded here. Versions follow semantic versioning.

## 0.2.0 (boundary service)

- Added boundary sources behind one interface: the US Census Bureau (cities, ZIP areas, counties) and
  registered ArcGIS layers (a city's neighborhoods).
- Added the Sources screen: register a layer, find an area by name, and see its real outline with a
  street-map link for checking it.
- Added `mappulse/v1` routes for sources, boundary search, shape fetch and base-map layers (land, cities,
  surrounding areas, water, major and minor roads), all limited to map managers.
- Added `wp mappulse find`, `shape`, `sources list|add|remove` and `cache flush`.
- All remote requests go through one client: https only, allowed hosts only, no redirects, 20 second
  timeout, spacing between requests, a 4 MB cap per query and a small number of retries. A host that fails
  is skipped for the rest of the page request. Answers are cached as files for 30 days; a failed or
  incomplete answer is never cached.
- A search that has more matches than it shows says so. Names match across spaces and punctuation, so
  "st petersburg" finds "St. Petersburg".
- Maps screen: an Import button and a Shortcode column. The empty widget notice links to the Import screen.
- Uninstalling now removes registered layers and saved boundary answers. Maps are still kept.

## 0.1.0 (first installable plugin version)

- Added the `mappulse/` plugin: the `mappulse_map` map store, with each map's SVG cached and rebuilt when
  the map or the plugin version changes.
- Added one renderer, `mappulse_render_map()`, behind three placements: an Elementor widget, the
  `agentview/mappulse` Gutenberg block and the `[mappulse]` shortcode.
- Added four layouts (list left, list right, list below, map only), with hover and keyboard focus synced
  between the map and the area list.
- Added map file import (admin screen and `wp mappulse import`) and a bundled St. Petersburg sample map.
- Colors are CSS custom properties: each map sets `--mappulse-doc-*` defaults, and `--mappulse-*` overrides them.
- Added the test setup: PHPUnit inside WordPress with Elementor, an end-to-end smoke script, coding
  standards, and workflows that build and release `mappulse.zip`.

## Spike 0.0.5

- The spike writes a map document (`<slug>.map.json`, schema 1): zones, style and the projected drawing paths.
  The plugin imports this file until the builder exists.
- Added unit tests for the export (`npm test`).

## 0.0.4 (Phase 0 spike)

- Label collision boxes use the measured width of the uppercase label type, so neighboring labels no longer touch.

## 0.0.3 (Phase 0 spike, second tuning pass)

- Water shapes are merged before drawing, which removes the seam lines between adjacent bodies of water.
- A zone narrower than its name gets smaller type, down to `style.labelSizeMin` (default 9.5 px).
- Leader lines are drawn only when a label has been pushed clear of its anchor point.

## 0.0.2 (Phase 0 spike, first tuning pass on real data)

- Zone matching ignores generic organisation words ("Neighborhood", "Assn", "Civic") and treats
  "Greater" and "Historic" as qualifiers, so "Woodlawn" finds "Greater Woodlawn" before "Woodlawn Oaks".
- The report lists the other candidate records for each zone and warns when the choice is close.
- Labels that would overlap are pushed apart; a label moved off its zone gets a leader line.
- City limits are drawn as a base tone under the neighborhoods, so land without a neighborhood
  association no longer reads as a hole.
- Ponds smaller than 30 px2 are dropped (`water.minAreaPx` in the config).

## 0.0.1 (Phase 0 spike)

- Added the spike: scripts that download real boundary layers and render one full-context SVG.
- Added the pipeline steps for zone matching, priority overlap cutting, coastline clipping,
  shared-edge simplification, projection and label placement.
- Added a GitHub Actions workflow that runs the spike on stand-in data and on the real sources,
  and commits the result to `spike/results/`.
- Added the development plan and the data source notes.
