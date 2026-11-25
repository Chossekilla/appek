<?php
// Pomocník pro scripts/sync_e2e.sh — založí online objednávku (kanál „web“, řada WEB) v instanci <root>
// stejně jako api/web_objednavky.php?action=order, ale bez licenční brány balíčku „restaurace“
// (ta v lokální DB nemusí být zapnutá) a bez HTTP. Vypíše číslo objednávky, nebo CHYBA.
//   php scripts/sync_e2e_obj.php <root instance> <jméno> <id výrobku> <ks>
[, $root, $jmeno, $vid, $ks] = $argv + [null, '', '', 0, 1];
chdir($root . '/api');
require $root . '/api/config.php';
try {
    $pdo = db();
    $v = $pdo->prepare("SELECT v.nazev, v.cena_bez_dph, COALESCE(s.sazba, 21) sazba FROM vyrobky v LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id WHERE v.id = :id");
    $v->execute(['id' => (int) $vid]);
    $v = $v->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new Exception("výrobek $vid neexistuje");
    $bez = round((float) $v['cena_bez_dph'] * (int) $ks, 2);
    $dph = round($bez * (float) $v['sazba'] / 100, 2);
    $pdo->beginTransaction();
    $odbId = (int) ($pdo->query("SELECT id FROM odberatele WHERE nazev = '🛍️ Online zákazníci' LIMIT 1")->fetchColumn() ?: 0);
    if (!$odbId) throw new Exception('chybí odběratel „🛍️ Online zákazníci“ (má být v DB před kopií pro cloud)');
    $cislo = kanal_dalsi_cislo($pdo, 'web');
    $pdo->prepare("INSERT INTO objednavky (cislo, typ, odberatel_id, datum_objednani, datum_dodani, castka_bez_dph, castka_dph, castka_celkem,
                     stav, puvod, pos_typ, pos_payment, poznamka)
                   VALUES (:c, 'web', :o, NOW(), CURDATE(), :b, :d, :ck, 'nova', 'web', 'vyzvednuti', 'pri_vyzvednuti', :pz)")
        ->execute(['c' => $cislo, 'o' => $odbId, 'b' => $bez, 'd' => $dph, 'ck' => $bez + $dph, 'pz' => "📦 Vyzvednutí\n👤 $jmeno · sync e2e"]);
    $objId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO objednavky_polozky (objednavka_id, vyrobek_id, vyrobek_nazev, jednotka, mnozstvi, cena_bez_dph, sazba_dph)
                   VALUES (:o, :v, :n, 'ks', :m, :c, :s)")
        ->execute(['o' => $objId, 'v' => (int) $vid, 'n' => mb_substr($v['nazev'], 0, 255), 'm' => (int) $ks, 'c' => (float) $v['cena_bez_dph'], 's' => $v['sazba']]);
    $pdo->commit();
    echo $cislo;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    echo 'CHYBA';
}
