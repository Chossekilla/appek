<?php
/**
 * 🆕 v3.0.5 — Printer library (ESC/POS termo tiskárny)
 *
 * Pro fyzické tiskárny: TCP socket na port 9100, ESC/POS bytes.
 * Pro testování bez hardware: dummy mode = zapíše do souboru v /tmp/.
 *
 * Hlavní funkce:
 *   - printer_ensure_schema()        — auto-create tabulky
 *   - printer_list/get/save/delete() — CRUD
 *   - printer_dispatch_order()       — auto-split objednávky na tiskárny podle kategorie
 *   - printer_print_receipt()        — vytisknout účtenku na kasa
 *   - printer_test_print()           — testovací tisk
 *   - setting_get / setting_set      — pomocné nastavení v tabulce `nastaveni`
 */

// ─────────────────────────────────────────────────────────────
// SCHEMA
// ─────────────────────────────────────────────────────────────
function printer_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS restaurant_printers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nazev VARCHAR(100) NOT NULL,
            typ ENUM('kasa','kuchyne','bar','sklad','vydej','generic') NOT NULL DEFAULT 'generic',
            ip VARCHAR(45) NOT NULL,
            port INT NOT NULL DEFAULT 9100,
            sirka_papiru INT NOT NULL DEFAULT 80,
            encoding VARCHAR(20) DEFAULT 'cp852',
            aktivni TINYINT(1) NOT NULL DEFAULT 1,
            poznamka VARCHAR(255) DEFAULT NULL,
            posledni_tisk DATETIME NULL,
            posledni_chyba TEXT NULL,
            pocet_tisku INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_typ_aktivni (typ, aktivni)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Přidej printer_id do kategorie_vyrobku (legacy mapování kategorie → tiskárna; ponecháno kvůli kompatibilitě)
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM kategorie_vyrobku")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('printer_id', $cols, true)) {
            $pdo->exec("ALTER TABLE kategorie_vyrobku ADD COLUMN printer_id INT NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE kategorie_vyrobku ADD INDEX idx_printer (printer_id)");
        }
    } catch (Throwable $e) { /* tabulka možná ještě neexistuje */ }

    // 🆕 v3.0.200 — routing bonů podle KUCHYŇSKÉ STANICE: stanice má svou tiskárnu.
    //   Sloupec přidán idempotentně (jen když tabulka stanic už existuje).
    try {
        if ($pdo->query("SHOW TABLES LIKE 'kitchen_stations'")->fetchColumn()) {
            $scols = $pdo->query("SHOW COLUMNS FROM kitchen_stations")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('printer_id', $scols, true)) {
                $pdo->exec("ALTER TABLE kitchen_stations ADD COLUMN printer_id INT NULL DEFAULT NULL");
            }
        }
    } catch (Throwable $e) {}

    $done = true;
}

// ─────────────────────────────────────────────────────────────
// SETTINGS helpers (key/value v tabulce `nastaveni`)
// ─────────────────────────────────────────────────────────────
function setting_get(PDO $pdo, string $key, ?string $default = null): ?string {
    try {
        $s = $pdo->prepare("SELECT hodnota FROM nastaveni WHERE klic = :k");
        $s->execute(['k' => $key]);
        $v = $s->fetchColumn();
        return ($v !== false) ? (string)$v : $default;
    } catch (Throwable $e) {
        return $default;
    }
}
function setting_set(PDO $pdo, string $key, string $value, string $popis = ''): void {
    $pdo->prepare("
        INSERT INTO nastaveni (klic, hodnota, popis) VALUES (:k, :v, :p)
        ON DUPLICATE KEY UPDATE hodnota = :v, popis = COALESCE(NULLIF(:p, ''), popis), upraveno = NOW()
    ")->execute(['k' => $key, 'v' => $value, 'p' => $popis]);
}

// ─────────────────────────────────────────────────────────────
// CRUD
// ─────────────────────────────────────────────────────────────
function printer_list(PDO $pdo): array {
    printer_ensure_schema($pdo);
    return $pdo->query("SELECT * FROM restaurant_printers ORDER BY typ, nazev")->fetchAll();
}
function printer_get(PDO $pdo, int $id): ?array {
    printer_ensure_schema($pdo);
    $s = $pdo->prepare("SELECT * FROM restaurant_printers WHERE id = :id");
    $s->execute(['id' => $id]);
    $r = $s->fetch();
    return $r ?: null;
}
function printer_save(PDO $pdo, array $data): int {
    printer_ensure_schema($pdo);
    $allowed_typ = ['kasa','kuchyne','bar','sklad','vydej','generic'];
    $typ = in_array($data['typ'] ?? '', $allowed_typ, true) ? $data['typ'] : 'generic';
    $nazev = trim((string)($data['nazev'] ?? ''));
    if ($nazev === '') throw new Exception('Název je povinný');
    $ip = trim((string)($data['ip'] ?? ''));
    if ($ip === '') throw new Exception('IP adresa je povinná');
    if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^[a-z0-9\-.]+$/i', $ip)) {
        throw new Exception('Neplatná IP adresa nebo hostname');
    }
    $port = max(1, min(65535, (int)($data['port'] ?? 9100)));
    $sirka = in_array((int)($data['sirka_papiru'] ?? 80), [58, 80], true) ? (int)$data['sirka_papiru'] : 80;
    $enc = $data['encoding'] ?? 'cp852';
    $aktivni = !empty($data['aktivni']) ? 1 : 0;
    $pozn = trim((string)($data['poznamka'] ?? ''));

    if (!empty($data['id'])) {
        $pdo->prepare("
            UPDATE restaurant_printers
            SET nazev=:n, typ=:t, ip=:ip, port=:p, sirka_papiru=:s, encoding=:e, aktivni=:a, poznamka=:pz
            WHERE id=:id
        ")->execute([
            'n' => $nazev, 't' => $typ, 'ip' => $ip, 'p' => $port, 's' => $sirka,
            'e' => $enc, 'a' => $aktivni, 'pz' => $pozn, 'id' => (int)$data['id']
        ]);
        return (int)$data['id'];
    } else {
        $pdo->prepare("
            INSERT INTO restaurant_printers (nazev, typ, ip, port, sirka_papiru, encoding, aktivni, poznamka)
            VALUES (:n, :t, :ip, :p, :s, :e, :a, :pz)
        ")->execute([
            'n' => $nazev, 't' => $typ, 'ip' => $ip, 'p' => $port, 's' => $sirka,
            'e' => $enc, 'a' => $aktivni, 'pz' => $pozn
        ]);
        return (int)$pdo->lastInsertId();
    }
}
function printer_delete(PDO $pdo, int $id): void {
    printer_ensure_schema($pdo);
    // Vyčisti FK v kategorie_vyrobku (set printer_id = NULL)
    try {
        $pdo->prepare("UPDATE kategorie_vyrobku SET printer_id = NULL WHERE printer_id = :id")
            ->execute(['id' => $id]);
    } catch (Throwable $e) {}
    // 🆕 v3.0.200 — vyčisti i FK v kuchyňských stanicích (smazaná tiskárna nesmí nechat viset stanici)
    try {
        $pdo->prepare("UPDATE kitchen_stations SET printer_id = NULL WHERE printer_id = :id")
            ->execute(['id' => $id]);
    } catch (Throwable $e) {}
    $pdo->prepare("DELETE FROM restaurant_printers WHERE id = :id")->execute(['id' => $id]);
}

// ─────────────────────────────────────────────────────────────
// ESC/POS COMMAND BUILDERS
// ─────────────────────────────────────────────────────────────
const PR_ESC = "\x1b";
const PR_GS  = "\x1d";

function pr_init(): string { return PR_ESC . '@'; }
function pr_align(string $a): string {
    $code = ['L' => 0, 'C' => 1, 'R' => 2][strtoupper($a)] ?? 0;
    return PR_ESC . 'a' . chr($code);
}
function pr_bold(bool $on): string { return PR_ESC . 'E' . chr($on ? 1 : 0); }
function pr_underline(int $n): string { return PR_ESC . '-' . chr(max(0, min(2, $n))); }
function pr_size(int $w = 1, int $h = 1): string {
    $w = max(1, min(8, $w)); $h = max(1, min(8, $h));
    return PR_GS . '!' . chr((($w - 1) << 4) | ($h - 1));
}
function pr_feed(int $n = 1): string { return PR_ESC . 'd' . chr(max(0, min(8, $n))); }
function pr_cut(): string { return "\n\n\n" . PR_GS . 'V' . chr(0); }
function pr_text(string $s, string $enc = 'cp852'): string {
    $out = @iconv('UTF-8', $enc . '//TRANSLIT//IGNORE', $s);
    return $out !== false ? $out : $s;
}

// ─────────────────────────────────────────────────────────────
// LAYOUT HELPERS
// ─────────────────────────────────────────────────────────────
function pr_line_width(array $printer): int {
    return ((int)$printer['sirka_papiru']) === 58 ? 32 : 42;
}
function pr_hr(int $w, string $ch = '-'): string {
    return str_repeat($ch, $w) . "\n";
}
function pr_two_col(string $left, string $right, int $w): string {
    $lw = mb_strlen($left, 'UTF-8');
    $rw = mb_strlen($right, 'UTF-8');
    $pad = max(1, $w - $lw - $rw);
    return $left . str_repeat(' ', $pad) . $right . "\n";
}

// ─────────────────────────────────────────────────────────────
// BUILDERS — ÚČTENKA (kasa)
// ─────────────────────────────────────────────────────────────
function printer_build_receipt(array $order, array $printer, array $firma = []): string {
    $w = pr_line_width($printer);
    $enc = $printer['encoding'] ?? 'cp852';

    $out  = pr_init();
    $out .= pr_align('C') . pr_bold(true) . pr_size(2, 2);
    $out .= pr_text(($firma['firma_nazev'] ?? 'APPEK POS') . "\n", $enc);
    $out .= pr_size() . pr_bold(false);
    if (!empty($firma['firma_ulice']) || !empty($firma['firma_mesto'])) {
        $addr = trim(($firma['firma_ulice'] ?? '') . ', ' . ($firma['firma_mesto'] ?? ''), ', ');
        $out .= pr_text($addr . "\n", $enc);
    }
    if (!empty($firma['firma_ico'])) $out .= pr_text('IČ: ' . $firma['firma_ico'], $enc);
    if (!empty($firma['firma_dic'])) $out .= pr_text(' · DIČ: ' . $firma['firma_dic'], $enc);
    if (!empty($firma['firma_ico']) || !empty($firma['firma_dic'])) $out .= "\n";

    $out .= pr_hr($w);
    $out .= pr_align('L');
    $out .= pr_text(pr_two_col('Účtenka:', (string)($order['cislo'] ?? '?'), $w), $enc);
    $out .= pr_text(pr_two_col('Datum:', substr((string)($order['datum_objednani'] ?? date('Y-m-d H:i')), 0, 16), $w), $enc);
    if (!empty($order['pos_uzivatel'])) {
        $out .= pr_text(pr_two_col('Prodavač:', (string)$order['pos_uzivatel'], $w), $enc);
    }
    if (!empty($order['pos_typ'])) {
        $out .= pr_text(pr_two_col('Typ:', (string)$order['pos_typ'], $w), $enc);
    }
    $out .= pr_hr($w);

    foreach (($order['polozky'] ?? []) as $p) {
        $nm  = (string)($p['vyrobek_nazev'] ?? '?');
        $mn  = (int)($p['mnozstvi'] ?? 1);
        $cena = (float)($p['cena_celkem'] ?? ($p['cena_bez_dph'] ?? 0) * $mn);
        // Řádek: "2× Espresso          50.00"
        $prefix = $mn . '× ';
        $price = sprintf('%.2f', $cena);
        $maxNm = $w - mb_strlen($prefix, 'UTF-8') - mb_strlen($price, 'UTF-8') - 1;
        $nmShort = mb_strimwidth($nm, 0, $maxNm, '..', 'UTF-8');
        $out .= pr_text(pr_two_col($prefix . $nmShort, $price, $w), $enc);
    }

    $out .= pr_hr($w, '=');
    $out .= pr_bold(true) . pr_size(1, 2);
    $celk = sprintf('CELKEM: %.2f Kč', (float)($order['castka_celkem'] ?? 0));
    $out .= pr_text(str_pad($celk, $w, ' ', STR_PAD_LEFT) . "\n", $enc);
    $out .= pr_size() . pr_bold(false);

    if (!empty($order['pos_payment'])) {
        $out .= pr_text(pr_two_col('Platba:', (string)$order['pos_payment'], $w), $enc);
    }
    if (!empty($order['pos_tip']) && (float)$order['pos_tip'] > 0) {
        $out .= pr_text(pr_two_col('Spropitné:', sprintf('%.2f Kč', $order['pos_tip']), $w), $enc);
    }

    $out .= pr_feed(1) . pr_align('C');
    $out .= pr_text("Děkujeme za nákup!\n", $enc);
    $out .= pr_text(date('Y-m-d H:i:s') . "\n", $enc);
    $out .= pr_cut();

    return $out;
}

// ─────────────────────────────────────────────────────────────
// BUILDERS — BON (kuchyně / bar)
// ─────────────────────────────────────────────────────────────
function printer_build_bon(array $items, array $context, array $printer): string {
    $w = pr_line_width($printer);
    $enc = $printer['encoding'] ?? 'cp852';

    $out  = pr_init();
    $out .= pr_align('C') . pr_bold(true) . pr_size(2, 2);
    $out .= pr_text("*** BON ***\n", $enc);
    $out .= pr_size(1, 1);
    $typ_lbl = ['kasa' => 'KASA', 'kuchyne' => 'KUCHYNĚ', 'bar' => 'BAR', 'sklad' => 'SKLAD', 'vydej' => 'VÝDEJ', 'generic' => 'TISK'][(string)$printer['typ']] ?? 'TISK';
    $out .= pr_text($typ_lbl . "\n", $enc);
    $out .= pr_bold(false) . pr_size();
    $out .= pr_hr($w, '=');

    $out .= pr_align('L');
    if (!empty($context['stul_nazev'])) {
        $out .= pr_bold(true) . pr_size(2, 1);
        $out .= pr_text($context['stul_nazev'] . "\n", $enc);
        $out .= pr_size() . pr_bold(false);
    } elseif (!empty($context['cislo'])) {
        $out .= pr_bold(true) . pr_text('Účet: ' . $context['cislo'] . "\n", $enc) . pr_bold(false);
    }
    $out .= pr_text(date('H:i:s') . "\n", $enc);
    if (!empty($context['pos_uzivatel'])) {
        $out .= pr_text('Prodavač: ' . $context['pos_uzivatel'] . "\n", $enc);
    }
    if (!empty($context['poznamka'])) {
        $out .= pr_text("POZN: " . $context['poznamka'] . "\n", $enc);
    }
    $out .= pr_hr($w);

    foreach ($items as $it) {
        $out .= pr_bold(true) . pr_size(1, 2);
        $out .= pr_text((int)$it['mnozstvi'] . '× ' . $it['vyrobek_nazev'] . "\n", $enc);
        $out .= pr_size() . pr_bold(false);
        if (!empty($it['poznamka'])) {
            $out .= pr_text('  → ' . $it['poznamka'] . "\n", $enc);
        }
    }

    $out .= pr_feed(1) . pr_align('C') . pr_text('— připravit —' . "\n", $enc);
    $out .= pr_cut();

    return $out;
}

// ─────────────────────────────────────────────────────────────
// SEND
// ─────────────────────────────────────────────────────────────
function printer_send(PDO $pdo, array $printer, string $payload): array {
    $dummy = ((string)setting_get($pdo, 'printer_dummy_mode', '1')) === '1';

    if ($dummy) {
        $dir = sys_get_temp_dir() . '/appek_printer_dummy';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$printer['nazev']);
        // .bin = raw ESC/POS bytes; .txt = human-readable preview (strip control codes)
        $base = $dir . '/' . date('Ymd_His') . '_p' . $printer['id'] . '_' . $safe;
        @file_put_contents($base . '.bin', $payload);
        // Strip ESC/POS bytes for preview
        $preview = preg_replace('/\x1b[@aE\-d]./', '', $payload);
        $preview = preg_replace('/\x1d![\x00-\xff]/', '', $preview);
        $preview = preg_replace('/\x1dV\x00/', "\n--- CUT ---\n", $preview);
        @file_put_contents($base . '.txt', $preview);
        printer_log_success($pdo, (int)$printer['id']);
        return ['ok' => true, 'dummy' => true, 'file' => $base . '.txt', 'bytes' => strlen($payload)];
    }

    $errno = 0; $errstr = '';
    $sock = @fsockopen((string)$printer['ip'], (int)$printer['port'], $errno, $errstr, 3);
    if (!$sock) {
        $err = "Connect {$printer['ip']}:{$printer['port']} selhalo: $errstr ($errno)";
        printer_log_error($pdo, (int)$printer['id'], $err);
        return ['ok' => false, 'error' => $err];
    }
    stream_set_timeout($sock, 5);
    $written = fwrite($sock, $payload);
    fclose($sock);
    if ($written === false || $written < strlen($payload)) {
        printer_log_error($pdo, (int)$printer['id'], 'fwrite partial: ' . (int)$written);
        return ['ok' => false, 'error' => 'fwrite selhalo'];
    }
    printer_log_success($pdo, (int)$printer['id']);
    return ['ok' => true, 'bytes' => $written];
}

function printer_log_success(PDO $pdo, int $id): void {
    try {
        $pdo->prepare("
            UPDATE restaurant_printers
            SET posledni_tisk = NOW(), posledni_chyba = NULL, pocet_tisku = pocet_tisku + 1
            WHERE id = :id
        ")->execute(['id' => $id]);
    } catch (Throwable $e) {}
}
function printer_log_error(PDO $pdo, int $id, string $err): void {
    try {
        $pdo->prepare("
            UPDATE restaurant_printers
            SET posledni_tisk = NOW(), posledni_chyba = :e
            WHERE id = :id
        ")->execute(['id' => $id, 'e' => mb_substr($err, 0, 1000)]);
    } catch (Throwable $e) {}
}

// ─────────────────────────────────────────────────────────────
// DISPATCH — rozeslat objednávku na tiskárny podle KUCHYŇSKÉ STANICE
//   🆕 v3.0.200 — položka → vyrobky.kitchen_station_id → kitchen_stations.printer_id.
//   Nahradilo mapování kategorie→tiskárna. Položky bez stanice/tiskárny se přeskočí.
// ─────────────────────────────────────────────────────────────
function printer_dispatch_order(PDO $pdo, int $objednavka_id, array $context = []): array {
    printer_ensure_schema($pdo);

    // Načti položky + stanice + tiskárna stanice
    $sql = "
        SELECT op.*,
               v.nazev AS vyrobek_nazev,
               v.kitchen_station_id,
               ks.printer_id,
               ks.nazev AS station_nazev
        FROM objednavky_polozky op
        LEFT JOIN vyrobky v          ON v.id = op.vyrobek_id
        LEFT JOIN kitchen_stations ks ON ks.id = v.kitchen_station_id
        WHERE op.objednavka_id = :id
    ";
    try {
        $s = $pdo->prepare($sql);
        $s->execute(['id' => $objednavka_id]);
        $items = $s->fetchAll();
    } catch (Throwable $e) {
        // kitchen_stations ještě neexistuje (restaurace balíček neinicializovaný) → nic k routování
        return [];
    }

    // Group by printer_id stanice (přeskoč položky bez stanice / bez tiskárny stanice)
    $byPrinter = [];
    foreach ($items as $it) {
        $pid = (int)($it['printer_id'] ?? 0);
        if (!$pid) continue;
        $byPrinter[$pid][] = $it;
    }

    $results = [];
    foreach ($byPrinter as $pid => $items_for_printer) {
        $printer = printer_get($pdo, $pid);
        if (!$printer || !$printer['aktivni']) {
            $results[] = ['printer_id' => $pid, 'ok' => false, 'error' => 'Tiskárna neaktivní/smazaná'];
            continue;
        }
        $payload = printer_build_bon($items_for_printer, $context, $printer);
        $res = printer_send($pdo, $printer, $payload);
        $results[] = array_merge(['printer_id' => $pid, 'nazev' => $printer['nazev'], 'typ' => $printer['typ']], $res);
    }

    return $results;
}

// ─────────────────────────────────────────────────────────────
// PRINT RECEIPT — účtenka na kasa
// ─────────────────────────────────────────────────────────────
function printer_print_receipt(PDO $pdo, int $objednavka_id): array {
    printer_ensure_schema($pdo);

    $kasa = $pdo->query("SELECT * FROM restaurant_printers WHERE typ='kasa' AND aktivni=1 ORDER BY id LIMIT 1")->fetch();
    if (!$kasa) return ['ok' => false, 'error' => 'Žádná aktivní kasa tiskárna'];

    $o = $pdo->prepare("SELECT * FROM objednavky WHERE id = :id");
    $o->execute(['id' => $objednavka_id]);
    $order = $o->fetch();
    if (!$order) return ['ok' => false, 'error' => 'Objednávka nenalezena'];

    $p = $pdo->prepare("
        SELECT op.*, v.nazev AS vyrobek_nazev
        FROM objednavky_polozky op
        LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
        WHERE op.objednavka_id = :id
    ");
    $p->execute(['id' => $objednavka_id]);
    $order['polozky'] = $p->fetchAll();

    $firma = [];
    foreach (['firma_nazev', 'firma_ulice', 'firma_mesto', 'firma_ico', 'firma_dic'] as $k) {
        $firma[$k] = setting_get($pdo, $k);
    }

    $payload = printer_build_receipt($order, $kasa, $firma);
    return printer_send($pdo, $kasa, $payload);
}

// ─────────────────────────────────────────────────────────────
// PRINT DOC — vytiskni libovolný doklad (obj/dl/fa) na ZVOLENOU tiskárnu
// 🆕 v3.0.133 — User: "ikonka tisku v detailu + volba tiskárny z nastavení".
//   mode = 'receipt' (účtenka s cenami) nebo 'bon' (jen položky+množství).
// ─────────────────────────────────────────────────────────────
function printer_print_doc(PDO $pdo, string $docType, int $docId, int $printerId, string $mode = 'receipt'): array {
    printer_ensure_schema($pdo);

    $printer = printer_get($pdo, $printerId);
    if (!$printer)               return ['ok' => false, 'error' => 'Tiskárna nenalezena'];
    if (empty($printer['aktivni'])) return ['ok' => false, 'error' => 'Tiskárna je neaktivní'];

    // Načti doklad + položky do normalizovaného tvaru ($order + $items)
    $order = null;
    $items = [];
    if ($docType === 'obj') {
        $s = $pdo->prepare("SELECT o.*, od.nazev AS odberatel_nazev FROM objednavky o LEFT JOIN odberatele od ON od.id = o.odberatel_id WHERE o.id = :id");
        $s->execute(['id' => $docId]);
        $order = $s->fetch();
        if (!$order) return ['ok' => false, 'error' => 'Objednávka nenalezena'];
        $p = $pdo->prepare("
            SELECT op.mnozstvi, op.cena_bez_dph, op.sazba_dph, op.poznamka,
                   COALESCE(NULLIF(op.vyrobek_nazev, ''), v.nazev) AS vyrobek_nazev
            FROM objednavky_polozky op
            LEFT JOIN vyrobky v ON v.id = op.vyrobek_id
            WHERE op.objednavka_id = :id ORDER BY op.id
        ");
        $p->execute(['id' => $docId]);
        $items = $p->fetchAll();
        $order['datum_objednani'] = $order['datum_objednani'] ?? date('Y-m-d H:i');
    } elseif ($docType === 'dl') {
        $s = $pdo->prepare("SELECT dl.*, od.nazev AS odberatel_nazev FROM dodaci_listy dl LEFT JOIN odberatele od ON od.id = dl.odberatel_id WHERE dl.id = :id");
        $s->execute(['id' => $docId]);
        $order = $s->fetch();
        if (!$order) return ['ok' => false, 'error' => 'Dodací list nenalezen'];
        $p = $pdo->prepare("
            SELECT dlp.mnozstvi, dlp.cena_bez_dph, dlp.sazba_dph, dlp.poznamka,
                   COALESCE(NULLIF(dlp.vyrobek_nazev, ''), v.nazev) AS vyrobek_nazev
            FROM dodaci_list_polozky dlp
            LEFT JOIN vyrobky v ON v.id = dlp.vyrobek_id
            WHERE dlp.dodaci_list_id = :id ORDER BY dlp.id
        ");
        $p->execute(['id' => $docId]);
        $items = $p->fetchAll();
        $order['datum_objednani'] = $order['datum_vystaveni'] ?? date('Y-m-d H:i');
    } elseif ($docType === 'fa') {
        $s = $pdo->prepare("SELECT f.*, od.nazev AS odberatel_nazev FROM faktury f LEFT JOIN odberatele od ON od.id = f.odberatel_id WHERE f.id = :id");
        $s->execute(['id' => $docId]);
        $order = $s->fetch();
        if (!$order) return ['ok' => false, 'error' => 'Faktura nenalezena'];
        $p = $pdo->prepare("
            SELECT fp.mnozstvi, fp.cena_bez_dph, fp.sazba_dph, fp.poznamka,
                   COALESCE(NULLIF(fp.vyrobek_nazev, ''), v.nazev) AS vyrobek_nazev
            FROM faktura_polozky fp
            LEFT JOIN vyrobky v ON v.id = fp.vyrobek_id
            WHERE fp.faktura_id = :id ORDER BY fp.poradi, fp.id
        ");
        $p->execute(['id' => $docId]);
        $items = $p->fetchAll();
        $order['datum_objednani'] = $order['datum_vystaveni'] ?? date('Y-m-d H:i');
    } else {
        return ['ok' => false, 'error' => 'Neznámý typ dokladu: ' . $docType];
    }

    if (!$items) return ['ok' => false, 'error' => 'Doklad nemá žádné položky k tisku'];

    // Dopočti cena_celkem s DPH per položka (přesnější součet na účtence)
    foreach ($items as &$it) {
        $mn  = (float)($it['mnozstvi'] ?? 0);
        $bez = (float)($it['cena_bez_dph'] ?? 0);
        $dph = (float)($it['sazba_dph'] ?? 0);
        $it['cena_celkem'] = round($mn * $bez * (1 + $dph / 100), 2);
    }
    unset($it);
    $order['polozky'] = $items;

    if ($mode === 'bon') {
        $context = [
            'cislo'     => (string)($order['cislo'] ?? ''),
            'odberatel' => (string)($order['odberatel_nazev'] ?? ''),
        ];
        $payload = printer_build_bon($items, $context, $printer);
    } else { // receipt
        $firma = [];
        foreach (['firma_nazev', 'firma_ulice', 'firma_mesto', 'firma_ico', 'firma_dic'] as $k) {
            $firma[$k] = setting_get($pdo, $k);
        }
        $payload = printer_build_receipt($order, $printer, $firma);
    }

    return printer_send($pdo, $printer, $payload);
}

// ─────────────────────────────────────────────────────────────
// TEST PRINT
// ─────────────────────────────────────────────────────────────
function printer_test_print(PDO $pdo, int $printer_id): array {
    $printer = printer_get($pdo, $printer_id);
    if (!$printer) return ['ok' => false, 'error' => 'Tiskárna nenalezena'];
    $w = pr_line_width($printer);
    $enc = $printer['encoding'] ?? 'cp852';

    $out  = pr_init();
    $out .= pr_align('C') . pr_bold(true) . pr_size(2, 2);
    $out .= pr_text("APPEK\n", $enc);
    $out .= pr_size(1, 1);
    $out .= pr_text("Test tisku\n", $enc);
    $out .= pr_bold(false) . pr_size() . pr_align('L');
    $out .= pr_hr($w);
    $out .= pr_text(pr_two_col('Tiskárna:', (string)$printer['nazev'], $w), $enc);
    $out .= pr_text(pr_two_col('Typ:',      (string)$printer['typ'], $w), $enc);
    $out .= pr_text(pr_two_col('IP:port:',  $printer['ip'] . ':' . $printer['port'], $w), $enc);
    $out .= pr_text(pr_two_col('Šířka:',    $printer['sirka_papiru'] . ' mm', $w), $enc);
    $out .= pr_text(pr_two_col('Datum:',    date('Y-m-d H:i:s'), $w), $enc);
    $out .= pr_hr($w);
    $out .= pr_align('C');
    $out .= pr_text("Pokud čteš tohle,\nvšechno funguje! 🎉\n", $enc);
    $out .= pr_text("Diakritika: ěščřžýáíé\n", $enc);
    $out .= pr_cut();

    return printer_send($pdo, $printer, $out);
}
