# MapPulse spike (Phase 0)

Rebuilds the St. Petersburg client map from real boundary data as one full-context SVG:
land, coastline, water, every other neighborhood drawn muted, major roads, then the client zones.

This is throwaway code. Its job is to prove the look and measure file size before the plugin is built.
`src/pipeline.mjs` is the part that carries into Phase 1.

## Run it

Needs Node 18 or later and internet access.

    npm install
    npm run fetch
    npm run build

Then open `out/preview.html`.

On GitHub the **Spike** workflow runs the same commands on every push that changes `spike/`,
and commits the output to `spike/results/st-petersburg/`.

- `npm run fetch` downloads 7 layers into `data/raw/`: St. Petersburg neighborhood associations
  (city GIS), plus Census places, ZIP areas, counties, water and roads. It prints the layer it used for each.
- `npm run build` matches the 17 zones by name, cuts overlaps by priority, clips zones to the coastline,
  simplifies everything in one topology so shared borders stay shared, places labels, and writes
  `out/st-petersburg.svg`, `out/preview.html` and `out/report.json`.
- `npm run sample` writes invented stand-in shapes for a dry run without internet.
  That output is stamped "Sample data" and is not real geography.

## Reading a run

The build prints a match table (also in `out/report.json`) showing which source record each zone matched.
A zone marked MISSING needs its match name changed in `config/st-petersburg.json`.

## What to adjust

`config/st-petersburg.json` holds the zone list and match names, the priority order
(neighborhood, city, zip, county), colors, simplification and frame padding.

A ZIP zone looks like this:

    { "name": "33701", "type": "zip", "find": [{ "layer": "zcta", "match": ["33701"] }] }

## Not in the spike

- Tolerance-based snapping of borders that do not already share points. The spike only snaps to a fine grid.
- Label collision handling. The build lists labels that are wider than their zone.
- The builder, the plugin and hover cards.
