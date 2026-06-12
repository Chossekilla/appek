<?php
require_once __DIR__ . '/config.php';
cors_headers();

// 🐛 v3.0.285 — endpoint volá i ADMIN (úprava dodacího listu → výběr pobočky cílového
// odběratele přes ?odberatel_id=). Dřív tvrdě require_odberatel() → admin dostal
// 401 „Vyžadováno přihlášení". Admin session smí číst pobočky LIBOVOLNÉHO odběratele
// (query param); B2B session dál vidí JEN svoje (param se ignoruje).
session_secure_start();
$paramId = (int) ($_GET['odberatel_id'] ?? 0);
if (!empty($_SESSION['admin_id']) && $paramId > 0) {
    $odberatel_id = $paramId;             // admin čte pobočky zvoleného odběratele
} else {
    $odberatel_id = require_odberatel();  // B2B (i při souběžné admin session) vidí jen svoje
}

$stmt = db()->prepare("
    SELECT id, nazev, ulice, mesto, psc, kontaktni_osoba, telefon, cas_dodani, vychozi
    FROM mista_dodani
    WHERE odberatel_id = :id AND aktivni = 1
    ORDER BY vychozi DESC, poradi, nazev
");
$stmt->execute(['id' => $odberatel_id]);
json_response($stmt->fetchAll());
