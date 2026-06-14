<?php
/**
 * Kategorie výrobků - CRUD pro super admina.
 *
 * Pravidla:
 * - GET — všechny kategorie + počet výrobků v každé
 * - POST — nová kategorie
 * - POST ?action=upload — nahrát obrázek (vrací { url })
 * - PUT — úprava existující kategorie
 * - DELETE — smazání pouze pokud kategorie nemá výrobky (jinak 409)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
cors_headers();

$method = $_SERVER['REQUEST_METHOD'];

// 🆕 v3.0.334 — validace nadřazené kategorie (subkategorie = max 1 úroveň, hybrid model).
function kat_validate_parent(PDO $pdo, ?int $parentId, int $selfId = 0): ?int {
    if (!$parentId) return null;
    if ($parentId === $selfId) json_error('Kategorie nemůže být svým rodičem', 400);
    $row = $pdo->prepare("SELECT parent_id FROM kategorie_vyrobku WHERE id = :id");
    $row->execute(['id' => $parentId]);
    $p = $row->fetch(PDO::FETCH_ASSOC);
    if (!$p) json_error('Nadřazená kategorie neexistuje', 400);
    if ($p['parent_id'] !== null) json_error('Subkategorie může být jen pod hlavní kategorií (max 1 úroveň)', 400);
    if ($selfId) { // tato kategorie nesmí mít vlastní děti (bránit „vnukům")
        $kids = $pdo->prepare("SELECT COUNT(*) FROM kategorie_vyrobku WHERE parent_id = :id");
        $kids->execute(['id' => $selfId]);
        if ((int) $kids->fetchColumn() > 0) json_error('Tato kategorie má vlastní subkategorie — nemůže se stát subkategorií', 400);
    }
    return $parentId;
}

// =============================================================
// UPLOAD obrázku kategorie - re-enkóduje přes GD (strip EXIF/payload).
// =============================================================
if ($method === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    require_super_admin();
    if (empty($_FILES['obrazek'])) json_error('Chybí soubor');

    $file = $_FILES['obrazek'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_error('Chyba uploadu (kód ' . $file['error'] . ')');
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) {
        json_error('Povolen je jen JPG, PNG nebo WEBP');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        json_error('Soubor je větší než 2 MB');
    }

    $img = match($mime) {
        'image/jpeg' => @imagecreatefromjpeg($file['tmp_name']),
        'image/png'  => @imagecreatefrompng($file['tmp_name']),
        'image/webp' => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$img) json_error('Nepodařilo se zpracovat obrázek');

    $w = imagesx($img);
    $h = imagesy($img);

    // Kategorie ikonu nepotřebuje větší než 200×200 px
    $max = 200;
    if ($w > $max || $h > $max) {
        if ($w >= $h) { $nw = $max; $nh = (int) ($h * $max / $w); }
        else          { $nh = $max; $nw = (int) ($w * $max / $h); }
        $resized = imagecreatetruecolor($nw, $nh);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $resized;
    }

    $upload_dir = __DIR__ . '/../uploads/kategorie';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $htaccess = $upload_dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess,
            "<FilesMatch \"\\.(php|php3|php4|php5|phtml|phar)$\">\n"
          . "    Require all denied\n"
          . "</FilesMatch>\n");
    }

    $ext = ($mime === 'image/png') ? 'png' : 'jpg';
    $filename = 'kat_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = $upload_dir . '/' . $filename;

    $ok = ($ext === 'png')
        ? imagepng($img, $target, 6)
        : imagejpeg($img, $target, 85);
    imagedestroy($img);

    if (!$ok) json_error('Nepodařilo se uložit soubor');

    json_response([
        'url' => '/uploads/kategorie/' . $filename,
        'filename' => $filename,
    ]);
}

// 🆕 v3.0.339 — produkty v kategorii (pro proklikávací výpis v modalu kategorie)
if ($method === 'GET' && ($_GET['action'] ?? '') === 'produkty') {
    require_admin();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí id', 400);
    $st = db()->prepare("SELECT id, cislo, nazev, cena_bez_dph, aktivni FROM vyrobky WHERE kategorie_id = :id ORDER BY nazev LIMIT 300");
    $st->execute(['id' => $id]);
    json_response(['produkty' => $st->fetchAll()]);
}

if ($method === 'GET') {
    require_admin();
    $stmt = db()->query("
        SELECT k.id, k.nazev, k.ikona, k.obrazek_url, k.poradi, k.aktivni, k.parent_id,
               (SELECT COUNT(*) FROM vyrobky v WHERE v.kategorie_id = k.id) AS pocet_vyrobku,
               (SELECT COUNT(*) FROM kategorie_vyrobku c WHERE c.parent_id = k.id) AS pocet_subkategorii
        FROM kategorie_vyrobku k
        ORDER BY COALESCE(k.parent_id, k.id), k.parent_id IS NOT NULL, k.poradi, k.nazev
    ");
    json_response($stmt->fetchAll());
}

if ($method === 'POST') {
    require_super_admin();
    $d = json_input();
    $nazev = trim($d['nazev'] ?? '');
    if ($nazev === '') json_error('Vyplňte název kategorie');

    $ikona   = trim($d['ikona'] ?? '🥖');
    $poradi  = (int) ($d['poradi'] ?? 999);
    $aktiv   = !empty($d['aktivni']) ? 1 : 0;
    $obrazek = !empty($d['obrazek_url']) ? trim($d['obrazek_url']) : null;
    $parent  = kat_validate_parent(db(), isset($d['parent_id']) && $d['parent_id'] ? (int) $d['parent_id'] : null);

    $stmt = db()->prepare("
        INSERT INTO kategorie_vyrobku (nazev, ikona, obrazek_url, poradi, aktivni, parent_id)
        VALUES (:n, :i, :o, :p, :a, :par)
    ");
    $stmt->execute([
        'n' => $nazev,
        'i' => $ikona,
        'o' => $obrazek,
        'p' => $poradi,
        'a' => $aktiv,
        'par' => $parent,
    ]);

    json_response(['ok' => true, 'id' => (int) db()->lastInsertId()]);
}

if ($method === 'PUT') {
    require_super_admin();
    $d = json_input();
    $id = (int) ($d['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    $nazev = trim($d['nazev'] ?? '');
    if ($nazev === '') json_error('Vyplňte název kategorie');

    // Smaž starý obrázek, pokud se mění
    if (array_key_exists('obrazek_url', $d)) {
        $stary = db()->prepare("SELECT obrazek_url FROM kategorie_vyrobku WHERE id = :id");
        $stary->execute(['id' => $id]);
        $stary_url = $stary->fetchColumn();
        $novy_url  = !empty($d['obrazek_url']) ? trim($d['obrazek_url']) : null;
        if ($stary_url && $stary_url !== $novy_url) {
            $stary_path = realpath(__DIR__ . '/..' . $stary_url);
            $upload_dir = realpath(__DIR__ . '/../uploads/kategorie');
            // Bezpečnostní kontrola - cesta musí být v uploads/kategorie/
            if ($stary_path && $upload_dir && str_starts_with($stary_path, $upload_dir)) {
                @unlink($stary_path);
            }
        }
    }

    $obrazek_save = array_key_exists('obrazek_url', $d) && !empty($d['obrazek_url'])
        ? trim($d['obrazek_url'])
        : null;

    $parent = kat_validate_parent(db(), array_key_exists('parent_id', $d) && $d['parent_id'] ? (int) $d['parent_id'] : null, $id);

    $stmt = db()->prepare("
        UPDATE kategorie_vyrobku
        SET nazev = :n, ikona = :i, obrazek_url = :o, poradi = :p, aktivni = :a, parent_id = :par
        WHERE id = :id
    ");
    $stmt->execute([
        'id' => $id,
        'n'  => $nazev,
        'i'  => trim($d['ikona'] ?? '🥖'),
        'o'  => $obrazek_save,
        'p'  => (int) ($d['poradi'] ?? 999),
        'a'  => !empty($d['aktivni']) ? 1 : 0,
        'par' => $parent,
    ]);

    json_response(['ok' => true]);
}

if ($method === 'DELETE') {
    require_super_admin();
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) json_error('Chybí ID');

    // Kontrola - kategorie nesmí obsahovat výrobky
    $cnt = db()->prepare("SELECT COUNT(*) FROM vyrobky WHERE kategorie_id = :id");
    $cnt->execute(['id' => $id]);
    if ((int) $cnt->fetchColumn() > 0) {
        json_error('Kategorie obsahuje výrobky. Nejdřív přesuňte výrobky jinam nebo je smažte.', 409);
    }

    // 🆕 v3.0.334 — hlavní kategorie nesmí mít subkategorie
    $sub = db()->prepare("SELECT COUNT(*) FROM kategorie_vyrobku WHERE parent_id = :id");
    $sub->execute(['id' => $id]);
    if ((int) $sub->fetchColumn() > 0) {
        json_error('Kategorie má subkategorie. Nejdřív smažte nebo přesuňte subkategorie.', 409);
    }

    // Smaž i fyzický soubor obrázku, pokud je
    $obr = db()->prepare("SELECT obrazek_url FROM kategorie_vyrobku WHERE id = :id");
    $obr->execute(['id' => $id]);
    $url = $obr->fetchColumn();
    if ($url) {
        $path = realpath(__DIR__ . '/..' . $url);
        $upload_dir = realpath(__DIR__ . '/../uploads/kategorie');
        if ($path && $upload_dir && str_starts_with($path, $upload_dir)) {
            @unlink($path);
        }
    }

    $stmt = db()->prepare("DELETE FROM kategorie_vyrobku WHERE id = :id");
    $stmt->execute(['id' => $id]);

    json_response(['ok' => true]);
}

json_error('Metoda není podporována', 405);
