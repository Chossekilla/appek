<?php
/**
 * 💳 v3.0.643 — ONLINE PLATBY v měně dokladu (balík B „platba v Kč i EUR současně").
 *
 * Jediné místo, kde se:
 *   - zapisuje OČEKÁVANÁ platba při založení platby u brány (pay_mena + pay_castka; u účtu stolu i pay_suma_kc),
 *   - potvrzuje platba z webhooku/callbacku brány — VŽDY s kontrolou částky A měny proti očekávané:
 *       sedí  → pay_status='paid', pay_prijato, zápis do ledgeru platby_doklad (idempotentně přes idem)
 *       nesedí → pay_status='failed' + upozornění v adminu (nikdy 'paid')
 *
 * Reference u brány (client_reference_id / order_number):
 *   PAY-<obj_id>-<pay_token>   QR platba objednávky (pay/)
 *   UCET-<ucet_id>-<pay_token> QR platba účtu stolu (pay/)
 *   B2B-<obj_id>-<pay_token>   B2B checkout (objednavky.php)
 * Token = objednavky.pay_token / restaurant_pos_ucty.pay_token (32 hex) — webhook bez tokenu nic nepotvrdí.
 *
 * Účet stolu: ledger NEZAPISUJE tento soubor — po 'paid' účet dokončí admin_pos.php
 * (pos_finalize_ucet_payment, balík A) z pay_mena/pay_castka/pay_suma_kc.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_mena_lib.php';
require_once __DIR__ . '/_customer_integrace.php';

const ONLINE_REF_RE = '/^(PAY|UCET|B2B)-(\d+)-([a-f0-9]{32})$/';

/** Sloupce online platby na objednávce (stejné jako pay_qr_ensure_schema; volat MIMO transakci). */
function online_obj_pay_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM objednavky")->fetchAll(PDO::FETCH_COLUMN);
        $add = [];
        if (!in_array('pay_token', $cols, true))  $add[] = "ADD COLUMN pay_token VARCHAR(48) NULL UNIQUE";
        if (!in_array('pay_status', $cols, true)) $add[] = "ADD COLUMN pay_status ENUM('none','pending','pending_manual','paid','failed','expired','refunded') DEFAULT 'none'";
        if (!in_array('pay_method', $cols, true)) $add[] = "ADD COLUMN pay_method VARCHAR(40) NULL";
        if (!in_array('paid_at', $cols, true))    $add[] = "ADD COLUMN paid_at DATETIME NULL";
        if (!in_array('pay_extra', $cols, true))  $add[] = "ADD COLUMN pay_extra TEXT NULL";
        if ($add) $pdo->exec("ALTER TABLE objednavky " . implode(', ', $add));
    } catch (Throwable $e) { error_log('online_obj_pay_schema: ' . $e->getMessage()); }
    mena_ensure_schema($pdo);   // pay_mena / pay_castka / pay_prijato
    $done = true;
}

/** pay_token objednávky (vytvoří, když chybí). */
function online_obj_token(PDO $pdo, int $id): string {
    online_obj_pay_schema($pdo);
    $st = $pdo->prepare("SELECT pay_token FROM objednavky WHERE id = ?");
    $st->execute([$id]);
    $t = (string) $st->fetchColumn();
    if (preg_match('/^[a-f0-9]{32}$/', $t)) return $t;
    $t = bin2hex(random_bytes(16));
    $pdo->prepare("UPDATE objednavky SET pay_token = ? WHERE id = ? AND (pay_token IS NULL OR pay_token = '')")->execute([$t, $id]);
    $st->execute([$id]);
    return (string) $st->fetchColumn();
}

/**
 * Zapíše očekávanou platbu (volat těsně PŘED voláním brány). $dc = mena_doc_castka(...).
 *   objednavky: pay_mena, pay_castka, pay_status='pending' (ne u zaplacené), pay_method, pay_extra
 *   restaurant_pos_ucty: pay_mena, pay_castka, pay_suma_kc (= Kč stav účtu v okamžiku init), pay_status 'pending'
 */
function online_platba_ocekavana(PDO $pdo, string $typ, int $id, array $dc, ?string $brana = null, ?array $extra = null): void {
    $mena = strtoupper((string) $dc['mena']);
    $castka = round((float) $dc['celkem'], 2);
    if ($typ === 'objednavky') {
        $pdo->prepare("UPDATE objednavky SET pay_mena = ?, pay_castka = ?, pay_status = IF(pay_status = 'paid', 'paid', 'pending'),
                              pay_method = COALESCE(?, pay_method), pay_extra = COALESCE(?, pay_extra) WHERE id = ?")
            ->execute([$mena, $castka, $brana, $extra !== null ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null, $id]);
        return;
    }
    if ($typ === 'restaurant_pos_ucty') {
        $pdo->prepare("UPDATE restaurant_pos_ucty SET pay_mena = ?, pay_castka = ?, pay_suma_kc = ?,
                              pay_status = IF(pay_status = 'paid', 'paid', 'pending') WHERE id = ?")
            ->execute([$mena, $castka, round((float) $dc['celkem_czk'], 2), $id]);
        return;
    }
    throw new InvalidArgumentException("online_platba_ocekavana: neznámý typ $typ");
}

/** Upozornění v adminu (tabulka notifications; best-effort). */
function online_platba_upozorni(PDO $pdo, string $titulek, string $zprava, string $link = '#/objednavky'): void {
    try {
        $pdo->prepare("INSERT INTO notifications (kind, title, msg, link, severity) VALUES ('platba_nesedi', ?, ?, ?, 'error')")
            ->execute([mb_substr($titulek, 0, 200), $zprava, $link]);
    } catch (Throwable $e) { error_log('online_platba_upozorni: ' . $e->getMessage() . ' | ' . $titulek . ' | ' . $zprava); }
}

/**
 * Potvrzení platby od brány. $a = [typ, id, minor (int, centy), mena, brana, ext_id, zdroj ('online'|'qr')].
 * → ['stav' => 'paid'|'failed'|'uz_zaplaceno'|'nenalezeno', 'duvod' => ?string, 'ocekavano' => …]
 */
function online_platba_potvrd(PDO $pdo, array $a): array {
    $typ = (string) $a['typ']; $id = (int) $a['id'];
    $minor = (int) $a['minor']; $mena = strtoupper((string) $a['mena']);
    $brana = (string) ($a['brana'] ?? 'online'); $ext = (string) ($a['ext_id'] ?? '');
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        if ($typ === 'objednavky') {
            $st = $pdo->prepare("SELECT id, cislo, stav, pay_status, pay_mena, pay_castka, mena, mena_kurz FROM objednavky WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $o = $st->fetch(PDO::FETCH_ASSOC);
            if (!$o) { if ($own) $pdo->commit(); return ['stav' => 'nenalezeno']; }
            if (($o['pay_status'] ?? '') === 'paid') { if ($own) $pdo->commit(); return ['stav' => 'uz_zaplaceno']; }
            if (!empty($o['pay_mena']) && $o['pay_castka'] !== null) {
                $expMena = strtoupper((string) $o['pay_mena']); $expMinor = (int) round((float) $o['pay_castka'] * 100);
            } else {   // platba založená před v3.0.643 (bez pay_*) → částka dokladu
                $dc = mena_doc_castka($pdo, 'objednavky', $id);
                $expMena = $dc['mena']; $expMinor = $dc['celkem_minor'];
            }
            $info = ['ocekavano' => ['mena' => $expMena, 'minor' => $expMinor], 'prijato' => ['mena' => $mena, 'minor' => $minor], 'brana' => $brana, 'ext_id' => $ext];
            if ($mena !== $expMena || $minor !== $expMinor) {
                $duvod = $mena !== $expMena ? 'mena_nesedi' : 'castka_nesedi';
                $pdo->prepare("UPDATE objednavky SET pay_status = 'failed', pay_extra = ? WHERE id = ?")
                    ->execute([json_encode(['chyba' => $duvod] + $info, JSON_UNESCAPED_UNICODE), $id]);
                online_platba_upozorni($pdo, '⚠️ Platba nesedí — objednávka ' . $o['cislo'],
                    sprintf('%s: očekáváno %s, brána potvrdila %s. Objednávka NENÍ označena jako zaplacená — zkontroluj platbu u brány (%s).',
                        strtoupper($brana), mena_fmt($expMinor / 100, $expMena), mena_fmt($minor / 100, $mena), $ext));
                if ($own) $pdo->commit();
                return ['stav' => 'failed', 'duvod' => $duvod] + $info;
            }
            $castka = round($minor / 100, 2);
            $pdo->prepare("UPDATE objednavky SET pay_status = 'paid', paid_at = NOW(), pay_method = ?, pay_prijato = ?, pay_extra = ?,
                                  stav = IF(stav IN ('rozpracovano','novy','zaplaceno_predem'), 'zaplaceno', stav) WHERE id = ?")
                ->execute([$brana, $castka, json_encode($info, JSON_UNESCAPED_UNICODE), $id]);
            $kurz = $mena === 'CZK' ? 1.0 : (float) ($o['mena_kurz'] ?: 0);
            if ($kurz <= 0) $kurz = (float) (mena_doc_castka($pdo, 'objednavky', $id)['kurz'] ?? 1);
            mena_platba_zapis($pdo, ['objednavka_id' => $id, 'zdroj' => (($a['zdroj'] ?? 'online') === 'qr' ? 'qr' : 'online'), 'metoda' => 'online',
                                     'mena' => $mena, 'castka_mena' => $castka, 'kurz' => $kurz, 'uzivatel' => $brana,
                                     'idem' => substr($brana . ':' . ($ext !== '' ? $ext : ('obj' . $id)), 0, 64)]);
            if ($own) $pdo->commit();
            return ['stav' => 'paid'] + $info;
        }
        if ($typ === 'restaurant_pos_ucty') {
            $st = $pdo->prepare("SELECT id, stav, pay_status, suma_kc, pay_mena, pay_castka, pay_suma_kc, cislo_dokladu FROM restaurant_pos_ucty WHERE id = ? FOR UPDATE");
            $st->execute([$id]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if (!$u) { if ($own) $pdo->commit(); return ['stav' => 'nenalezeno']; }
            if (($u['stav'] ?? '') === 'paid' || ($u['pay_status'] ?? '') === 'paid') { if ($own) $pdo->commit(); return ['stav' => 'uz_zaplaceno']; }
            $expMena = !empty($u['pay_mena']) ? strtoupper((string) $u['pay_mena']) : 'CZK';
            $expMinor = $u['pay_castka'] !== null ? (int) round((float) $u['pay_castka'] * 100) : (int) round((float) $u['suma_kc'] * 100);
            $info = ['ocekavano' => ['mena' => $expMena, 'minor' => $expMinor], 'prijato' => ['mena' => $mena, 'minor' => $minor], 'brana' => $brana, 'ext_id' => $ext];
            $duvod = null;
            if ($mena !== $expMena) $duvod = 'mena_nesedi';
            elseif ($minor !== $expMinor) $duvod = 'castka_nesedi';
            elseif ($u['pay_suma_kc'] !== null && abs((float) $u['suma_kc'] - (float) $u['pay_suma_kc']) > 0.005) $duvod = 'ucet_zmenen';   // číšník po init přidal/ubral položku
            if ($duvod) {
                $pdo->prepare("UPDATE restaurant_pos_ucty SET pay_status = 'failed' WHERE id = ?")->execute([$id]);
                online_platba_upozorni($pdo, '⚠️ QR platba účtu nesedí — účet #' . $id,
                    ($duvod === 'ucet_zmenen'
                        ? 'Účet se po zahájení platby změnil (' . mena_fmt((float) $u['pay_suma_kc'], 'CZK') . ' → ' . mena_fmt((float) $u['suma_kc'], 'CZK') . '). '
                        : sprintf('Očekáváno %s, brána potvrdila %s. ', mena_fmt($expMinor / 100, $expMena), mena_fmt($minor / 100, $mena)))
                    . 'Účet NENÍ uzavřen — vyřeš doplatek/vrácení u stolu (' . strtoupper($brana) . ' ' . $ext . ').', '#/pos');
                if ($own) $pdo->commit();
                return ['stav' => 'failed', 'duvod' => $duvod] + $info;
            }
            $pdo->prepare("UPDATE restaurant_pos_ucty SET pay_status = 'paid' WHERE id = ? AND pay_status <> 'paid'")->execute([$id]);
            if ($own) $pdo->commit();
            return ['stav' => 'paid'] + $info;
        }
        throw new InvalidArgumentException("online_platba_potvrd: neznámý typ $typ");
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Reference brány → doklad (ověří token). → ['typ','id','zdroj','prefix'] | null */
function online_platba_reference(PDO $pdo, string $ref): ?array {
    if (!preg_match(ONLINE_REF_RE, $ref, $m)) return null;
    [$_, $prefix, $id, $token] = $m;
    $id = (int) $id;
    try {
        if ($prefix === 'UCET') {
            $st = $pdo->prepare("SELECT pay_token FROM restaurant_pos_ucty WHERE id = ?");
            $st->execute([$id]);
            $t = (string) $st->fetchColumn();
            return ($t !== '' && hash_equals($t, $token)) ? ['typ' => 'restaurant_pos_ucty', 'id' => $id, 'zdroj' => 'qr', 'prefix' => $prefix] : null;
        }
        $st = $pdo->prepare("SELECT pay_token FROM objednavky WHERE id = ?");
        $st->execute([$id]);
        $t = (string) $st->fetchColumn();
        if ($t === '' || !hash_equals($t, $token)) return null;
        return ['typ' => 'objednavky', 'id' => $id, 'zdroj' => $prefix === 'B2B' ? 'online' : 'qr', 'prefix' => $prefix];
    } catch (Throwable $e) { return null; }
}

/** Stripe event (už ověřený podpisem) → potvrzení. */
function online_platba_stripe_event(PDO $pdo, array $event): array {
    if (($event['type'] ?? '') !== 'checkout.session.completed') return ['stav' => 'ignorovano'];
    $o = $event['data']['object'] ?? [];
    if (isset($o['payment_status']) && $o['payment_status'] !== 'paid') return ['stav' => 'ignorovano', 'duvod' => 'payment_status=' . $o['payment_status']];
    $r = online_platba_reference($pdo, (string) ($o['client_reference_id'] ?? ''));
    if (!$r) return ['stav' => 'nenalezeno'];
    if (!isset($o['amount_total'], $o['currency'])) return ['stav' => 'ignorovano', 'duvod' => 'chybi_castka'];
    return online_platba_potvrd($pdo, ['typ' => $r['typ'], 'id' => $r['id'], 'minor' => (int) $o['amount_total'], 'mena' => (string) $o['currency'],
                                       'brana' => 'stripe', 'ext_id' => (string) ($o['id'] ?? ''), 'zdroj' => $r['zdroj']]);
}

/** GoPay notifikace (id platby) → stav ověřený dotazem na GoPay → potvrzení. */
function online_platba_gopay(PDO $pdo, int $gpId): array {
    $pay = customer_int_gopay_get_payment($gpId);
    if (!($pay['ok'] ?? false)) return ['stav' => 'chyba', 'duvod' => $pay['error'] ?? 'gopay'];
    if (($pay['state'] ?? '') !== 'PAID') return ['stav' => 'ignorovano', 'duvod' => 'state=' . ($pay['state'] ?? '?')];
    $r = online_platba_reference($pdo, (string) ($pay['order_number'] ?? ''));
    if (!$r) return ['stav' => 'nenalezeno'];
    return online_platba_potvrd($pdo, ['typ' => $r['typ'], 'id' => $r['id'], 'minor' => (int) ($pay['amount'] ?? -1), 'mena' => (string) ($pay['currency'] ?? ''),
                                       'brana' => 'gopay', 'ext_id' => (string) $gpId, 'zdroj' => $r['zdroj']]);
}

/**
 * Založí platbu u brány pro doklad. $dc = mena_doc_castka. → ['ok', 'url', 'id', 'error'].
 *   $p = [brana, reference, description, return_url, cancel_url, notification_url, customer_email, order_no]
 */
function online_platba_zaloz(PDO $pdo, string $typ, int $id, array $dc, array $p): array {
    $brana = (string) $p['brana'];
    $payload = ['amount' => (float) $dc['celkem'], 'currency' => (string) $dc['mena'], 'reference' => $p['reference'] ?? '',
                'description' => $p['description'] ?? 'Platba', 'return_url' => $p['return_url'] ?? '', 'cancel_url' => $p['cancel_url'] ?? ($p['return_url'] ?? ''),
                'notification_url' => $p['notification_url'] ?? '', 'customer_email' => $p['customer_email'] ?? null, 'order_no' => $p['order_no'] ?? ''];
    // Brána umí měnu? (před zápisem očekávané platby — ať se objednávka zbytečně nepřepne do 'pending')
    $chk = customer_int_platba_castka($payload, $brana === 'paypal' ? 'paypal' : ($brana === 'gopay' ? 'gopay' : 'stripe'));
    if (!$chk['ok']) return ['ok' => false, 'error' => $chk['error']];
    online_platba_ocekavana($pdo, $typ, $id, $dc, $typ === 'objednavky' ? $brana : null);
    if ($brana === 'stripe') {
        $r = customer_int_stripe_create_checkout($payload);
        $url = $r['url'] ?? ($r['session']['url'] ?? null);
        $ext = $r['id'] ?? null;
    } elseif ($brana === 'gopay') {
        $r = customer_int_gopay_create_payment($payload);
        $url = $r['gw_url'] ?? null;
        $ext = $r['id'] ?? null;
    } elseif ($brana === 'paypal') {
        $r = customer_int_paypal_create_order($payload);
        $url = $r['approve_url'] ?? null;
        $ext = $r['order_id'] ?? null;
    } else {
        return ['ok' => false, 'error' => 'neznama_brana'];
    }
    if (!($r['ok'] ?? false) || !$url) return ['ok' => false, 'error' => $r['error'] ?? 'brana_bez_url'];
    if ($typ === 'objednavky') {
        $pdo->prepare("UPDATE objednavky SET pay_extra = ? WHERE id = ?")
            ->execute([json_encode([$brana . '_id' => $ext, 'mena' => $dc['mena'], 'castka' => $dc['celkem']], JSON_UNESCAPED_UNICODE), $id]);
    }
    return ['ok' => true, 'url' => $url, 'id' => $ext];
}
