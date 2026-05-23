<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    $odb = (int) ($_GET['odberatel_id'] ?? 0);
    if (!$odb) json_error('Chybí ID odběratele');

    $stmt = $pdo->prepare("
        SELECT * FROM mista_dodani
        WHERE odberatel_id = :o
        ORDER BY vychozi DESC, poradi, nazev
    ");
    $stmt->execute(['o' => $odb]);
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $d = json_input();
    if (empty($d['odberatel_id']) || empty($d['nazev'])) {
        json_error('Chybí povinné údaje');
    }
    $odb_id = (int) $d['odberatel_id'];

    $pdo->beginTransaction();
    try {
        // Pokud je nastaveno vychozí, ostatní zruš
        if (!empty($d['vychozi'])) {
            $pdo->prepare("UPDATE mista_dodani SET vychozi = 0 WHERE odberatel_id = :o")
                ->execute(['o' => $odb_id]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO mista_dodani (odberatel_id, nazev, ulice, mesto, psc,
                                      kontaktni_osoba, telefon, email,
                                      cas_dodani, pokyny_pro_ridice,
                                      vychozi, aktivni, poradi)
            VALUES (:o,:n,:ul,:me,:psc,:ko,:tel,:em,:cd,:pok,:vy,:ak,:po)
        ");
        $stmt->execute([
            'o' => $odb_id, 'n' => trim($d['nazev']),
            'ul' => $d['ulice'] ?? null, 'me' => $d['mesto'] ?? null,
            'psc' => $d['psc'] ?? null, 'ko' => $d['kontaktni_osoba'] ?? null,
            'tel' => $d['telefon'] ?? null, 'em' => $d['email'] ?? null,
            'cd' => $d['cas_dodani'] ?? null, 'pok' => $d['pokyny_pro_ridice'] ?? null,
            'vy' => isset($d['vychozi']) ? (int) $d['vychozi'] : 0,
            'ak' => isset($d['aktivni']) ? (int) $d['aktivni'] : 1,
            'po' => $d['poradi'] ?? 0,
        ]);
        $new_id = (int) $pdo->lastInsertId();
        $pdo->commit();
        json_response(['id' => $new_id], 201);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_pobocky POST: ' . $e->getMessage());
        json_error('Nepodařilo se uložit pobočku', 500);
    }
}

if ($method === 'PUT') {
    $d = json_input();
    if (empty($d['id'])) json_error('Chybí ID');

    $pdo->beginTransaction();
    try {
        if (!empty($d['vychozi'])) {
            $stmt = $pdo->prepare("SELECT odberatel_id FROM mista_dodani WHERE id = :id");
            $stmt->execute(['id' => $d['id']]);
            $odb = $stmt->fetchColumn();
            if ($odb) {
                $pdo->prepare("UPDATE mista_dodani SET vychozi = 0 WHERE odberatel_id = :o")
                    ->execute(['o' => $odb]);
            }
        }

        $stmt = $pdo->prepare("
            UPDATE mista_dodani SET
                nazev = :n, ulice = :ul, mesto = :me, psc = :psc,
                kontaktni_osoba = :ko, telefon = :tel, email = :em,
                cas_dodani = :cd, pokyny_pro_ridice = :pok,
                vychozi = :vy, aktivni = :ak, poradi = :po
            WHERE id = :id
        ");
        $stmt->execute([
            'id' => (int) $d['id'], 'n' => trim($d['nazev']),
            'ul' => $d['ulice'] ?? null, 'me' => $d['mesto'] ?? null,
            'psc' => $d['psc'] ?? null, 'ko' => $d['kontaktni_osoba'] ?? null,
            'tel' => $d['telefon'] ?? null, 'em' => $d['email'] ?? null,
            'cd' => $d['cas_dodani'] ?? null, 'pok' => $d['pokyny_pro_ridice'] ?? null,
            'vy' => isset($d['vychozi']) ? (int) $d['vychozi'] : 0,
            'ak' => isset($d['aktivni']) ? (int) $d['aktivni'] : 1,
            'po' => $d['poradi'] ?? 0,
        ]);
        $pdo->commit();
        json_response(['ok' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('admin_pobocky PUT: ' . $e->getMessage());
        json_error('Nepodařilo se uložit změny', 500);
    }
}

if ($method === 'DELETE') {
    require_super_admin(); // jen super admin smí mazat pobočky
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    // Pokud je pobočka použitá v objednávce nebo DL, jen ji deaktivuj
    // 🐛 fix v2.9.169 — native PDO neumožňuje reuse :id 2x; distinct placeholdery.
    $cnt = $pdo->prepare("
        SELECT
            (SELECT COUNT(*) FROM objednavky    WHERE misto_dodani_id = :id1) +
            (SELECT COUNT(*) FROM dodaci_listy  WHERE misto_dodani_id = :id2)
    ");
    $cnt->execute(['id1' => $id, 'id2' => $id]);
    if ($cnt->fetchColumn() > 0) {
        $pdo->prepare("UPDATE mista_dodani SET aktivni = 0 WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true, 'deactivated' => true]);
    }
    $pdo->prepare("DELETE FROM mista_dodani WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true, 'deleted' => true]);
}

json_error('Neplatná metoda', 405);
