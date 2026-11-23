// Map document export. The plugin stores this JSON and draws only from its "topology" block.
import { pathOfMP, pathOfLines } from './svg.mjs';

const r1 = (n) => Math.round(n * 10) / 10;
const join = (items) => items.map((it) => pathOfMP(it.mp)).join('');

/** Turn the render model that build.mjs assembles into a map document (schema 1). */
export function toMapDocument(m) {
  const zones = m.zones.filter((z) => z.label);
  return {
    schema: 1,
    title: m.title,
    frame: { width: m.frame.width, height: m.frame.height },
    style: { ...m.style },
    zones: zones.map((z) => ({
      id: z.slug, name: z.name, type: z.type,
      source: { layer: z.layer, record: z.sourceName },
      link: '', text: '',
    })),
    topology: {
      layers: {
        land: join(m.land),
        cities: join(m.contextBase || []),
        areas: join(m.context),
        water: pathOfMP(m.waterDrawn || []),
        roadsMinor: pathOfLines(m.roadsSecondary),
        roadsMajor: pathOfLines(m.roadsPrimary),
      },
      zones: Object.fromEntries(zones.map((z) => [z.slug, {
        d: pathOfMP(z.mp),
        label: {
          x: r1(z.label.x), y: r1(z.label.y), size: z.fontSize || m.style.labelSize, lines: z.lines,
          anchor: z.leader && z.anchor ? { x: r1(z.anchor.x), y: r1(z.anchor.y) } : null,
        },
      }])),
      waterLabels: m.waterLabels.map((w) => ({ text: w.name.toUpperCase(), x: r1(w.x), y: r1(w.y), vertical: !!w.vertical })),
    },
    meta: { generator: m.generator, synthetic: !!m.synthetic },
  };
}
