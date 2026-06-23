<?php
/**
 * 🔑 LICENSES — Seznam vydaných licencí + nástroje (generate, revoke, audit).
 *
 * Tahle stránka obsahuje to, co bylo původně v index.php — tabulku licencí.
 * (index.php je teď pure dashboard s kartami.)
 */
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_layout.php';

$user = vendor_require_login();
$currentPage = 'licenses';
?><!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>🔑 Licence — APPEK Master</title>
<link rel="stylesheet" href="style.css?v=1.5">
<style>
  .toolbar { background: #fff; border-radius: 12px; padding: 14px 18px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
  .filter-row { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
  .filter-row .search-input {
    flex: 1; min-width: 220px; padding: 9px 14px;
    border: 1px solid #d2d2d7; border-radius: 9px; font-size: 13.5px; font-family: inherit;
  }
  .filter-row .filter-select { padding: 9px 12px; border: 1px solid #d2d2d7; border-radius: 9px; font-size: 13px; font-family: inherit; }
  .filter-row .btn-icon { width: 38px; height: 38px; border: 1px solid #d2d2d7; background: #fff; border-radius: 9px; cursor: pointer; font-size: 15px; }
  .filter-row .btn-icon:hover { background: #f5f5f7; }
  .table-wrap { background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.04); }
  .lic-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .lic-table th, .lic-table td { padding: 11px 14px; text-align: left; border-bottom: 1px solid #f0f0f3; }
  .lic-table th { background: #fafafa; font-weight: 700; color: #6e6e73; text-transform: uppercase; letter-spacing: 0.4px; font-size: 11px; }
  .lic-table .loading { text-align: center; color: #86868b; padding: 40px; }
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px; }
</style>
</head>
<body>

<?php vendor_render_topbar($user, $currentPage); ?>
<?php vendor_render_back(); ?>

<main class="page-master">

  <div class="page-header-master">
    <h1>🔑 Licence</h1>
    <div style="font-size:13px;color:#86868b">Vydané klíče pro zákaznické instalace</div>
  </div>

  <!-- STATS -->
  <section class="stats-grid" id="stats">⏳ Načítám…</section>

  <!-- TOOLBAR -->
  <section class="toolbar">
    <div class="filter-row">
      <input type="text" id="search" placeholder="🔍 Hledat zákazníka / klíč / email…" class="search-input">
      <select id="status-filter" class="filter-select">
        <option value="">Všechny stavy</option>
        <option value="active">✅ Aktivní</option>
        <option value="expired">⏰ Expirované</option>
        <option value="revoked">🚫 Revoked</option>
      </select>
      <button class="btn-master primary" onclick="openGenerateModal()">+ Vygenerovat klíč</button>
      <button class="btn-icon" onclick="openAuditLog()" title="Audit log">📜</button>
    </div>
  </section>

  <!-- TABLE -->
  <section class="table-wrap">
    <table class="lic-table" id="lic-table">
      <thead>
        <tr>
          <th>Stav</th>
          <th>Klíč</th>
          <th>Zákazník</th>
          <th>🎁 Balíčky</th>
          <th>Kontakt</th>
          <th>Vydáno</th>
          <th>Expirace</th>
          <th>Cena</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="lic-body">
        <tr><td colspan="9" class="loading">⏳ Načítám…</td></tr>
      </tbody>
    </table>
  </section>
</main>

<!-- MODAL CONTAINER -->
<div id="modal" class="modal-backdrop hidden" onclick="if(event.target===this)closeModal()">
  <div class="modal-card">
    <div class="modal-head">
      <h3 id="modal-title">Modal</h3>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div id="modal-body" class="modal-body"></div>
  </div>
</div>

<script src="i18n.js?v=1.0"></script>
<script src="app.js?v=1.5"></script>

<?php vendor_render_footer(); ?>
</body>
</html>
