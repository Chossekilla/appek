// =============================================================
// ✏️ INLINE EDITING — generic helper
// =============================================================
// Použití v HTML:
//   <span class="inline-edit" data-inline-table="vyrobky" data-inline-id="123" data-inline-field="cena_bez_dph" data-inline-type="number">${fmt(v.cena_bez_dph)}</span>
// Click → input. Enter/Blur → save. Esc → cancel.
// Backend volá: PUT /api/admin_inline_edit.php { table, id, field, value }
(function() {
  document.addEventListener('click', async (e) => {
    const el = e.target.closest('.inline-edit:not(.is-editing)');
    if (!el) return;
    e.stopPropagation();

    const table = el.dataset.inlineTable;
    const id    = el.dataset.inlineId;
    const field = el.dataset.inlineField;
    const type  = el.dataset.inlineType || 'text';
    if (!table || !id || !field) return;

    const origHtml = el.innerHTML;
    const rawValue = el.dataset.inlineValue ?? el.textContent.trim();
    el.classList.add('is-editing');
    el.innerHTML = `<input type="${type === 'number' ? 'number' : 'text'}" ${type === 'number' ? 'step="0.01" min="0"' : ''} value="${esc(rawValue.replace(/[^\d.,\-]/g, '').replace(',', '.'))}">`;
    const input = el.querySelector('input');
    input.focus();
    input.select();

    let saved = false;
    const cancel = () => { if (saved) return; el.classList.remove('is-editing'); el.innerHTML = origHtml; };
    const save = async () => {
      if (saved) return;
      saved = true;
      const newVal = input.value;
      el.innerHTML = `${origHtml}<span class="inline-edit-spinner">⏳</span>`;
      try {
        await api('admin_inline_edit.php', {
          method: 'POST',
          body: JSON.stringify({ table, id: parseInt(id), field, value: newVal }),
        });
        // Refresh: callback nebo prostě reload tabulky
        el.classList.remove('is-editing');
        const display = type === 'number' ? fmt(parseFloat(newVal)) : esc(newVal);
        el.innerHTML = display;
        el.dataset.inlineValue = newVal;
        toastSuccess('Změna uložena');
      } catch (err) {
        el.classList.remove('is-editing');
        el.innerHTML = origHtml;
        toastError('Chyba: ' + err.message);
      }
    };
    input.onkeydown = (e) => {
      if (e.key === 'Enter') { e.preventDefault(); save(); }
      else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
    };
    input.onblur = () => save();
  });
})();

