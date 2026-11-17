<?php
/**
 * 🐛 v3.0.644 — sdílené pomocníky pro vystavování faktur (z objednávky / z DL / hromadně).
 *
 *   faktura_soucty_z_dl()          — hlavička faktury (bez DPH / DPH / celkem) ze SKUTEČNÝCH řádků DL,
 *                                    které faktura tiskne (dřív se brala castka_* z objednávky →
 *                                    po úpravě DL faktura účtovala nedodané zboží, rekapitulace DPH
 *                                    neseděla s řádky).
 *   faktura_ulozit_snapshot_odb()  — údaje odběratele platné ke dni vystavení (adresa/IČO/DIČ);
 *                                    PDF/ISDOC/účetní export je pak berou ze snapshotu, ne z aktuálního
 *                                    záznamu odběratele (vydaný daňový doklad se zpětně nemění).
 *   faktura_sql_dobropisy()        — SQL podvýraz: součet dobropisů k faktuře (záporné číslo, 0 = žádný)
 *                                    → saldo pohledávky = castka_celkem + dobropisy − castka_uhrazeno.
 */

if (!function_exists('faktura_soucty_z_dl')) {
    /**
     * Součty z položek dodacích listů — STEJNÝ výpočet jako admin_faktura_z_dl.php
     * (Σ mnozstvi×cena a Σ DPH po řádcích, zaokrouhleno až na konci).
     * Řádky čte zamykacím čtením (LOCK IN SHARE MODE), aby uvnitř transakce viděl poslední
     * potvrzený stav i po dřívějším čtení (REPEATABLE READ snapshot). Mimo transakci je zámek okamžitě uvolněn.
     * @return array{bez: float, dph: float, celkem: float}|null  null = DL nemají žádné položky
     */
    function faktura_soucty_z_dl(PDO $pdo, array $dlIds): ?array {
        $dlIds = array_values(array_unique(array_filter(array_map('intval', $dlIds))));
        if (!$dlIds) return null;
        $ph = implode(',', array_fill(0, count($dlIds), '?'));
        $st = $pdo->prepare("SELECT mnozstvi, cena_bez_dph, sazba_dph FROM dodaci_list_polozky
                             WHERE dodaci_list_id IN ($ph) LOCK IN SHARE MODE");
        $st->execute($dlIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) return null;
        $bez = 0.0; $dph = 0.0;
        foreach ($rows as $p) {
            $b = (float) $p['cena_bez_dph'] * (float) $p['mnozstvi'];
            $bez += $b;
            $dph += $b * ((float) ($p['sazba_dph'] ?? 0) / 100);
        }
        $bez = round($bez, 2);
        $dph = round($dph, 2);
        return ['bez' => $bez, 'dph' => $dph, 'celkem' => round($bez + $dph, 2)];
    }
}

if (!function_exists('faktura_ulozit_snapshot_odb')) {
    /** Uloží na fakturu snapshot údajů odběratele (stav ke dni vystavení). Tichý no-op na staré DB bez sloupců. */
    function faktura_ulozit_snapshot_odb(PDO $pdo, int $faId): void {
        try {
            $pdo->prepare("
                UPDATE faktury f JOIN odberatele od ON od.id = f.odberatel_id
                SET f.odb_nazev_snapshot = od.nazev, f.odb_ico_snapshot = od.ico, f.odb_dic_snapshot = od.dic,
                    f.odb_ulice_snapshot = od.ulice, f.odb_mesto_snapshot = od.mesto, f.odb_psc_snapshot = od.psc
                WHERE f.id = :id
            ")->execute(['id' => $faId]);
        } catch (Throwable $e) {
            error_log('faktura_ulozit_snapshot_odb: ' . $e->getMessage());
        }
    }
}

if (!function_exists('faktura_sql_dobropisy')) {
    /** SQL: součet dobropisů (≤ 0) k faktuře s aliasem $a. Využívá index idx_puvodni_faktura. */
    function faktura_sql_dobropisy(string $a = 'f'): string {
        return "(SELECT COALESCE(SUM(dbx.castka_celkem), 0) FROM faktury dbx
                 WHERE dbx.puvodni_faktura_id = $a.id AND dbx.je_dobropis = 1)";
    }
}
