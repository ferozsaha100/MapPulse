# Changelog

All notable changes to MapPulse are recorded here. Versions follow semantic versioning.

## 0.0.1 (Phase 0 spike)

- Added the spike: scripts that download real boundary layers and render one full-context SVG.
- Added the pipeline steps for zone matching, priority overlap cutting, coastline clipping,
  shared-edge simplification, projection and label placement.
- Added a GitHub Actions workflow that runs the spike on stand-in data and on the real sources,
  and commits the result to `spike/results/`.
- Added the development plan and the data source notes.
