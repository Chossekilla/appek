<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_admin();
require_super_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

try {
    if ($method === 'GET') {
        // Seznam sazeb + počet výrobků používajících každou sazbu
        $sazby = $pdo->query("
            SELECT s.id, s.sazba, s.nazev, s.platne_od,
                   (SELECT COUNT(*) FROM vyrobky v WHERE v.sazba_dph_id = s.id) AS pocet_vyrobku
            FROM sazby_dph s
            ORDER BY s.sazba
        ")->fetchAll();

        json_response(['sazby' => $sazby]);
    }

    if ($method === 'POST') {
        // Vytvořit novou sazbu
        $d = json_input();
        $sazba = isset($d['sazba']) ? (float) $d['sazba'] : 0;
        $nazev = trim($d['nazev'] ?? '');
        $platne_od = $d['platne_od'] ?? date('Y-m-d');

        if ($sazba < 0 || $sazba > 100) json_error('Sazba musí být 0 až 100 %');
        if ($nazev === '') json_error('Chybí název');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $platne_od)) json_error('Neplatné datum');

        $pdo->prepare("
            INSERT INTO sazby_dph (sazba, nazev, platne_od)
            VALUES (:s, :n, :p)
        ")->execute(['s' => $sazba, 'n' => $nazev, 'p' => $platne_od]);

        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }

    if ($method === 'PUT') {
        // Editace existující sazby
        $d = json_input();
        $id = (int) ($d['id'] ?? 0);
        if (!$id) json_error('Chybí ID');

        $sazba = isset($d['sazba']) ? (float) $d['sazba'] : 0;
        $nazev = trim($d['nazev'] ?? '');
        $platne_od = $d['platne_od'] ?? date('Y-m-d');

        if ($sazba < 0 || $sazba > 100) json_error('Sazba musí být 0 až 100 %');
        if ($nazev === '') json_error('Chybí název');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $platne_od)) json_error('Neplatné datum');

        $stmt = $pdo->prepare("
            UPDATE sazby_dph
            SET sazba = :s, nazev = :n, platne_od = :p
            WHERE id = :id
        ");
        $stmt->execute(['s' => $sazba, 'n' => $nazev, 'p' => $platne_od, 'id' => $id]);

        json_response(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) json_error('Chybí ID');

        // Ověř, že žádný výrobek nepoužívá tuto sazbu
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM vyrobky WHERE sazba_dph_id = :id");
        $stmt->execute(['id' => $id]);
        $pocet = (int) $stmt->fetchColumn();
        if ($pocet > 0) {
            json_error("Sazbu nelze smazat — používá ji $pocet výrobků. Nejprve je převeďte na jinou sazbu.", 400);
        }

        $pdo->prepare("DELETE FROM sazby_dph WHERE id = :id")->execute(['id' => $id]);
        json_response(['ok' => true]);
    }

    json_error('Method not allowed', 405);

} catch (Throwable $e) {
    json_error_safe('Server error', $e, 500);
}
