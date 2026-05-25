<?php
require_once __DIR__ . '/config.php';
cors_headers();
$odberatel_id = require_odberatel();

$stmt = db()->prepare("
    SELECT id, nazev, ulice, mesto, psc, kontaktni_osoba, telefon, cas_dodani, vychozi
    FROM mista_dodani
    WHERE odberatel_id = :id AND aktivni = 1
    ORDER BY vychozi DESC, poradi, nazev
");
$stmt->execute(['id' => $odberatel_id]);
json_response($stmt->fetchAll());
