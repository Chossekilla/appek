// =============================================================
// 🖱️ DRAG & DROP HANDLERS
// =============================================================
function rtAttachDragHandlers() {
  const canvas = document.getElementById('rt-canvas');
  if (!canvas) return;
  canvas.querySelectorAll('.rt-table-tile').forEach(tile => {
    tile.addEventListener('pointerdown', rtTableDragStart);
  });
}

function rtTableDragStart(e) {
  if (!rtState.editMode) return;
  // Ignore click on delete button
  if (e.target.textContent === '✕') return;
  e.preventDefault();
  const tile = e.currentTarget;
  const canvas = document.getElementById('rt-canvas');
  const tileRect = tile.getBoundingClientRect();      // post-transform (screen px)
  const canvasRect = canvas.getBoundingClientRect();  // post-transform (screen px)
  // 🆕 v3.0.84 — Canvas může být CSS-scaled (transform:scale). Detect live scale
  // a převeď drag deltas zpět do canvas-space coords (jinak by se tile placeoval špatně).
  const scale = canvas.offsetWidth ? (canvasRect.width / canvas.offsetWidth) : 1;
  rtState.draggedTableId = parseInt(tile.dataset.id);
  rtState.dragStart = {
    offsetX: e.clientX - tileRect.left,
    offsetY: e.clientY - tileRect.top,
    canvasLeft: canvasRect.left,
    canvasTop: canvasRect.top,
    canvasW: canvas.offsetWidth,    // UNSCALED canvas-space (px coords v DB)
    canvasH: canvas.offsetHeight,
    tileW: tile.offsetWidth,        // UNSCALED tile-space
    tileH: tile.offsetHeight,
    scale: scale || 1,
  };
  tile.style.cursor = 'grabbing';
  tile.style.zIndex = '999';
  tile.style.opacity = '0.85';
  document.addEventListener('pointermove', rtTableDragMove);
  document.addEventListener('pointerup', rtTableDragEnd, { once: true });
}

function rtTableDragMove(e) {
  if (rtState.draggedTableId === null || !rtState.dragStart) return;
  e.preventDefault();
  const s = rtState.dragStart;
  const tile = document.querySelector(`.rt-table-tile[data-id="${rtState.draggedTableId}"]`);
  if (!tile) return;
  // 🆕 v3.0.84 — divide screen-px delta by scale → canvas-space coords (DB-correct)
  let x = (e.clientX - s.canvasLeft - s.offsetX) / s.scale;
  let y = (e.clientY - s.canvasTop - s.offsetY) / s.scale;
  // Snap to grid 20px
  x = Math.round(x / 20) * 20;
  y = Math.round(y / 20) * 20;
  // Clamp do canvasu (UNSCALED dims = DB coord space)
  x = Math.max(0, Math.min(x, s.canvasW - s.tileW));
  y = Math.max(0, Math.min(y, s.canvasH - s.tileH));
  tile.style.left = x + 'px';
  tile.style.top = y + 'px';
  tile.dataset.x = x;
  tile.dataset.y = y;
}

function rtTableDragEnd(e) {
  document.removeEventListener('pointermove', rtTableDragMove);
  const id = rtState.draggedTableId;
  const tile = document.querySelector(`.rt-table-tile[data-id="${id}"]`);
  if (tile) {
    tile.style.cursor = 'grab';
    tile.style.zIndex = '';
    tile.style.opacity = '';
    // Update state, mark dirty
    const t = (state._rtData?.stoly || []).find(s => s.id === id);
    if (t) {
      const newX = parseInt(tile.dataset.x);
      const newY = parseInt(tile.dataset.y);
      if (newX !== parseInt(t.x) || newY !== parseInt(t.y)) {
        t.x = newX;
        t.y = newY;
        rtState.dirtyTables.add(id);
        // Re-render aby se ukázal save button + outline
        renderRestaurantTables();
      }
    }
  }
  rtState.draggedTableId = null;
  rtState.dragStart = null;
}

