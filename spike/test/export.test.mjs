import test from 'node:test';
import assert from 'node:assert/strict';
import { toMapDocument } from '../src/export.mjs';

const m = {
  title: 'Test', frame: { width: 1000, height: 800 }, generator: 'mappulse-spike/0.0.5', synthetic: true,
  style: { water: '#DCE8EA', zone: '#DCCFB6', labelSize: 11.5 },
  land: [{ mp: [[[[0, 0], [10, 0], [10, 10], [0, 0]]]] }], contextBase: [], context: [],
  water: [], waterDrawn: [], roadsPrimary: [], roadsSecondary: [[[0, 0], [5, 5]]], waterLabels: [],
  zones: [{ name: 'Old Northeast', slug: 'old-northeast', type: 'neighborhood', layer: 'neighborhoods',
    sourceName: 'HISTORIC OLD NORTHEAST NBRHD ASSN', mp: [[[[1, 1], [9, 1], [9, 9], [1, 1]]]],
    lines: ['OLD', 'NORTHEAST'], fontSize: 10, label: { x: 5, y: 5 }, anchor: { x: 5, y: 5 }, leader: false }],
};

test('document has schema 1 and one topology entry per zone', () => {
  const doc = toMapDocument(m);
  assert.equal(doc.schema, 1);
  assert.deepEqual(doc.frame, { width: 1000, height: 800 });
  assert.deepEqual(doc.zones.map((z) => z.id), ['old-northeast']);
  assert.equal(doc.topology.zones['old-northeast'].d, 'M1 1L9 1L9 9Z');
  assert.deepEqual(doc.topology.zones['old-northeast'].label, { x: 5, y: 5, size: 10, lines: ['OLD', 'NORTHEAST'], anchor: null });
  assert.equal(doc.topology.layers.roadsMinor, 'M0 0L5 5');
  assert.equal(doc.meta.synthetic, true);
});

test('path data uses only path commands, digits, spaces, dots and minus signs', () => {
  const doc = toMapDocument(m);
  for (const d of [...Object.values(doc.topology.layers), doc.topology.zones['old-northeast'].d]) assert.match(d, /^[MLZ0-9 .\-]*$/);
});
