#!/usr/bin/env node
// MapPulse spike, step 1: download the raw boundary layers named in the config.
// Needs Node 18+ (built-in fetch) and open internet access. No dependencies.
// Usage: node scripts/fetch.mjs config/st-petersburg.json
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';

const UA = 'MapPulse-spike/0.0.1 (Agent View Digital; boundary fetch)';
const cfgPath = process.argv[2] || 'config/st-petersburg.json';
const cfg = JSON.parse(await readFile(cfgPath, 'utf8'));
const outDir = path.join('data', 'raw');
await mkdir(outDir, { recursive: true });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const TIMEOUT_MS = Number(process.env.MAPPULSE_TIMEOUT_MS || 120000);

async function getJson(url) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const res = await fetch(url, { headers: { 'User-Agent': UA, Accept: 'application/json' }, signal: AbortSignal.timeout(TIMEOUT_MS) });
      const text = await res.text();
      if (!res.ok) throw new Error(`HTTP ${res.status} ${text.slice(0, 200)}`);
      const json = JSON.parse(text);
      if (json.error) throw new Error(`Service error ${JSON.stringify(json.error).slice(0, 300)}`);
      return json;
    } catch (err) {
      if (attempt === 3) throw err;
      console.log(`    retry ${attempt} after error: ${err.cause ? err.cause.code || err.cause.message : err.message}`);
      await sleep(1500 * attempt);
    }
  }
}

/** Pick the layer whose name matches; fall back to the configured id. */
function pickLayer(list, src) {
  const re = new RegExp(src.layerName, 'i');
  const hits = list.filter((l) => !l.group && re.test(l.name));
  if (hits.length) return hits[hits.length - 1]; // the last match is the most detailed scale on TIGERweb
  if (src.layerId !== null && src.layerId !== undefined) {
    return list.find((l) => l.id === src.layerId) || { id: src.layerId, name: `(layer ${src.layerId}, name not confirmed)` };
  }
  return null;
}

async function fetchLayer(src, layerId) {
  const [w, s, e, n] = cfg.fetchBbox;
  const pageSize = 1000;
  const features = [];
  for (let offset = 0; offset < 200000; offset += pageSize) {
    const q = new URLSearchParams({
      where: '1=1',
      outFields: '*',
      returnGeometry: 'true',
      outSR: '4326',
      geometryPrecision: '6',
      resultOffset: String(offset),
      resultRecordCount: String(pageSize),
      f: 'geojson',
    });
    if (src.useBbox) {
      q.set('geometry', `${w},${s},${e},${n}`);
      q.set('geometryType', 'esriGeometryEnvelope');
      q.set('inSR', '4326');
      q.set('spatialRel', 'esriSpatialRelIntersects');
    }
    if (src.maxAllowableOffset) q.set('maxAllowableOffset', String(src.maxAllowableOffset));
    const page = await getJson(`${src.service}/${layerId}/query?${q}`);
    const got = page.features || [];
    features.push(...got);
    const more = page.exceededTransferLimit || (page.properties && page.properties.exceededTransferLimit);
    console.log(`    ${got.length} features (total ${features.length})`);
    if (!more || got.length === 0) break;
    await sleep(400);
  }
  return { type: 'FeatureCollection', features };
}

const meta = { synthetic: false, fetchedAt: new Date().toISOString(), config: cfgPath, layers: {}, services: {} };
let failed = 0;
for (const src of cfg.sources) {
  console.log(`\n[${src.id}] ${src.service}`);
  try {
    if (!meta.services[src.service]) {
      const svc = await getJson(`${src.service}?f=json`);
      meta.services[src.service] = (svc.layers || []).map((l) => ({ id: l.id, name: l.name, group: !!(l.subLayerIds && l.subLayerIds.length) }));
    }
    const layer = pickLayer(meta.services[src.service], src);
    if (!layer) throw new Error(`No layer matches /${src.layerName}/ and no layerId is set`);
    console.log(`    using layer ${layer.id}: ${layer.name}`);
    const fc = await fetchLayer(src, layer.id);
    await writeFile(path.join(outDir, `${src.id}.geojson`), JSON.stringify(fc));
    const props = fc.features[0] ? Object.keys(fc.features[0].properties || {}) : [];
    meta.layers[src.id] = { layerId: layer.id, layerName: layer.name, features: fc.features.length, fields: props, license: src.license };
  } catch (err) {
    failed++;
    meta.layers[src.id] = { error: err.message };
    console.log(`    FAILED: ${err.message}`);
  }
  await sleep(500);
}
await writeFile(path.join(outDir, '_meta.json'), JSON.stringify(meta, null, 2));

console.log('\nSummary');
for (const [id, m] of Object.entries(meta.layers)) {
  console.log(`  ${id.padEnd(16)} ${m.error ? 'FAILED: ' + m.error : m.features + ' features  (layer ' + m.layerId + ': ' + m.layerName + ')'}`);
}
if (failed === cfg.sources.length) {
  console.log('\nNothing was downloaded. Check the internet connection (or a firewall or VPN) and run "npm run fetch" again.');
  process.exit(1);
}
console.log(`\nSaved to ${outDir}. ${failed ? failed + ' layer(s) failed; the build still runs with what arrived.' : 'All layers downloaded.'}`);
console.log('Next: npm run build');
