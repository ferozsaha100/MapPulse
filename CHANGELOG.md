# Changelog

All notable changes to MapPulse are recorded here. Versions follow semantic versioning.

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
