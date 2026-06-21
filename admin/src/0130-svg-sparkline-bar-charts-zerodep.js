// =============================================================
// 📊 SVG SPARKLINE + BAR CHARTS (zero-dep)
// =============================================================
// 🆕 v2.9.248 — Sparkline upgrade: smooth bezier curve, glow, peak dot
window.sparklineSVG = function(data, opts = {}) {
  const {w = 280, h = 60, color = '#BA7517'} = opts;
  if (!data || data.length < 2) return '';  // tiše skryj (žádný šum)
  const min = Math.min(...data);
  const max = Math.max(...data);
  const range = max - min || 1;
  const stepX = w / (data.length - 1);
  const points = data.map((v, i) => [i * stepX, h - ((v - min) / range) * (h - 8) - 4]);

  // Smooth catmull-rom curve (vypadá hezky organicky, ne lomeno)
  let linePath = `M${points[0][0].toFixed(1)},${points[0][1].toFixed(1)}`;
  for (let i = 0; i < points.length - 1; i++) {
    const p0 = points[i - 1] || points[i];
    const p1 = points[i];
    const p2 = points[i + 1];
    const p3 = points[i + 2] || p2;
    const cp1x = p1[0] + (p2[0] - p0[0]) / 6;
    const cp1y = p1[1] + (p2[1] - p0[1]) / 6;
    const cp2x = p2[0] - (p3[0] - p1[0]) / 6;
    const cp2y = p2[1] - (p3[1] - p1[1]) / 6;
    linePath += ` C${cp1x.toFixed(1)},${cp1y.toFixed(1)} ${cp2x.toFixed(1)},${cp2y.toFixed(1)} ${p2[0].toFixed(1)},${p2[1].toFixed(1)}`;
  }
  const areaPath = linePath + ` L${w},${h} L0,${h} Z`;
  const last = points[points.length - 1];

  // Najdi peak (highest) — visually highlighted
  let peakIdx = 0;
  for (let i = 1; i < data.length; i++) if (data[i] > data[peakIdx]) peakIdx = i;
  const peak = points[peakIdx];

  // Unique gradient ID (multiple sparkliny na stránce)
  const gid = 'spk-' + Math.random().toString(36).slice(2, 8);

  return `
    <svg class="chart-svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" style="display:block;width:100%;height:${h}px;overflow:visible">
      <defs>
        <linearGradient id="${gid}" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stop-color="${color}" stop-opacity="0.35"/>
          <stop offset="100%" stop-color="${color}" stop-opacity="0"/>
        </linearGradient>
      </defs>
      <path d="${areaPath}" fill="url(#${gid})"/>
      <path d="${linePath}" fill="none" stroke="${color}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="filter:drop-shadow(0 1px 2px ${color}33)"/>
      ${peakIdx !== data.length - 1 ? `<circle cx="${peak[0].toFixed(1)}" cy="${peak[1].toFixed(1)}" r="2" fill="${color}" opacity="0.5"/>` : ''}
      <circle cx="${last[0].toFixed(1)}" cy="${last[1].toFixed(1)}" r="3" fill="${color}" stroke="#fff" stroke-width="1.5"/>
    </svg>
  `;
};

window.barsSVG = function(data, opts = {}) {
  const {fmt: fmtFn = (v) => v} = opts;
  if (!data || data.length === 0) return '<div style="font-size:11px;color:#aaa;padding:14px">Žádná data</div>';
  const max = Math.max(...data.map(d => d.value));
  return `
    <div class="chart-bars" style="margin-bottom:24px">
      ${data.map(d => {
        const pct = max > 0 ? (d.value / max) * 100 : 0;
        return `
          <div class="chart-bar" style="height:${pct}%" title="${esc(d.label)}: ${fmtFn(d.value)}">
            <div class="chart-bar-value">${fmtFn(d.value)}</div>
            <div class="chart-bar-label">${esc((d.label || '').slice(0, 12))}</div>
          </div>
        `;
      }).join('')}
    </div>
  `;
};

