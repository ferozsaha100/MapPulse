#!/usr/bin/env node
// MapPulse spike, step 2: turn the raw layers into one full-context SVG.
// Usage: node scripts/build.mjs config/st-petersburg.json
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import * as P from '../src/pipeline.mjs';
import { renderSvg, renderPreview } from '../src/svg.mjs';

const cfgPath = process.argv[2] || 'config/st-petersburg.json';
const cfg = JSON.parse(await readFile(cfgPath, 'utf8'));
const rawDir = path.join('data', 'raw');
const outDir = 'out';
await mkdir(outDir, { recursive: true });

async function load(id) {
  try { return JSON.parse(await readFile(path.join(rawDir, `${id}.geojson`), 'utf8')).features || []; } catch { return []; }
}
let meta = {};
try { meta = JSON.parse(await readFile(path.join(rawDir, '_meta.json'), 'utf8')); } catch { /* no meta file */ }

const layers = {};
for (const src of cfg.sources) layers[src.id] = await load(src.id);
const warnings = [];
const notes = [];
for (const [id, feats] of Object.entries(layers)) if (!feats.length) warnings.push(`Layer "${id}" is empty or missing.`);
const keys = {};
for (const id of Object.keys(layers)) keys[id] = P.nameKey(layers[id]);

// 1. Match configured zones to source features
const matched = [];
const matchReport = [];
for (const zone of cfg.zones) {
  const hit = P.matchZone(zone, layers, keys);
  if (!hit) {
    warnings.push(`Zone "${zone.name}" was not found in any source.`);
    matchReport.push({ zone: zone.name, found: false });
    continue;
  }
  matched.push({ name: zone.name, slug: P.slug(zone.name), type: hit.type, layer: hit.layer, sourceName: hit.value, feature: hit.feature });
  matchReport.push({ zone: zone.name, found: true, type: hit.type, layer: hit.layer, sourceName: hit.value, score: hit.score, alternatives: hit.alternatives });
  const close = hit.alternatives.filter((a) => hit.score - a.score <= 25);
  if (close.length) warnings.push(`Zone "${zone.name}" uses "${hit.value}", but ${close.map((a) => `"${a.name}"`).join(', ')} could also be meant. Confirm which record is right.`);
}
if (!matched.length) {
  console.error('No zones matched. Run "npm run fetch" first, then check data/raw/_meta.json.');
  process.exit(1);
}

// 2. Projection and frame, fitted to the matched zones
const lat0 = (cfg.fetchBbox[1] + cfg.fetchBbox[3]) / 2;
const proj = P.makeProjection(lat0);
const projMP = (geom) => P.mapMP(P.toMP(geom), proj);
const box = [Infinity, Infinity, -Infinity, -Infinity];
for (const z of matched) {
  z.u = projMP(z.feature.geometry);
  const b = P.mpBbox(z.u);
  box[0] = Math.min(box[0], b[0]); box[1] = Math.min(box[1], b[1]); box[2] = Math.max(box[2], b[2]); box[3] = Math.max(box[3], b[3]);
}
const frame = P.fitFrame(box, cfg.frame);
const W = frame.width, H = frame.height;
const px = (mpU) => P.mapMP(mpU, frame.toPx);
const pxGeom = (geom) => P.clipToFrame(px(projMP(geom)), W, H);

// 3. Zones to pixels, then cut overlaps by priority
for (const z of matched) z.mp = px(z.u);
const zones = P.resolveOverlaps(matched, cfg.priority);
for (const z of zones) {
  if (z.cutShare > 0.02) notes.push(`"${z.name}" lost ${Math.round(z.cutShare * 100)}% of its area to higher-priority zones.`);
  if (z.cutShare > 0.85) warnings.push(`"${z.name}" is almost fully covered by higher-priority zones.`);
}

// 4. Water, clipped to the frame; larger bodies also trim the zones (coastline clip)
const waterCfg = { minAreaPx: 30, clipMinAreaPx: 60, ...(cfg.water || {}) };
const water = [];
for (const f of layers.water) {
  const mp = pxGeom(f.geometry);
  const area = P.mpArea(mp);
  if (area < waterCfg.minAreaPx) continue;
  water.push({ mp, area, name: keys.water ? f.properties[keys.water] || '' : '' });
}
const bigWater = water.filter((w) => w.area >= waterCfg.clipMinAreaPx).map((w) => ({ mp: w.mp, box: P.mpBbox(w.mp) }));
for (const z of zones) {
  const b = P.mpBbox(z.mp);
  const before = P.mpArea(z.mp);
  z.mp = P.subtract(z.mp, bigWater.filter((w) => P.boxesTouch(b, w.box)).map((w) => w.mp));
  z.waterShare = before > 0 ? Math.max(0, 1 - P.mpArea(z.mp) / before) : 0;
  if (z.waterShare > 0.02) notes.push(`"${z.name}": ${Math.round(z.waterShare * 100)}% of the source shape was over water and was clipped.`);
}

// 5. Surrounding areas: every neighborhood that is not a client zone
const used = new Set(zones.map((z) => z.feature));
const context = [];
for (const f of layers.neighborhoods) {
  if (used.has(f)) continue;
  const mp = pxGeom(f.geometry);
  if (P.mpArea(mp) < 6) continue;
  context.push({ mp, name: keys.neighborhoods ? f.properties[keys.neighborhoods] || '' : '' });
}

// 5b. City limits as a base tone under the neighborhoods, so the whole built-up area reads as one surface
const contextBase = [];
for (const f of layers.places) {
  if (used.has(f)) continue;
  const mp = pxGeom(f.geometry);
  if (P.mpArea(mp) < 20) continue;
  contextBase.push({ mp, name: keys.places ? f.properties[keys.places] || '' : '' });
}

// 6. Land outline
let land = layers.counties.map((f) => ({ mp: pxGeom(f.geometry) })).filter((l) => P.mpArea(l.mp) > 1);
if (!land.length) {
  warnings.push('No county outline arrived: land is drawn as the whole frame.');
  land = [{ mp: [P.frameRing(W, H)] }];
}

// 7. Roads, kept only where they pass through the frame
function roadLines(feats) {
  const out = [];
  for (const f of feats) {
    for (const line of P.toLines(f.geometry)) {
      let cur = [];
      for (const pt of line) {
        const p = frame.toPx(proj(pt));
        if (p[0] > -40 && p[0] < W + 40 && p[1] > -40 && p[1] < H + 40) cur.push(p);
        else { if (cur.length > 1) out.push(cur); cur = []; }
      }
      if (cur.length > 1) out.push(cur);
    }
  }
  return out;
}

// 8. Simplify everything in one topology so shared borders stay shared
const simple = P.simplifyTogether({
  zones: zones.map((z, i) => P.polyFeature(z.mp, { i })),
  context: context.map((c, i) => P.polyFeature(c.mp, { i })),
  contextBase: contextBase.map((c, i) => P.polyFeature(c.mp, { i })),
  land: land.map((l, i) => P.polyFeature(l.mp, { i })),
  water: water.map((w, i) => P.polyFeature(w.mp, { i })),
  roadsP: [P.lineFeature(roadLines(layers['roads-primary']), {})],
  roadsS: [P.lineFeature(roadLines(layers['roads-secondary']), {})],
}, cfg.simplify);
const clean = (geom) => P.toMP(geom).map((poly) => poly.filter((ring) => ring.length >= 4 && Math.abs(P.ringArea(ring)) > 0.5)).filter((poly) => poly.length);
simple.zones.forEach((f) => { zones[f.properties.i].mp = clean(f.geometry); });
simple.context.forEach((f) => { context[f.properties.i].mp = clean(f.geometry); });
simple.contextBase.forEach((f) => { contextBase[f.properties.i].mp = clean(f.geometry); });
simple.land.forEach((f) => { land[f.properties.i].mp = clean(f.geometry); });
simple.water.forEach((f) => { water[f.properties.i].mp = clean(f.geometry); });
const roadsPrimary = P.toLines(simple.roadsP[0] && simple.roadsP[0].geometry);
const roadsSecondary = P.toLines(simple.roadsS[0] && simple.roadsS[0].geometry);

// 9. Labels. A zone narrower than its name gets smaller type, down to a floor that still reads.
const minLabelSize = cfg.style.labelSizeMin || 9.5;
for (const z of zones) {
  z.lines = P.labelLines(z.name);
  z.label = P.labelPoint(z.mp);
  if (!z.label) { warnings.push(`"${z.name}" has no drawable area left.`); continue; }
  const chars = Math.max(...z.lines.map((l) => l.length));
  const textW = chars * cfg.style.labelSize * 0.72;
  const b = P.mpBbox(z.mp);
  const zoneW = b[2] - b[0];
  z.fontSize = Math.round(Math.min(cfg.style.labelSize, Math.max(minLabelSize, (cfg.style.labelSize * zoneW * 1.2) / textW)) * 10) / 10;
  const fitW = chars * z.fontSize * 0.72;
  if (fitW > zoneW * 1.1) notes.push(`"${z.name}": the label is wider than the zone even at ${z.fontSize} px (${Math.round(fitW)} px vs ${Math.round(zoneW)} px).`);
}
// Keep labels from sitting on top of each other; a label pushed clear of its anchor gets a leader line.
const boxes = zones.filter((z) => z.label).map((z) => ({
  z, x: z.label.x, y: z.label.y,
  w: Math.max(...z.lines.map((l) => l.length)) * z.fontSize * 0.72 + 4,
  h: z.lines.length * z.fontSize * 1.18 + 2,
}));
P.separateLabels(boxes, { width: W, height: H });
for (const b of boxes) {
  const moved = Math.hypot(b.x - b.z.label.x, b.y - b.z.label.y);
  b.z.anchor = { x: b.z.label.x, y: b.z.label.y };
  b.z.label = { ...b.z.label, x: b.x, y: b.y, w: b.w, h: b.h };
  b.z.leader = Math.abs(b.z.anchor.x - b.x) > b.w / 2 + 3 || Math.abs(b.z.anchor.y - b.y) > b.h / 2 + 3;
  if (moved > 4) notes.push(`"${b.z.name}": label moved ${Math.round(moved)} px to clear a neighbor${b.z.leader ? ' and drawn with a leader line' : ''}.`);
}

const byName = new Map();
for (const w of water) {
  if (!w.name || w.area < W * H * 0.012) continue;
  const lp = P.labelPoint(w.mp);
  if (!lp || lp.room < 34) continue;
  const prev = byName.get(w.name);
  if (prev && prev.area >= lp.area) continue;
  // Fit the name inside the frame; a tall, narrow body gets a vertical label.
  const textW = w.name.length * 10.4;
  const wb = P.mpBbox(w.mp);
  const vertical = wb[3] - wb[1] > (wb[2] - wb[0]) * 1.8 && wb[3] - wb[1] > textW + 24;
  const half = textW / 2 + 10;
  const x = vertical ? lp.x : Math.min(Math.max(lp.x, half), W - half);
  const y = vertical ? Math.min(Math.max(lp.y, half), H - half) : lp.y;
  byName.set(w.name, { name: w.name, x, y, vertical, area: lp.area });
}
const waterLabels = [...byName.values()];

// 10. Write the outputs
const title = `${cfg.name}: service areas`;
const waterDrawn = P.mergeForDrawing(water.map((w) => w.mp));
const svg = renderSvg({ title, frame: { width: W, height: H }, land, context, contextBase, zones, water, waterDrawn, roadsPrimary, roadsSecondary, waterLabels, style: cfg.style, synthetic: !!meta.synthetic });
const report = {
  market: cfg.name,
  synthetic: !!meta.synthetic,
  fetchedAt: meta.fetchedAt || null,
  frame: { width: W, height: H },
  zonesConfigured: cfg.zones.length,
  zonesMatched: zones.length,
  contextAreas: context.length,
  contextCities: contextBase.length,
  waterShapes: water.length,
  arcs: simple.arcCount,
  svgBytes: Buffer.byteLength(svg),
  nameFields: keys,
  availableNames: Object.fromEntries(['neighborhoods', 'places', 'zcta'].map((id) => [id, [...new Set(layers[id].map((f) => (keys[id] ? f.properties[keys[id]] : null)).filter(Boolean))].sort()])),
  matches: matchReport,
  notes,
  warnings,
};
await writeFile(path.join(outDir, `${cfg.slug}.svg`), svg);
await writeFile(path.join(outDir, 'preview.html'), renderPreview({ svg, title, zones, report, synthetic: !!meta.synthetic }));
await writeFile(path.join(outDir, 'report.json'), JSON.stringify(report, null, 2));

console.log(`\n${cfg.name}${meta.synthetic ? '  [SAMPLE DATA]' : ''}`);
console.log(`Frame ${W} x ${H} px. ${zones.length}/${cfg.zones.length} zones, ${context.length} surrounding areas, ${water.length} water shapes.`);
console.log(`SVG ${(report.svgBytes / 1024).toFixed(0)} KB -> out/${cfg.slug}.svg, out/preview.html, out/report.json\n`);
for (const m of matchReport) {
  console.log(`  ${m.found ? 'ok ' : 'MISSING'} ${m.zone.padEnd(20)} ${m.found ? `${m.layer}: "${m.sourceName}" (score ${m.score})` : ''}`);
}
if (notes.length) { console.log('\nNotes'); notes.forEach((n) => console.log('  - ' + n)); }
if (warnings.length) { console.log('\nWarnings'); warnings.forEach((w) => console.log('  ! ' + w)); }
