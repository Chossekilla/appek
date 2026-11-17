<?php
/**
 * Rozpis výroby po pobočkách (místo dodání × odběratel) — SDÍLENÉ pro
 * admin_vyroba.php (obrazovka Výroba) i vyrobni_list_print.php (tisk/PDF).
 *
 * Dřív byla tahle logika zkopírovaná v obou souborech a brala jen PRVNÍ objednávku
 * na místo: číslo + poznámka dalších objednávek se ztratily, položky se opakovaly
 * (3× „Veka 23 ks" místo 69 ks) a UI počítalo „Objednávek" z neexistujícího pole → vždy 1.
 *
 * Vstupní řádek (z JOINu objednavky × polozky × vyrobky × mista_dodani × odberatele):
 *   odberatel_id, odberatel, misto_id, misto, ulice, mesto, psc,
 *   misto_kontakt|kontaktni_osoba, misto_tel, cas_dodani, pokyny_pro_ridice,
 *   objednavka_id, objednavka_cislo, poznamka, vyrobek_id, vyrobek, mnozstvi
 */

function vyroba_po_pobockach(array $rozpis): array {
    $g = [];
    foreach ($rozpis as $r) {
        $key = ($r['misto_id'] ?? 'no') . '|' . $r['odberatel_id'];
        if (!isset($g[$key])) {
            $g[$key] = [
                'odberatel'      => $r['odberatel'],
                'misto'          => $r['misto'] ?? '—',
                'adresa'         => trim(($r['ulice'] ?? '') . ', ' . ($r['mesto'] ?? '') . ' ' . ($r['psc'] ?? ''), ' ,'),
                'kontakt'        => $r['misto_kontakt'] ?? ($r['kontaktni_osoba'] ?? null),
                'telefon'        => $r['misto_tel'] ?? null,
                'cas_dodani'     => $r['cas_dodani'] ?? null,
                'pokyny'         => $r['pokyny_pro_ridice'] ?? null,
                'objednavky'     => [],   // čísla všech objednávek na tomto místě
                'objednavka_ids' => [],
                '_pozn'          => [],   // cislo => poznámka (jen neprázdné)
                '_pol'           => [],   // vyrobek_id => [vyrobek, mnozstvi] (sečteno)
            ];
        }
        $oid = (int) $r['objednavka_id'];
        if (!in_array($oid, $g[$key]['objednavka_ids'], true)) {
            $g[$key]['objednavka_ids'][] = $oid;
            $g[$key]['objednavky'][] = (string) $r['objednavka_cislo'];
            $pz = trim((string) ($r['poznamka'] ?? ''));
            if ($pz !== '') $g[$key]['_pozn'][(string) $r['objednavka_cislo']] = $pz;
        }
        $vk = (string) ($r['vyrobek_id'] ?? $r['vyrobek']);
        if (!isset($g[$key]['_pol'][$vk])) $g[$key]['_pol'][$vk] = ['vyrobek' => $r['vyrobek'], 'mnozstvi' => 0.0];
        $g[$key]['_pol'][$vk]['mnozstvi'] += (float) $r['mnozstvi'];
    }
    foreach ($g as &$p) {
        $multi = count($p['objednavky']) > 1;
        $pozn = [];
        foreach ($p['_pozn'] as $cislo => $text) $pozn[] = $multi ? "$cislo: $text" : $text;
        // zpětně kompatibilní pole (UI i tisk je čtou)
        $p['objednavka_cislo'] = implode(', ', $p['objednavky']);
        $p['poznamka'] = implode("\n", $pozn);
        $p['polozky'] = array_values($p['_pol']);
        unset($p['_pozn'], $p['_pol']);
    }
    unset($p);
    return array_values($g);
}

function vyroba_pocet_objednavek(array $rozpis): int {
    return count(array_unique(array_map(fn($r) => (int) $r['objednavka_id'], $rozpis)));
}

/**
 * 🐛 v3.0.644 — Filtr objednávek, které se POČÍTAJÍ do výroby / spotřeby surovin daného dne.
 *   Pokladna (puvod='pos' — walk-in, dine-in účet, QR účet) odepisuje suroviny už PŘI PRODEJI
 *   (pos_deduct_ingredients) a prodává hotové zboží → do potřeby výroby nepatří. Dřív je
 *   „Odepsat suroviny“ odečetl podruhé (sklad do mínusu) a výrobní list je chtěl vyrobit znovu.
 *   Vratky (refund_of = VRA- se zápornými množstvími) potřebu nesnižují.
 *   Vrací SQL fragment „ AND …“ pro alias objednávek $a. Sloupce nemusí existovat (staré instalace).
 */
function vyroba_obj_filtr_sql(PDO $pdo, string $a = 'o'): string {
    static $cols = null;
    if ($cols === null) {
        try { $cols = $pdo->query("SHOW COLUMNS FROM objednavky")->fetchAll(PDO::FETCH_COLUMN); }
        catch (Throwable $e) { $cols = []; }
    }
    $sql = '';
    if (in_array('puvod', $cols, true))     $sql .= " AND COALESCE({$a}.puvod, '') <> 'pos'";
    if (in_array('refund_of', $cols, true)) $sql .= " AND {$a}.refund_of IS NULL";
    return $sql;
}
