// =============================================================
// Edit suroviny z kalkulace — po uložení se vrátí zpět do kalkulace
// =============================================================
window.vkEditSurovina = function(surovina_id) {
  state._sur_return_to_kalkulace = true;
  editSurovina(surovina_id);
};

