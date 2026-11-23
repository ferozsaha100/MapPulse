// MapPulse geometry pipeline (spike version).
// Shapes are MultiPolygons in the polygon-clipping layout:
// MultiPolygon = Polygon[], Polygon = Ring[], Ring = [x, y][].
import pc from 'polygon-clipping';
import polylabel from 'polylabel';
import { topology } from 'topojson-server';
import { feature } from 'topojson-client';
import { presimplify, simplify } from 'topojson-simplify';

// ---------------------------------------------------------------- geometry helpers
export function toMP(geom) {
  if (!geom) return [];
  if (geom.type === 'Polygon') return [geom.coordinates];
  if (geom.type === 'MultiPolygon') return geom.coordinates;
  if (geom.type === 'GeometryCollection') return geom.geometries.flatMap(toMP);
  return [];
}
export function toLines(geom) {
  if (!geom) return [];
  if (geom.type === 'LineString') return [geom.coordinates];
  if (geom.type === 'MultiLineString') return geom.coordinates;
  if (geom.type === 'GeometryCollection') return geom.geometries.flatMap(toLines);
  return [];
}
export const mapMP = (mp, f) => mp.map((poly) => poly.map((ring) => ring.map(f)));
export function ringArea(ring) {
  let a = 0;
  for (let i = 0, n = ring.length, j = n - 1; i < n; j = i++) a += (ring[j][0] - ring[i][0]) * (ring[j][1] + ring[i][1]);
  return a / 2;
}
export const polyArea = (poly) => poly.reduce((sum, ring, i) => sum + (i === 0 ? Math.abs(ringArea(ring)) : -Math.abs(ringArea(ring))), 0);
export const mpArea = (mp) => mp.reduce((sum, poly) => sum + polyArea(poly), 0);
export function bboxOf(points, box = [Infinity, Infinity, -Infinity, -Infinity]) {
  for (const p of points) {
    if (p[0] < box[0]) box[0] = p[0];
    if (p[1] < box[1]) box[1] = p[1];
    if (p[0] > box[2]) box[2] = p[0];
    if (p[1] > box[3]) box[3] = p[1];
  }
  return box;
}
export function mpBbox(mp) {
  const box = [Infinity, Infinity, -Infinity, -Infinity];
  for (const poly of mp) bboxOf(poly[0] || [], box);
  return box;
}
export const boxesTouch = (a, b) => a[0] <= b[2] && a[2] >= b[0] && a[1] <= b[3] && a[3] >= b[1];
export const frameRing = (w, h) => [[[0, 0], [w, 0], [w, h], [0, h], [0, 0]]];

// ---------------------------------------------------------------- projection
/** Equirectangular projection scaled by cos(latitude). Good for one metro area. */
export function makeProjection(lat0) {
  const k = Math.cos((lat0 * Math.PI) / 180);
  return ([lon, lat]) => [lon * k, -lat];
}
/** Fit a bounding box (in projected units) into a pixel frame with padding and aspect limits. */
export function fitFrame(box, { width = 1000, padding = 0.07, minAspect = 1, maxAspect = 1.6 } = {}) {
  let [x0, y0, x1, y1] = box;
  const pad = padding * Math.max(x1 - x0, y1 - y0);
  x0 -= pad; y0 -= pad; x1 += pad; y1 += pad;
  let w = x1 - x0, h = y1 - y0;
  if (w / h < minAspect) { const add = (minAspect * h - w) / 2; x0 -= add; x1 += add; w = x1 - x0; }
  if (w / h > maxAspect) { const add = (w / maxAspect - h) / 2; y0 -= add; y1 += add; h = y1 - y0; }
  const scale = width / w;
  return { width, height: Math.round(h * scale), toPx: ([x, y]) => [(x - x0) * scale, (y - y0) * scale] };
}

// ---------------------------------------------------------------- name matching
export const norm = (s) => String(s).toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, ' ').trim();
const SKIP_KEY = /(^|_)(id|oid|objectid|fid|geoid|code|date|url|web|email|phone|addr|contact|pres|status|shape|area|len|lat|lon|centlat|centlon|intpt)/i;

/** Guess which property holds the display name of a layer's features. */
export function nameKey(features) {
  const stats = new Map();
  for (const f of features) {
    for (const [k, v] of Object.entries(f.properties || {})) {
      if (typeof v !== 'string' || !/[a-z]/i.test(v) || v.length > 80) continue;
      if (!stats.has(k)) stats.set(k, new Set());
      stats.get(k).add(v);
    }
  }
  let best = null, bestScore = -1;
  for (const [k, set] of stats) {
    let score = set.size;
    if (/^(name|basename)$/i.test(k)) score *= 3;
    else if (/name|assoc|neigh|nbhd/i.test(k)) score *= 2;
    if (SKIP_KEY.test(k)) score *= 0.2;
    if (score > bestScore) { bestScore = score; best = k; }
  }
  return best;
}

const GENERIC = new Set(['nbrhd', 'neighborhood', 'neighbourhood', 'assn', 'assoc', 'association', 'civic', 'property', 'owners', 'homeowners',
  'home', 'community', 'residents', 'inc', 'condo', 'city', 'town', 'village', 'cdp', 'zcta5']);
/** A name with the generic organisation words removed: "ALLENDALE NEIGHBORHOOD ASSN" -> "allendale". */
export const coreName = (s) => norm(s).split(' ').filter((w) => !GENERIC.has(w)).join(' ');

// Words that qualify a name without naming a different place: "Greater Woodlawn" is Woodlawn, "Woodlawn Oaks" is not.
const QUALIFIER = new Set(['greater', 'historic', 'the']);

function scoreValue(value, patterns) {
  const nv = coreName(value);
  if (!nv) return 0;
  let best = 0;
  patterns.forEach((pattern, i) => {
    const p = coreName(pattern) || norm(pattern);
    let s = 0;
    if (nv === p) s = 100;
    else if (` ${nv} `.includes(` ${p} `)) {
      const pw = new Set(p.split(' '));
      const extra = nv.split(' ').filter((w) => !pw.has(w));
      s = extra.every((w) => QUALIFIER.has(w)) ? 94 - 2 * extra.length : 80 - Math.min(20, (nv.length - p.length) * 0.5);
    } else if (nv.includes(p)) s = 55;
    s *= 1 - 0.12 * i;
    if (s > best) best = s;
  });
  return best;
}

/** Find the feature for one configured zone. Tries each "find" entry in order. */
export function matchZone(zone, layers, keys) {
  for (const entry of zone.find) {
    const feats = layers[entry.layer] || [];
    const hits = [];
    for (const f of feats) {
      let top = null;
      for (const [k, v] of Object.entries(f.properties || {})) {
        if (typeof v !== 'string') continue;
        let s = scoreValue(v, entry.match);
        if (s && k === keys[entry.layer]) s += 5;
        if (s >= 50 && (!top || s > top.score)) top = { feature: f, score: Math.round(s), value: v, key: k };
      }
      if (top) hits.push(top);
    }
    if (!hits.length) continue;
    hits.sort((a, b) => b.score - a.score);
    const [best, ...rest] = hits;
    // Other records that could also be meant. The builder shows these so a person confirms the right one.
    const alternatives = rest.filter((h) => h.value !== best.value).slice(0, 4).map((h) => ({ name: h.value, score: h.score }));
    return { ...best, alternatives, layer: entry.layer, type: entry.type || zone.type };
  }
  return null;
}

// ---------------------------------------------------------------- overlaps and clipping
function safe(op, fallback, ...args) {
  try { return pc[op](...args); } catch (err) { return fallback; }
}
/** Cut higher-priority zones out of lower ones. Returns the zones in priority order. */
export function resolveOverlaps(zones, priority) {
  const rank = (z) => { const i = priority.indexOf(z.type); return i < 0 ? priority.length : i; };
  const ordered = zones.map((z, i) => ({ z, i })).sort((a, b) => rank(a.z) - rank(b.z) || a.i - b.i).map((o) => o.z);
  const done = [];
  for (const z of ordered) {
    const before = mpArea(z.mp);
    const box = mpBbox(z.mp);
    const clips = done.filter((d) => boxesTouch(box, mpBbox(d.mp))).map((d) => d.mp);
    if (clips.length) z.mp = safe('difference', z.mp, z.mp, ...clips);
    const after = mpArea(z.mp);
    z.cutShare = before > 0 ? Math.max(0, 1 - after / before) : 0;
    z.parts = z.mp.length;
    done.push(z);
  }
  return ordered;
}
export const clipToFrame = (mp, w, h) => safe('intersection', [], mp, [frameRing(w, h)]);
export const subtract = (mp, clips) => (clips.length ? safe('difference', mp, mp, ...clips) : mp);
export const unionAll = (mps) => (mps.length ? safe('union', mps[0], ...mps) : []);
/** Merge shapes for drawing, so neighbors share no visible seam. Falls back to the unmerged shapes. */
export function mergeForDrawing(mps) {
  const all = mps.filter((mp) => mp && mp.length);
  if (!all.length) return [];
  try { return pc.union(all[0], ...all.slice(1)); } catch (err) { return all.flat(); }
}

// ---------------------------------------------------------------- shared-edge simplification
const fc = (items) => ({
  type: 'FeatureCollection',
  features: items.map((it) => ({ type: 'Feature', properties: it.properties || {}, geometry: it.geometry })),
});
export const polyFeature = (mp, properties) => ({ properties, geometry: { type: 'MultiPolygon', coordinates: mp } });
export const lineFeature = (lines, properties) => ({ properties, geometry: { type: 'MultiLineString', coordinates: lines } });

/** Simplify several layers in one topology so borders shared between shapes stay shared. */
export function simplifyTogether(layers, { quantization = 1e5, minAreaPx = 0.9 } = {}) {
  const objects = {};
  for (const [name, items] of Object.entries(layers)) objects[name] = fc(items);
  const topo = presimplify(topology(objects, quantization));
  const simple = simplify(topo, minAreaPx);
  const out = {};
  for (const name of Object.keys(layers)) out[name] = feature(simple, simple.objects[name]).features;
  out.arcCount = simple.arcs.length;
  return out;
}

// ---------------------------------------------------------------- labels
/** A point well inside the largest part of a shape. */
export function labelPoint(mp) {
  let best = null, bestArea = 0;
  for (const poly of mp) { const a = polyArea(poly); if (a > bestArea) { bestArea = a; best = poly; } }
  if (!best) return null;
  const p = polylabel(best, 1.0);
  return { x: p[0], y: p[1], room: p.distance || 0, area: bestArea };
}
export function labelLines(name) {
  const words = name.toUpperCase().replace(/-/g, '- ').split(/\s+/);
  if (words.length < 2 || name.length <= 9) return [name.toUpperCase()];
  const lines = [];
  let current = '';
  const target = Math.ceil(name.length / 2);
  for (const w of words) {
    const next = current ? (current.endsWith('-') ? current + w : `${current} ${w}`) : w;
    if (current && next.length > target + 2 && lines.length === 0) { lines.push(current); current = w; } else current = next;
  }
  lines.push(current);
  return lines;
}
export const slug = (s) => norm(s).replace(/ /g, '-');

/**
 * Push overlapping label boxes apart. Each item: { x, y, w, h } with x, y at the box centre.
 * Items are moved in place and kept inside the frame.
 */
export function separateLabels(items, { width, height, pad = 3, iterations = 120 } = {}) {
  const clamp = (v, lo, hi) => Math.min(Math.max(v, lo), hi);
  for (let n = 0; n < iterations; n++) {
    let moved = false;
    for (let i = 0; i < items.length; i++) {
      for (let j = i + 1; j < items.length; j++) {
        const a = items[i], b = items[j];
        const ox = (a.w + b.w) / 2 + pad - Math.abs(a.x - b.x);
        const oy = (a.h + b.h) / 2 + pad - Math.abs(a.y - b.y);
        if (ox <= 0 || oy <= 0) continue;
        moved = true;
        if (oy <= ox) { const d = (oy / 2) * (a.y <= b.y ? -1 : 1); a.y += d; b.y -= d; }
        else { const d = (ox / 2) * (a.x <= b.x ? -1 : 1); a.x += d; b.x -= d; }
      }
    }
    for (const a of items) {
      a.x = clamp(a.x, a.w / 2 + 4, width - a.w / 2 - 4);
      a.y = clamp(a.y, a.h / 2 + 4, height - a.h / 2 - 4);
    }
    if (!moved) break;
  }
  return items;
}
