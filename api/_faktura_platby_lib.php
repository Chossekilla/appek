<?php
/**
 * 🆕 v3.0.645 — ÚHRADY FAKTUR V KNIZE PLATEB (platby_doklad, zdroj='faktura').
 *
 * Úhrada faktury se dál drží kumulativně ve faktury.castka_uhrazeno (uložená měna, Kč) — to je
 * zdroj pravdy pro saldo/stav „uhrazena". Kniha plateb k tomu eviduje KAŽDOU ZMĚNU úhrady jako
 * pohyb peněz v měně dokladu:
 *
 *   castka_mena = doc_uhrazeno(po) − doc_uhrazeno(před)    (v měně faktury, jako na PDF)
 *     > 0  = přijatá (doplatek / částečná) úhrada
 *     < 0  = protizápis: zrušení / snížení úhrady, vrácení stavu na nezaplaceno, smazání uhrazené faktury
 *   kurz   = kurz ČNB dokladu (faktury.mena_kurz; Kč = 1), castka_czk = castka_mena × kurz
 *            (stejně jako ostatní řádky knihy — online/QR/pokladna)
 *   faktura_id = vazba na fakturu (objednavka_id/ucet_id zůstávají NULL — faktura nepatří pokladně;
 *            pos_refund_blokace a tisk účtenky čtou ledger podle objednavka_id → úhrada faktury je neovlivní)
 *
 * Před i po se počítá STEJNOU funkcí (mena_doc_pole) → součet řádků knihy pro fakturu se teleskopicky
 * rovná doc_uhrazeno (od chvíle, kdy se úhrady evidují; úhrady z doby před v3.0.645 se zpětně
 * NEdoplňují — kniha je deník pohybů, ne rekonstrukce historie).
 *
 * Idempotence: volající drží fakturu zamčenou (SELECT … FOR UPDATE v transakci) → souběžné requesty
 *   se seřadí a opakovaný request se stejnou cílovou úhradou má rozdíl 0 → nic se nezapíše.
 *   idem = „fa:{id}:{pořadí}:{před}>{po}[:{metoda}]" (pořadí = počet dosavadních řádků faktury + 1;
 *   sufix metody jen když se jedna změna rozpadne do více řádků) — jistota proti dvojímu zápisu.
 *
 * Protizápis (snížení/zrušení úhrady) bez zvolené metody se rozepíše po metodách, kudy peníze přišly
 *   (LIFO, každá nejvýš do svého čistého zůstatku) → přehled po metodách po zrušení úhrady sedí na 0.
 *
 * Dobropis SÁM pohyb peněz není (snižuje pohledávku, ne přijaté peníze) → do knihy se nezapisuje.
 *   Vrácení peněz po dobropisu = snížení úhrady (castka_uhrazeno) → protizápis.
 *
 * Pokladní uzávěrka (pos_uzaverka_data) řádky zdroj='faktura' NEPOČÍTÁ (ani tržby, ani šuplík).
 *
 *   faktura_platby_ensure_schema(PDO)  — sloupec platby_doklad.faktura_id + index (MIMO transakci)
 *   faktura_uhrada_stav(PDO, id)       — {mena, uhrazeno (doc), kurz, castka_uhrazeno (Kč)} | null
 *   faktura_uhrada_metoda(?string)     — normalizace metody (prevod|hotovost|karta|online), jinak null
 *   faktura_uhrada_zapis(PDO, id, před, po, metoda?, uzivatel?) — zápis rozdílu do knihy (v transakci volajícího)
 *   faktura_uhrady_seznam(PDO, id)     — řádky knihy k faktuře (detail faktury)
 */

if (!function_exists('faktura_platby_ensure_schema')) {

    /** Metody, které lze u úhrady faktury zvolit (podmnožina metod knihy). */
    if (!defined('FAKTURA_UHRADA_METODY')) define('FAKTURA_UHRADA_METODY', ['prevod', 'hotovost', 'karta', 'online']);

    /**
     * Idempotentní migrace: platby_doklad.faktura_id + index. MIMO transakci (DDL = implicitní commit).
     * Tabulku platby_doklad zakládá mena_ensure_schema (v3.0.643) — když ještě není, nic nedělá.
     */
    function faktura_platby_ensure_schema(PDO $pdo): bool {
        static $ok = null;
        if ($ok !== null) return $ok;
        if ($pdo->inTransaction()) { error_log('faktura_platby_ensure_schema: volání v transakci — přeskočeno'); return false; }
        try { $pdo->query("SELECT faktura_id FROM platby_doklad LIMIT 0"); return $ok = true; }
        catch (Throwable $e) { /* sloupec (nebo tabulka) chybí */ }
        try {
            $pdo->query("SELECT id FROM platby_doklad LIMIT 0");
        } catch (Throwable $e) { return $ok = false; /* ledger ještě neexistuje (instalace před 643 migrací) */ }
        try {
            $pdo->exec("ALTER TABLE platby_doklad ADD COLUMN faktura_id INT NULL AFTER ucet_id, ADD KEY idx_pd_faktura (faktura_id)");
        } catch (Throwable $e) {
            error_log('faktura_platby_ensure_schema: ' . $e->getMessage());
        }
        try { $pdo->query("SELECT faktura_id FROM platby_doklad LIMIT 0"); return $ok = true; }
        catch (Throwable $e) { return $ok = false; }
    }

    /** Normalizace metody úhrady z UI/API (i synonyma). Neznámá / prázdná → null (= použij výchozí). */
    function faktura_uhrada_metoda($m): ?string {
        $m = strtolower(trim((string) ($m ?? '')));
        if ($m === '') return null;
        $map = ['prevod' => 'prevod', 'bankovni_prevod' => 'prevod', 'banka' => 'prevod', 'transfer' => 'prevod',
                'hotovost' => 'hotovost', 'hotove' => 'hotovost', 'cash' => 'hotovost',
                'karta' => 'karta', 'card' => 'karta', 'terminal' => 'karta',
                'online' => 'online', 'stripe' => 'online', 'gopay' => 'online', 'paypal' => 'online'];
        return $map[$m] ?? null;
    }

    /**
     * Stav úhrady faktury v MĚNĚ DOKLADU (stejný výpočet jako seznam/detail/PDF — mena_doc_pole).
     * @return array{mena:string, uhrazeno:float, kurz:float, castka_uhrazeno:float}|null
     */
    function faktura_uhrada_stav(PDO $pdo, int $faId): ?array {
        require_once __DIR__ . '/_mena_lib.php';
        $st = $pdo->prepare("SELECT castka_uhrazeno FROM faktury WHERE id = ?");
        $st->execute([$faId]);
        $uh = $st->fetchColumn();
        if ($uh === false) return null;
        $rows = [['id' => $faId]];
        mena_doc_pole($pdo, 'faktury', $rows);
        $mena = strtoupper((string) ($rows[0]['doc_mena'] ?? 'CZK'));
        if (!isset(MENA_PODPOROVANE[$mena])) $mena = 'CZK';
        $kurz = $mena === 'CZK' ? 1.0 : (float) ($rows[0]['doc_kurz'] ?? 0);
        return [
            'mena' => $mena,
            'uhrazeno' => round((float) ($rows[0]['doc_uhrazeno'] ?? $uh), 2),
            'kurz' => $kurz > 0 ? $kurz : 1.0,
            'castka_uhrazeno' => round((float) $uh, 2),   // uložená měna (Kč) — jen pro informaci/log
        ];
    }

    /**
     * Zapíše změnu úhrady faktury do knihy plateb (v transakci volajícího, faktura zamčená FOR UPDATE).
     * $pred / $po = výstupy faktura_uhrada_stav před a po změně. Rozdíl < 0,005 → nic (idempotence).
     * $metoda: null = u příjmu převod, u protizápisu rozpis po metodách, kudy peníze přišly (LIFO);
     *         zadaná = celý rozdíl touto metodou.
     * Vrací id (prvního) řádku knihy, nebo 0 (nic se nezapsalo / ledger na instalaci chybí).
     */
    function faktura_uhrada_zapis(PDO $pdo, int $faId, ?array $pred, ?array $po, ?string $metoda = null, ?string $uzivatel = null): int {
        if (!$pred || !$po) return 0;
        require_once __DIR__ . '/_mena_lib.php';
        if (!function_exists('mena_platba_zapis')) return 0;
        // Měna vystavené faktury se nemění (snapshot; mena_prepis_povolen) — kdyby se přece lišila,
        //   rozdíl dvou měn nedává smysl → nezapisuj nic a zaloguj (úhrada se uloží, kniha ne).
        $mena = $po['mena'];
        if ($pred['mena'] !== $mena) { error_log("faktura_uhrada_zapis: faktura $faId změnila měnu {$pred['mena']}→$mena — kniha přeskočena"); return 0; }
        $delta = round($po['uhrazeno'] - $pred['uhrazeno'], 2);
        if (abs($delta) < 0.005) return 0;
        try {
            $q = $pdo->prepare("SELECT COUNT(*) FROM platby_doklad WHERE faktura_id = ?");
            $q->execute([$faId]);
            $poradi = (int) $q->fetchColumn() + 1;
        } catch (Throwable $e) {
            return 0;   // ledger / sloupec faktura_id není (migrace neproběhla) → úhrada se uloží, kniha ne
        }
        $metoda = faktura_uhrada_metoda($metoda);
        // Rozpis rozdílu po metodách: [metoda => částka]
        $rozpis = [];
        if ($metoda !== null || $delta > 0) {
            $rozpis[$metoda ?? 'prevod'] = $delta;
        } else {
            // Protizápis bez zvolené metody = vrať peníze tou cestou, kudy přišly: od naposledy přijaté
            //   metody (LIFO), každou nejvýš do jejího čistého zůstatku. Zbytek (úhrady z doby před knihou
            //   plateb) → metoda poslední přijaté úhrady, jinak převod.
            $zbyva = -$delta;
            $posl = null;
            $q = $pdo->prepare("SELECT metoda, SUM(castka_mena) AS cisto, MAX(CASE WHEN castka_mena > 0 THEN id ELSE 0 END) AS posl
                                FROM platby_doklad WHERE faktura_id = ? AND zdroj = 'faktura' AND mena = ?
                                GROUP BY metoda ORDER BY posl DESC");
            $q->execute([$faId, $mena]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $m = faktura_uhrada_metoda($r['metoda']);
                if ($m === null) continue;
                if ($posl === null && (int) $r['posl'] > 0) $posl = $m;
                $cisto = round((float) $r['cisto'], 2);
                if ($cisto <= 0.004 || $zbyva <= 0.004) continue;
                $x = round(min($cisto, $zbyva), 2);
                $rozpis[$m] = round(($rozpis[$m] ?? 0) - $x, 2);
                $zbyva = round($zbyva - $x, 2);
            }
            if ($zbyva > 0.004) {
                $m = $posl ?? 'prevod';
                $rozpis[$m] = round(($rozpis[$m] ?? 0) - $zbyva, 2);
            }
        }
        $cents = fn(float $x) => (string) (int) round($x * 100);
        $klic = 'fa:' . $faId . ':' . $poradi . ':' . $cents($pred['uhrazeno']) . '>' . $cents($po['uhrazeno']);
        $uz = $uzivatel !== null && $uzivatel !== '' ? $uzivatel : null;
        $prvni = 0;
        foreach ($rozpis as $m => $castka) {
            if (abs($castka) < 0.005) continue;
            // více řádků jedné změny (rozpis protizápisu po metodách) se liší sufixem metody
            $idem = substr($klic . (count($rozpis) > 1 ? ':' . $m : ''), 0, 64);
            $id = mena_platba_zapis($pdo, [
                'zdroj' => 'faktura', 'metoda' => $m, 'mena' => $mena,
                'castka_mena' => $castka, 'kurz' => $po['kurz'],
                'uzivatel' => $uz, 'idem' => $idem,
            ]);
            if ($id > 0) {
                $pdo->prepare("UPDATE platby_doklad SET faktura_id = ? WHERE id = ? AND faktura_id IS NULL")->execute([$faId, $id]);
                if (!$prvni) $prvni = $id;
            }
        }
        return $prvni;
    }

    /** Řádky knihy plateb k faktuře (nejstarší první) — pro detail faktury. Ledger bez faktura_id → []. */
    function faktura_uhrady_seznam(PDO $pdo, int $faId): array {
        try {
            $q = $pdo->prepare("SELECT id, metoda, mena, castka_mena, kurz, castka_czk, uzivatel, created_at
                                FROM platby_doklad WHERE faktura_id = ? AND zdroj = 'faktura' ORDER BY id");
            $q->execute([$faId]);
            $out = $q->fetchAll(PDO::FETCH_ASSOC);
            foreach ($out as &$r) {
                $r['id'] = (int) $r['id'];
                foreach (['castka_mena', 'kurz', 'castka_czk'] as $k) $r[$k] = (float) $r[$k];
            }
            unset($r);
            return $out;
        } catch (Throwable $e) { return []; }
    }
}
