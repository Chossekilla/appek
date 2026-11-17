<?php
/**
 * 💱 🆕 v3.0.645 — volba měny u RUČNĚ zakládaných dokladů mimo „Novou objednávku"
 *   (catering z kalkulačky, dort z konfigurátoru, ruční dodací list).
 *
 * Stejná logika jako ruční objednávka v api/admin_objednavky.php (v3.0.643):
 *   - platby ve více měnách VYPNUTÉ → null = doklad dostane snapshot jako dřív (mena_snapshot_doklad)
 *     a případné pole `mena` z klienta se ignoruje (zpětná kompatibilita starších klientů);
 *   - výslovná volba ve formuláři → musí být povolená (mena_volba_platby, kanál 'admin'),
 *     jinak MenaException (volající ji vrátí jako chybu, doklad nevznikne);
 *   - bez volby → výchozí měna odběratele (odberatele.mena_platby); když pro ni není kurz,
 *     tiše jako dřív (null) — nikdo cizí měnu výslovně nevolil.
 *
 * VOLAT PŘED beginTransaction (mena_volba_platby může líně stahovat kurz ČNB).
 */
require_once __DIR__ . '/_mena_lib.php';

if (!function_exists('mena_rucni_volba')) {
    /**
     * @param mixed $volba  hodnota `mena` z požadavku ('' / null = podle odběratele)
     * @return array|null   snapshot pro mena_nastav_doklad, nebo null = legacy snapshot
     * @throws MenaException jen při výslovné (neplatné / nepovolené / bez kurzu) volbě
     */
    function mena_rucni_volba(PDO $pdo, $volba, int $odbId): ?array {
        if (empty(mena_config_load($pdo)['platba']['aktivni'])) return null;
        $vyslovna = is_string($volba) ? strtoupper(trim($volba)) : '';
        $mk = $vyslovna;
        if ($mk === '' && $odbId > 0) {
            try {
                $sm = $pdo->prepare("SELECT mena_platby FROM odberatele WHERE id = ?");
                $sm->execute([$odbId]);
                $mk = strtoupper(trim((string) $sm->fetchColumn()));
            } catch (Throwable $e) { $mk = ''; }   // sloupec ještě neexistuje → jako dřív
        }
        if ($mk === '') return null;
        try {
            return mena_volba_platby($pdo, $mk, 'admin');
        } catch (MenaException $e) {
            if ($vyslovna !== '') throw $e;   // výslovná volba → chyba
            return null;                       // jen výchozí měna odběratele bez kurzu → jako dřív
        }
    }

    /**
     * Zapíše zvolenou měnu na NOVĚ vložený doklad (v transakci volajícího), bez volby legacy snapshot.
     * Objednávka: přepis povolen (= vzor admin_objednavky). Dodací list přepsat nejde → zapíše se jen
     * na doklad bez snapshotu; kdyby tam už byl jiný, je to chyba (doklad nesmí vzniknout v jiné měně).
     */
    function mena_rucni_zapsat(PDO $pdo, string $tab, int $id, ?array $m): void {
        if (!in_array($tab, ['objednavky', 'dodaci_listy'], true)) throw new InvalidArgumentException("mena_rucni_zapsat: neznámý typ $tab");
        if (!$m) { mena_snapshot_doklad($pdo, $tab, $id); return; }
        mena_nastav_doklad($pdo, $tab, $id, $m, $tab === 'objednavky');
        $st = $pdo->prepare("SELECT mena FROM `$tab` WHERE id = ?");
        $st->execute([$id]);
        if (strtoupper((string) $st->fetchColumn()) !== strtoupper((string) $m['mena'])) {
            throw new MenaException('mena_neulozena', 'Měnu dokladu se nepodařilo uložit.', 500);
        }
    }
}
