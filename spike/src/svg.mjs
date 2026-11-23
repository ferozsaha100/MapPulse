// SVG writer for the MapPulse spike.
const fmt = (n) => { const s = n.toFixed(1); return s.endsWith('.0') ? s.slice(0, -2) : s; };
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

export const pathOfMP = (mp) =>
  mp.map((poly) => poly.map((ring) => 'M' + ring.slice(0, -1).map((p) => `${fmt(p[0])} ${fmt(p[1])}`).join('L') + 'Z').join('')).join('');
export const pathOfLines = (lines) =>
  lines.filter((l) => l.length > 1).map((line) => 'M' + line.map((p) => `${fmt(p[0])} ${fmt(p[1])}`).join('L')).join('');

function compass(x, y, ink) {
  return (
    `<g transform="translate(${x} ${y})" aria-hidden="true"><circle r="22" fill="#fff" fill-opacity=".8" stroke="${ink}" stroke-width="1"/>` +
    `<path d="M0 -17L5 0L0 17L-5 0Z" fill="#fff" stroke="${ink}" stroke-width="1" stroke-linejoin="round"/><path d="M0 -17L5 0H-5Z" fill="${ink}"/>` +
    `<text y="-27" text-anchor="middle" font-size="10" font-weight="700" fill="${ink}">N</text></g>`
  );
}

/**
 * @param {object} m frame {width,height}, land[], context[], zones[], water[], roadsPrimary, roadsSecondary, waterLabels[], style, synthetic
 */
export function renderSvg(m) {
  const s = m.style;
  const { width: W, height: H } = m.frame;
  const css = [
    `.mp-map{font-family:${s.labelFont}}`,
    `.mp-ctx{fill:${s.context};stroke:${s.contextStroke};stroke-width:1;stroke-linejoin:round}`,
    `.mp-zone{fill:${s.zone};stroke:${s.zoneStroke};stroke-width:1.6;stroke-linejoin:round;cursor:pointer;transition:fill .18s ease}`,
    `.mp-zone-link:hover .mp-zone,.mp-zone-link:focus-visible .mp-zone{fill:${s.zoneHover}}`,
    `.mp-zone-link:focus-visible{outline:none}`,
    `.mp-water{fill:${s.water};stroke:${s.coast};stroke-width:.8;stroke-linejoin:round}`,
    `.mp-label{font-size:${s.labelSize}px;font-weight:600;letter-spacing:1.1px;fill:${s.label};stroke:#fff;stroke-opacity:.72;stroke-width:2.6px;paint-order:stroke;stroke-linejoin:round;pointer-events:none;transition:fill .18s ease}`,
    `.mp-wlabel{font-size:12px;font-style:italic;letter-spacing:3px;fill:${s.waterLabel};pointer-events:none}`,
    `.mp-layer-top{pointer-events:none}`,
    ...m.zones.map((z) => `.mp-map:has(#z-${z.slug}:hover) #l-${z.slug},.mp-map:has(#z-${z.slug}:focus-visible) #l-${z.slug}{fill:#fff;stroke:${s.zoneHover};stroke-opacity:1}`),
  ].join('');
  const o = [];
  o.push(`<svg class="mp-map" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${W} ${H}" role="group" aria-label="${esc(m.title)}">`);
  o.push(`<style>${css}</style>`);
  o.push(`<rect width="${W}" height="${H}" fill="${s.water}"/>`);
  o.push(`<g class="mp-land"><path d="${m.land.map((f) => pathOfMP(f.mp)).join('')}" fill="${s.land}" fill-rule="evenodd"/></g>`);
  o.push('<g class="mp-context">' + m.context.map((c) => `<path class="mp-ctx" d="${pathOfMP(c.mp)}" fill-rule="evenodd"><title>${esc(c.name || '')}</title></path>`).join('') + '</g>');
  o.push('<g class="mp-zones">' + m.zones.map((z) =>
    `<a class="mp-zone-link" id="z-${z.slug}" href="#${z.slug}" aria-label="${esc(z.name)}"><path class="mp-zone" d="${pathOfMP(z.mp)}" fill-rule="evenodd"/></a>`).join('') + '</g>');
  o.push('<g class="mp-layer-top">');
  o.push(`<path class="mp-water" d="${m.water.map((f) => pathOfMP(f.mp)).join('')}" fill-rule="evenodd"/>`);
  for (const [lines, casing, inner] of [[m.roadsSecondary, 3.2, 1.6], [m.roadsPrimary, 5.4, 3]]) {
    const d = pathOfLines(lines);
    if (!d) continue;
    o.push(`<path d="${d}" fill="none" stroke="${s.roadCasing}" stroke-width="${casing}" stroke-linecap="round" stroke-linejoin="round"/>`);
    o.push(`<path d="${d}" fill="none" stroke="${s.road}" stroke-width="${inner}" stroke-linecap="round" stroke-linejoin="round"/>`);
  }
  for (const wl of m.waterLabels) {
    const place = wl.vertical ? `transform="translate(${fmt(wl.x)} ${fmt(wl.y)}) rotate(90)"` : `x="${fmt(wl.x)}" y="${fmt(wl.y)}"`;
    o.push(`<text class="mp-wlabel" ${place} text-anchor="middle">${esc(wl.name.toUpperCase())}</text>`);
  }
  for (const z of m.zones) {
    if (!z.label) continue;
    const lh = s.labelSize * 1.18;
    const y0 = z.label.y - ((z.lines.length - 1) * lh) / 2 + s.labelSize * 0.36;
    o.push(`<text class="mp-label" id="l-${z.slug}" text-anchor="middle">` +
      z.lines.map((line, i) => `<tspan x="${fmt(z.label.x)}" y="${fmt(y0 + i * lh)}">${esc(line)}</tspan>`).join('') + '</text>');
  }
  o.push(compass(W - 46, 52, s.label));
  if (m.synthetic) {
    o.push(`<text x="${W / 2}" y="${H - 14}" text-anchor="middle" font-size="12" font-weight="700" letter-spacing="2" fill="#9A3412">SAMPLE DATA, NOT REAL BOUNDARIES</text>`);
  }
  o.push('</g></svg>');
  return o.join('');
}

export function renderPreview({ svg, title, zones, report, synthetic }) {
  const rows = zones.map((z) => `<li><a href="#${z.slug}" data-zone="${z.slug}">${esc(z.name)}<span>${esc(z.type)}</span></a></li>`).join('');
  const warn = report.warnings.length ? `<h2>Warnings</h2><ul class="w">${report.warnings.map((w) => `<li>${esc(w)}</li>`).join('')}</ul>` : '';
  return `<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${esc(title)} | MapPulse spike</title>
<style>
body{margin:0;background:#FBF8F1;color:#1B2430;font:15px/1.5 Inter,Helvetica,Arial,sans-serif}
main{max-width:1240px;margin:0 auto;padding:32px 24px 56px;display:flex;flex-wrap:wrap;gap:32px;align-items:flex-start}
aside{flex:1 1 240px;min-width:0}
.map{flex:999 1 560px;min-width:0;border-radius:10px;overflow:hidden;box-shadow:0 12px 32px rgba(16,34,58,.12)}
.map svg{display:block;width:100%;height:auto}
h1{font-size:26px;line-height:1.2;margin:0 0 6px}h2{font-size:13px;letter-spacing:.08em;text-transform:uppercase;margin:24px 0 8px;color:#55606E}
p{margin:0 0 12px;color:#55606E}
ul{list-style:none;margin:0;padding:0}
li a{display:flex;justify-content:space-between;gap:12px;padding:8px 10px;border-bottom:1px solid #E2DCCB;color:#1B2430;text-decoration:none;border-radius:4px}
li a span{color:#6B7480;font-size:12.5px}
li a:hover,li a.on{background:#1F3A5F;color:#fff}li a:hover span,li a.on span{color:#DCE8EA}
.w li{padding:6px 0;border-bottom:1px solid #E2DCCB;color:#8A5200;font-size:13.5px}
.flag{display:inline-block;background:#FFF3D6;color:#8A5200;font-weight:600;font-size:12.5px;padding:3px 9px;border-radius:999px;margin-bottom:10px}
</style></head><body><main>
<aside>${synthetic ? '<span class="flag">Sample data, not real boundaries</span>' : ''}
<h1>${esc(title)}</h1>
<p>${report.zonesMatched} of ${report.zonesConfigured} zones matched. ${report.contextAreas} surrounding areas. SVG ${Math.round(report.svgBytes / 1024)} KB.</p>
<h2>Zones</h2><ul>${rows}</ul>${warn}</aside>
<div class="map">${svg}</div>
</main>
<script>
document.querySelectorAll('a[data-zone]').forEach(function (a) {
  var z = document.getElementById('z-' + a.dataset.zone); if (!z) return;
  var path = z.querySelector('path'), label = document.getElementById('l-' + a.dataset.zone);
  a.addEventListener('mouseenter', function () { path.style.fill = '#1F3A5F'; if (label) { label.style.fill = '#fff'; label.style.stroke = '#1F3A5F'; } });
  a.addEventListener('mouseleave', function () { path.style.fill = ''; if (label) { label.style.fill = ''; label.style.stroke = ''; } });
  z.addEventListener('mouseenter', function () { a.classList.add('on'); });
  z.addEventListener('mouseleave', function () { a.classList.remove('on'); });
});
</script></body></html>`;
}
