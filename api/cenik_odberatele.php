<?php
/**
 * Vrací ceník výrobků s aplikovanými slevami pro daného odběratele.
 *
 * GET ?odberatel_id=N  → array výrobků s upravenými cenami
 *
 * Použití:
 *   - V UI ruční faktury: po výběru odběratele se načte tento endpoint
 *   - Vrátí cena_zakladni (z ceníku) + cena_bez_dph (po slevě) + sleva_pct/pevna_cena
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$odb_id = (int) ($_GET['odberatel_id'] ?? 0);
if (!$odb_id) json_error('Chybí ID odběratele');

$pdo = db();

// Ověř, že odběratel existuje + vrať kompletní info pro UI hlavičku
$stmt = $pdo->prepare("
    SELECT o.id, o.cislo, o.nazev, o.ico, o.dic, o.ulice, o.mesto, o.psc,
           o.email, o.telefon, o.kontaktni_osoba, o.splatnost_dni,
           o.cenova_skupina_id, cs.nazev AS skupina_nazev
    FROM odberatele o
    LEFT JOIN cenove_skupiny cs ON cs.id = o.cenova_skupina_id
    WHERE o.id = :id
");
$stmt->execute(['id' => $odb_id]);
$odb = $stmt->fetch();
if (!$odb) json_error('Odběratel nenalezen', 404);

$cenik = cenik_pro_odberatele($pdo, $odb_id);

// Vrátíme i top-level fields pro snazší přístup z frontendu
// (rdlState.odberatel = data → s.odberatel.nazev pak funguje)
json_response(array_merge($odb, [
    'odberatel'     => $odb,
    'skupina_nazev' => $odb['skupina_nazev'],
    'vyrobky'       => $cenik,
]));
