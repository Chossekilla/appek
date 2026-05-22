<?php
/**
 * ✏️ INLINE EDIT — bezpečný update jednoho pole jednoho záznamu.
 *
 * POST { table, id, field, value }
 *   → strict whitelist tabulek/polí
 *   → vrátí { ok, value }
 *
 * Whitelist je explicitní — žádné dynamické SQL bez kontroly.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Pouze POST', 405);

// ════════════════════════════════════════════════════════════
// WHITELIST — table → field → type
// ════════════════════════════════════════════════════════════
function inline_edit_whitelist(): array {
    return [
        'vyrobky' => [
            'nazev'        => 'string',
            'cislo'        => 'string',
            'ean'          => 'string',
            'cena_bez_dph' => 'number',
            'hmotnost_g'   => 'number',
            'aktivni'      => 'bool',
        ],
        'suroviny' => [
            'nazev'        => 'string',
            'cena_baleni'  => 'number',
            'obsah_baleni' => 'number',
            'sklad_stav'   => 'number',
            'sklad_min'    => 'number',
        ],
        'odberatele' => [
            'nazev'        => 'string',
            'email'        => 'string',
            'telefon'      => 'string',
            'splatnost_dni' => 'number',
            'blokovan'     => 'bool',
        ],
    ];
}

$d = json_input();
$table = $d['table'] ?? '';
$id    = (int) ($d['id'] ?? 0);
$field = $d['field'] ?? '';
$raw   = $d['value'] ?? null;

$wl = inline_edit_whitelist();
if (!isset($wl[$table])) json_error('Tabulka nepovolena k inline editu', 400);
if (!isset($wl[$table][$field])) json_error('Pole nepovoleno k inline editu', 400);
if ($id <= 0) json_error('Neplatné ID', 400);

$type = $wl[$table][$field];
$value = null;

if ($raw === '' || $raw === null) {
    $value = null;
} elseif ($type === 'number') {
    $s = preg_replace('/[^\d,.\-]/', '', (string) $raw);
    $s = str_replace(',', '.', $s);
    if ($s === '' || !is_numeric($s)) json_error('Hodnota není číslo', 400);
    $value = (float) $s;
} elseif ($type === 'bool') {
    $value = (in_array($raw, [1, '1', true, 'true', 'on'], true)) ? 1 : 0;
} else {
    $value = trim((string) $raw);
    if (strlen($value) > 500) json_error('Hodnota je moc dlouhá', 400);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare("UPDATE `$table` SET `$field` = :v WHERE id = :id");
    $stmt->execute(['v' => $value, 'id' => $id]);
    json_response(['ok' => true, 'value' => $value]);
} catch (Throwable $e) {
    json_error('DB chyba: ' . $e->getMessage(), 500);
}
