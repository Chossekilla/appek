<?php
/**
 * 🥗 CATERING — sdílené pure helpery (includable z endpointu i z testů).
 *
 * 🆕 v3.0.423 — vyčleněno z admin_catering_calc.php, aby šlo unit-testovat
 *   (endpoint na začátku volá require_admin() → nejde includnout do CLI testu).
 *   Vzor = _bom_lib.php / _seasonal_lib.php.
 */

if (!function_exists('catering_material_cost')) {
    // Materiálové náklady výrobku z receptury (Σ mnozstvi × jednotková cena suroviny).
    function catering_material_cost(PDO $pdo, int $vyrobek_id): float {
        try {
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(vs.mnozstvi * (s.cena_baleni / NULLIF(s.obsah_baleni, 0))), 0)
                FROM vyrobek_suroviny vs JOIN suroviny s ON s.id = vs.surovina_id
                WHERE vs.vyrobek_id = :v
            ");
            $st->execute(['v' => $vyrobek_id]);
            return round((float) $st->fetchColumn(), 2);
        } catch (Throwable $e) { return 0.0; }
    }
}

if (!function_exists('catering_clean_produkty')) {
    // 🆕 v3.0.423 — sanitizace produktů z katalogu (přístup A, viz spec).
    //   Zahodí položky bez napojení na výrobek, ořeže porce na >=0, coerce boolů.
    function catering_clean_produkty($produkty): array {
        $out = [];
        if (!is_array($produkty)) return $out;
        foreach ($produkty as $p) {
            if (!is_array($p)) continue;
            $vid = (int) ($p['vyrobek_id'] ?? 0);
            if ($vid <= 0) continue;               // bez napojení na výrobek nemá smysl
            $out[] = [
                'vyrobek_id'     => $vid,
                'porce_na_osobu' => (float) max(0, round((float) ($p['porce_na_osobu'] ?? 1), 4)),
                'aktivni'        => !empty($p['aktivni']),
                'povinne'        => !empty($p['povinne']),
                'poradi'         => (int) ($p['poradi'] ?? 0),
            ];
        }
        return $out;
    }
}

if (!function_exists('catering_decorate_produkty')) {
    // 🆕 v3.0.423 — dekoruj nakonfigurované produkty joinem na vyrobky
    //   (název/cena/kategorie/material). Smazaný/neaktivní výrobek → smazany:true.
    function catering_decorate_produkty(PDO $pdo, array $produkty): array {
        if (!$produkty) return [];
        $ids = array_values(array_unique(array_map(fn($p) => (int) $p['vyrobek_id'], $produkty)));
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $map = [];
        try {
            $st = $pdo->prepare("
                SELECT v.id, v.nazev, ROUND(v.cena_bez_dph,2) AS cena, v.kategorie_id,
                       COALESCE(k.nazev,'') AS kategorie, COALESCE(sd.sazba,12) AS dph
                FROM vyrobky v
                LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
                LEFT JOIN sazby_dph sd ON sd.id = v.sazba_dph_id
                WHERE v.aktivni = 1 AND v.id IN ($in)
            ");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $map[(int) $r['id']] = $r;
        } catch (Throwable $e) {}

        $out = [];
        foreach ($produkty as $p) {
            $vid = (int) $p['vyrobek_id'];
            $v = $map[$vid] ?? null;
            if (!$v) { $out[] = $p + ['smazany' => true, 'nazev' => '(smazaný produkt)']; continue; }
            $out[] = $p + [
                'nazev'        => $v['nazev'],
                'cena'         => (float) $v['cena'],
                'material'     => catering_material_cost($pdo, $vid),
                'kategorie_id' => (int) $v['kategorie_id'],
                'kategorie'    => $v['kategorie'],
                'dph'          => (float) $v['dph'],
                'smazany'      => false,
            ];
        }
        return $out;
    }
}
