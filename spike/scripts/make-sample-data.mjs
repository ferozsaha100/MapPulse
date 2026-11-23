#!/usr/bin/env node
// Writes STAND-IN data so the pipeline can be dry-run without network access.
// These shapes are invented. They are not real boundaries. Use "npm run fetch" for real data.
import { writeFile, mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const cfg = JSON.parse(await readFile(process.argv[2] || 'config/st-petersburg.json', 'utf8'));
const outDir = path.join('data', 'raw');
await mkdir(outDir, { recursive: true });

let seed = 20261124;
const rnd = () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296; };
const COLS = 9, ROWS = 10, W0 = -82.762, E0 = -82.598, N0 = 27.852, S0 = 27.7;
const dx = (E0 - W0) / COLS, dy = (N0 - S0) / ROWS;
const node = [];
for (let r = 0; r <= ROWS; r++) {
  node.push([]);
  for (let c = 0; c <= COLS; c++) {
    const edge = r === 0 || r === ROWS || c === 0 || c === COLS;
    node[r].push([+(W0 + c * dx + (edge ? 0 : (rnd() - 0.5) * dx * 0.42)).toFixed(6), +(N0 - r * dy + (edge ? 0 : (rnd() - 0.5) * dy * 0.42)).toFixed(6)]);
  }
}
const named = {
  '8,1': 'Shore Acres Civic Association', '8,4': 'Snell Isle Property Owners', '7,3': 'Coffee Pot Bayou', '6,3': 'Allendale Terrace',
  '6,4': 'Crescent Heights', '6,5': "Euclid St. Paul's", '5,5': 'Woodlawn', '7,5': 'Historic Old Northeast', '4,6': 'Historic Kenwood',
  '7,6': 'Downtown Neighborhood Association', '7,8': 'Old Southeast', '7,9': 'Tropical Shores', '6,9': 'Driftwood',
  '5,9': 'Greater Pinellas Point', '0,5': 'Jungle Prada', '0,7': 'Pasadena Bear Creek', '5,6': 'Woodlawn Oaks', '3,6': 'North Kenwood',
};
const cell = (c, r) => [[node[r][c], node[r][c + 1], node[r + 1][c + 1], node[r + 1][c], node[r][c]]];
const feat = (properties, geometry) => ({ type: 'Feature', properties, geometry });
const poly = (ring) => ({ type: 'Polygon', coordinates: [ring.concat([ring[0]])] });
const fc = (features) => JSON.stringify({ type: 'FeatureCollection', features });

const hoods = [];
let n = 0;
for (let r = 0; r < ROWS; r++) for (let c = 0; c < COLS; c++) {
  if ((c <= 2 && r >= 8) || (c === 8 && r === 0)) continue; // left open: Gulfport and a bay inlet
  n++;
  hoods.push(feat({ OBJECTID: n, NAME: named[`${c},${r}`] || `Sample Area ${n}`, STATUS: 'Active' }, { type: 'Polygon', coordinates: cell(c, r) }));
}
const gulfport = poly([[W0, S0 + dy * 2.6], [W0 + dx * 3, S0 + dy * 2.6], [W0 + dx * 3, S0], [W0, S0]]);
const places = [
  feat({ GEOID: '1227322', BASENAME: 'Gulfport', NAME: 'Gulfport city' }, gulfport),
  feat({ GEOID: '1263000', BASENAME: 'St. Petersburg', NAME: 'St. Petersburg city' }, poly([[W0, N0], [E0, N0], [E0, S0], [W0 + dx * 3, S0], [W0 + dx * 3, S0 + dy * 2.6], [W0, S0 + dy * 2.6]])),
  feat({ GEOID: '1267225', BASENAME: 'South Pasadena', NAME: 'South Pasadena city' }, poly([[W0 - 0.02, 27.745], [W0, 27.745], [W0, 27.73], [W0 - 0.02, 27.73]])),
];
const zcta = [
  feat({ BASENAME: '33701', NAME: 'ZCTA5 33701' }, poly([[-82.66, 27.79], [-82.61, 27.79], [-82.61, 27.75], [-82.66, 27.75]])),
  feat({ BASENAME: '33707', NAME: 'ZCTA5 33707' }, poly([[W0, 27.76], [-82.7, 27.76], [-82.7, S0], [W0, S0]])),
];
const counties = [feat({ BASENAME: 'Pinellas', NAME: 'Pinellas County' }, poly([[-82.95, 28.0], [-82.45, 28.0], [-82.45, 27.55], [-82.95, 27.55]]))];
const wavy = (lon, from, to, steps, amp) => Array.from({ length: steps + 1 }, (_, i) => [+(lon + Math.sin(i * 1.7) * amp).toFixed(6), +(from + ((to - from) * i) / steps).toFixed(6)]);
const east = wavy(E0 - 0.004, 28.0, 27.55, 30, 0.005);
const west = wavy(W0 + 0.003, 27.55, 28.0, 30, 0.004);
const water = [
  feat({ BASENAME: 'Tampa Bay', NAME: 'Tampa Bay', MTFCC: 'H2051' }, poly([...east, [-82.45, 27.55], [-82.45, 28.0]])),
  feat({ BASENAME: 'Boca Ciega Bay', NAME: 'Boca Ciega Bay', MTFCC: 'H2051' }, poly([...west, [-82.95, 28.0], [-82.95, 27.55]])),
  feat({ BASENAME: 'Tampa Bay', NAME: 'Tampa Bay', MTFCC: 'H2051' }, poly([[-82.95, S0 + 0.004], [-82.45, S0 + 0.004], [-82.45, 27.55], [-82.95, 27.55]])),
  feat({ BASENAME: 'Lake Sample', NAME: 'Lake Sample', MTFCC: 'H2030' }, poly(Array.from({ length: 16 }, (_, i) => [+(-82.69 + Math.cos(i / 16 * 6.283) * 0.006).toFixed(6), +(27.8 + Math.sin(i / 16 * 6.283) * 0.004).toFixed(6)]))),
];
const line = (pts) => ({ type: 'LineString', coordinates: pts });
const roadsPrimary = [feat({ NAME: 'I- 275' }, line(Array.from({ length: 25 }, (_, i) => [+(-82.678 + Math.sin(i / 4) * 0.004).toFixed(6), +(28.0 - i * 0.0185).toFixed(6)])))];
const roadsSecondary = [
  feat({ NAME: 'Sample Ave' }, line(Array.from({ length: 25 }, (_, i) => [+(-82.95 + i * 0.02).toFixed(6), +(27.772 + Math.sin(i / 3) * 0.002).toFixed(6)]))),
  feat({ NAME: 'Sample St' }, line(Array.from({ length: 25 }, (_, i) => [+(-82.725 + Math.sin(i / 5) * 0.003).toFixed(6), +(28.0 - i * 0.0185).toFixed(6)]))),
];

const files = { neighborhoods: hoods, places, zcta, counties, water, 'roads-primary': roadsPrimary, 'roads-secondary': roadsSecondary };
for (const [id, features] of Object.entries(files)) await writeFile(path.join(outDir, `${id}.geojson`), fc(features));
await writeFile(path.join(outDir, '_meta.json'), JSON.stringify({ synthetic: true, fetchedAt: null, note: 'Invented stand-in shapes for a dry run. Not real boundaries.' }, null, 2));
console.log(`Wrote stand-in data for ${cfg.name} to ${outDir} (${hoods.length} sample neighborhoods). Not real boundaries.`);
