<?php
/**
 * Hromadný import surovin s cenami.
 *
 * POST body { suroviny: [{nazev, jednotka, alergen, cena_baleni, obsah_baleni, poznamka, slozeni}, ...] }
 *
 * Pro každou položku:
 *   - Pokud nazev (case-insensitive) existuje → UPDATE (jen vyplněná pole, aktivni=1)
 *   - Jinak → INSERT
 *
 * Vrací { ok: true, vytvoreno: N, aktualizovano: M, chyby: [...] }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = json_input();
$items = $body['suroviny'] ?? [];
if (!is_array($items) || count($items) === 0) {
    json_error('Žádné suroviny k importu');
}

// Auto-snapshot zálohy před hromadnou změnou
require_once __DIR__ . '/_zaloha_helper.php';
try {
    zaloha_snapshot($pdo, 'Před importem surovin (' . count($items) . ' ks)');
} catch (Throwable $e) { /* nekritické */ }

$vytvoreno = 0;
$aktualizovano = 0;
$chyby = [];

$pdo->beginTransaction();
try {
    foreach ($items as $idx => $s) {
        $nazev = trim((string) ($s['nazev'] ?? ''));
        if ($nazev === '') {
            $chyby[] = "Řádek $idx: chybí název";
            continue;
        }

        $jednotka     = trim((string) ($s['jednotka'] ?? 'g')) ?: 'g';
        $alergen      = trim((string) ($s['alergen'] ?? '')) ?: null;
        $cena_baleni  = isset($s['cena_baleni']) && $s['cena_baleni'] !== '' ? (float) $s['cena_baleni'] : null;
        $obsah_baleni = isset($s['obsah_baleni']) && $s['obsah_baleni'] !== '' ? (float) $s['obsah_baleni'] : null;
        $poznamka     = trim((string) ($s['poznamka'] ?? '')) ?: null;
        $slozeni      = trim((string) ($s['slozeni'] ?? '')) ?: null;

        // Existuje?
        $exists = $pdo->prepare("SELECT id FROM suroviny WHERE LOWER(nazev) = LOWER(:n) LIMIT 1");
        $exists->execute(['n' => $nazev]);
        $existId = $exists->fetchColumn();

        if ($existId) {
            // UPDATE — přepíšeme jen vyplněná pole
            $sql = "UPDATE suroviny SET aktivni = 1";
            $params = ['id' => (int) $existId];
            if ($jednotka)     { $sql .= ", jednotka = :jednotka";       $params['jednotka']     = $jednotka; }
            if ($alergen)      { $sql .= ", alergen = :alergen";         $params['alergen']      = $alergen; }
            if ($cena_baleni !== null)  { $sql .= ", cena_baleni = :cb"; $params['cb']           = $cena_baleni; }
            if ($obsah_baleni !== null) { $sql .= ", obsah_baleni = :ob"; $params['ob']          = $obsah_baleni; }
            if ($poznamka)     { $sql .= ", poznamka = :poznamka";       $params['poznamka']     = $poznamka; }
            if ($slozeni)      { $sql .= ", slozeni = :slozeni";         $params['slozeni']      = $slozeni; }
            $sql .= " WHERE id = :id";
            $pdo->prepare($sql)->execute($params);
            $aktualizovano++;
        } else {
            // INSERT
            $pdo->prepare("
                INSERT INTO suroviny (nazev, jednotka, alergen, cena_baleni, obsah_baleni, poznamka, slozeni, aktivni)
                VALUES (:nazev, :jednotka, :alergen, :cb, :ob, :poznamka, :slozeni, 1)
            ")->execute([
                'nazev'    => $nazev,
                'jednotka' => $jednotka,
                'alergen'  => $alergen,
                'cb'       => $cena_baleni,
                'ob'       => $obsah_baleni,
                'poznamka' => $poznamka,
                'slozeni'  => $slozeni,
            ]);
            $vytvoreno++;
        }
    }

    $pdo->commit();
    json_response([
        'ok'            => true,
        'vytvoreno'     => $vytvoreno,
        'aktualizovano' => $aktualizovano,
        'celkem'        => count($items),
        'chyby'         => $chyby,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('admin_suroviny_import: ' . $e->getMessage());
    json_error_safe('Import selhal', , 500);
}
