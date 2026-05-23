<?php
/**
 * Správa admin uživatelů (super admin / prodavač).
 * Pouze super admin (role = 'admin') má přístup.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();
require_super_admin();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    $stmt = $pdo->query("
        SELECT id, email, jmeno, role, aktivni,
               posledni_login AS posledni_prihlaseni,
               vytvoreno AS created_at
        FROM admin_users
        ORDER BY role, jmeno
    ");
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    $d = json_input();

    $email = trim($d['email'] ?? '');
    $jmeno = trim($d['jmeno'] ?? '');
    $heslo = $d['heslo'] ?? '';
    $role  = $d['role'] ?? 'prodavac';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('Neplatný email');
    if (strlen($heslo) < 6) json_error('Heslo musí mít alespoň 6 znaků');
    if (!in_array($role, ['admin','prodavac','vyroba','expedice'], true)) {
        json_error('Neplatná role');
    }
    if ($jmeno === '') $jmeno = $email;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO admin_users (email, heslo_hash, jmeno, role, aktivni)
            VALUES (:em, :h, :j, :r, 1)
        ");
        $stmt->execute([
            'em' => $email,
            'h' => password_hash($heslo, PASSWORD_DEFAULT),
            'j' => $jmeno,
            'r' => $role,
        ]);
        json_response(['id' => $pdo->lastInsertId()], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_error('Email už existuje', 409);
        }
        error_log('admin_users POST: ' . $e->getMessage());
        json_error('Nepodařilo se vytvořit uživatele', 500);
    }
}

if ($method === 'PUT') {
    $d = json_input();
    // 🐛 fix v2.9.180 — přijmout ID z query stringu i z body (REST i RPC styl).
    $id = (int) ($d['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    // Super admin nesmí degradovat sám sebe (zabránit zamknutí systému)
    if ($id === (int) $_SESSION['admin_id'] && isset($d['role']) && $d['role'] !== 'admin') {
        json_error('Nemůžete změnit svou vlastní roli z admin', 400);
    }
    // A nesmí ani sebe deaktivovat
    if ($id === (int) $_SESSION['admin_id'] && isset($d['aktivni']) && !$d['aktivni']) {
        json_error('Nemůžete deaktivovat sám sebe', 400);
    }

    $sets = []; $params = ['id' => $id];
    if (isset($d['jmeno'])) { $sets[] = "jmeno = :j"; $params['j'] = trim($d['jmeno']); }
    if (isset($d['email'])) {
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) json_error('Neplatný email');
        $sets[] = "email = :e"; $params['e'] = $d['email'];
    }
    if (isset($d['role'])) {
        if (!in_array($d['role'], ['admin','prodavac','vyroba','expedice'], true)) {
            json_error('Neplatná role');
        }
        $sets[] = "role = :r"; $params['r'] = $d['role'];
    }
    if (isset($d['aktivni'])) {
        $sets[] = "aktivni = :a"; $params['a'] = (int) $d['aktivni'];
    }
    if (!empty($d['heslo'])) {
        if (strlen($d['heslo']) < 6) json_error('Heslo musí mít alespoň 6 znaků');
        $sets[] = "heslo_hash = :h";
        $params['h'] = password_hash($d['heslo'], PASSWORD_DEFAULT);
    }

    if (empty($sets)) json_error('Žádné změny');

    try {
        $pdo->prepare("UPDATE admin_users SET " . implode(', ', $sets) . " WHERE id = :id")
            ->execute($params);
        json_response(['ok' => true]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_error('Email už existuje u jiného uživatele', 409);
        }
        throw $e;
    }
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    // Super admin nesmí smazat sám sebe
    if ($id === (int) $_SESSION['admin_id']) {
        json_error('Nemůžete smazat sám sebe', 400);
    }

    // Pokud je poslední aktivní super admin, nedovolit smazat
    $stmt = $pdo->prepare("SELECT role FROM admin_users WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $cilova_role = $stmt->fetchColumn();
    if ($cilova_role === 'admin') {
        $cnt = $pdo->query("SELECT COUNT(*) FROM admin_users WHERE role = 'admin' AND aktivni = 1")
                    ->fetchColumn();
        if ((int) $cnt <= 1) {
            json_error('Nelze smazat jediného super admina v systému', 400);
        }
    }

    $pdo->prepare("DELETE FROM admin_users WHERE id = :id")->execute(['id' => $id]);
    json_response(['ok' => true]);
}

json_error('Neplatná metoda', 405);
