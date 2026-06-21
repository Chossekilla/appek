// =============================================================
// 💀 SKELETON LOADERS — bone-shape placeholders
// =============================================================
window.skeletonTable = function(rows = 6) {
  let html = '<div class="skel-table-wrap">';
  for (let i = 0; i < rows; i++) {
    html += `
      <div class="skel-table-row">
        <span class="skel"></span>
        <span class="skel"></span>
        <span class="skel"></span>
        <span class="skel"></span>
        <span class="skel"></span>
        <span class="skel"></span>
      </div>
    `;
  }
  return html + '</div>';
};
window.skeletonCards = function(count = 4) {
  let html = '';
  for (let i = 0; i < count; i++) {
    html += `
      <div class="skel-card">
        <span class="skel"></span>
        <span class="skel"></span>
        <span class="skel"></span>
      </div>
    `;
  }
  return html;
};
window.skeletonLine = function(width = '60%', height = '14px') {
  return `<span class="skel" style="width:${width};height:${height};display:inline-block"></span>`;
};

