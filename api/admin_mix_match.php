<?php
/**
 * 🧩 MIX-AND-MATCH KONFIGURÁTOR — Lahůdky balíček.
 *
 * Zákazník skládá vlastní chlebíček/sandwich/obložený talíř — vybírá ingredience.
 *
 *   GET                            → seznam šablon (templates) + kategorie ingrediencí
 *   GET ?action=template&id=N      → detail šablony s kategoriemi
 *   POST ?action=template          → create/update šablona
 *   DELETE ?action=template&id=N   → smaž šablonu
 *   POST ?action=category          → create/update kategorie ingrediencí
 *   DELETE ?action=category&id=N   → smaž kategorii
 *   POST ?action=ingredient        → create/update ingredience
 *   DELETE ?action=ingredient&id=N → smaž ingredienci
 *   POST ?action=quote             → spočítej cenu konfigurace
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_packages_lib.php';

cors_headers();
require_admin();
header('Content-Type: application/json; charset=UTF-8');

if (!package_enabled('lahudky')) {
    http_response_code(402);
    json_response(['error' => 'Vyžaduje balíček 🥗 Lahůdky']);
}

$pdo = db();

// ────── auto-migrace ──────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS mix_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nazev VARCHAR(150) NOT NULL,
        popis TEXT NULL,
        ikona VARCHAR(10) NOT NULL DEFAULT '🥪',
        cena_base_kc DECIMAL(10,2) NOT NULL DEFAULT 0,
        aktivni TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS mix_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        template_id INT NOT NULL,
        nazev VARCHAR(100) NOT NULL,
        ikona VARCHAR(10) NULL,
        povinne TINYINT(1) NOT NULL DEFAULT 0,
        min_vyber INT NOT NULL DEFAULT 0,
        max_vyber INT NOT NULL DEFAULT 1,
        poradi INT NOT NULL DEFAULT 0,
        INDEX idx_template (template_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS mix_ingredients (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category_id INT NOT NULL,
        nazev VARCHAR(150) NOT NULL,
        priplatek_kc DECIMAL(8,2) NOT NULL DEFAULT 0,
        alergeny VARCHAR(200) NULL,
        aktivni TINYINT(1) NOT NULL DEFAULT 1,
        poradi INT NOT NULL DEFAULT 0,
        INDEX idx_cat (category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// Seed defaultní šablona "Chlebíček" pokud žádná není
$count = (int) $pdo->query("SELECT COUNT(*) FROM mix_templates")->fetchColumn();
if ($count === 0) {
    $pdo->exec("INSERT INTO mix_templates (nazev, ikona, popis, cena_base_kc) VALUES
        ('Chlebíček na míru', '🥪', 'Klasický český chlebíček — vyber si všechny ingredience.', 18),
        ('Bageta na míru', '🥖', 'Křupavá bageta s vybranými ingrediencemi.', 28)
    ");
    $tplId = (int) $pdo->lastInsertId() - 1; // ID chlebíčku
    $tplBageta = $tplId + 1;
    // Kategorie pro chlebíček
    $pdo->exec("INSERT INTO mix_categories (template_id, nazev, ikona, povinne, min_vyber, max_vyber, poradi) VALUES
        ($tplId, 'Pomazánka', '🧈', 1, 1, 1, 1),
        ($tplId, 'Maso / ryby / sýr', '🥩', 1, 1, 2, 2),
        ($tplId, 'Doplňky', '🥒', 0, 0, 4, 3),
        ($tplBageta, 'Pečivo', '🥖', 1, 1, 1, 1),
        ($tplBageta, 'Náplň', '🥩', 1, 1, 3, 2),
        ($tplBageta, 'Zelenina', '🥬', 0, 0, 5, 3),
        ($tplBageta, 'Omáčka', '🥄', 0, 0, 2, 4)
    ");
    $catChlPom  = $tplId * 10 + 1; // simplification: just iterate by query result
    // Načti reálná id kategorií a přidej ingredience
    $cats = $pdo->query("SELECT id, template_id, nazev FROM mix_categories ORDER BY id")->fetchAll();
    foreach ($cats as $c) {
        $cid = (int)$c['id'];
        $key = strtolower($c['nazev']);
        if (strpos($key, 'pomazánka') !== false || strpos($key, 'omáčka') !== false) {
            $pdo->exec("INSERT INTO mix_ingredients (category_id, nazev, priplatek_kc, alergeny, poradi) VALUES
                ($cid, 'Vajíčková', 0, 'Vejce, hořčice', 1),
                ($cid, 'Šunková', 5, 'Mléko, hořčice', 2),
                ($cid, 'Tuňáková', 8, 'Ryby', 3),
                ($cid, 'Sýrová', 4, 'Mléko', 4),
                ($cid, 'Česneková', 0, 'Mléko, česnek', 5)
            ");
        } elseif (strpos($key, 'maso') !== false || strpos($key, 'náplň') !== false) {
            $pdo->exec("INSERT INTO mix_ingredients (category_id, nazev, priplatek_kc, alergeny, poradi) VALUES
                ($cid, 'Šunka Praga', 0, '', 1),
                ($cid, 'Salám Vysočina', 0, '', 2),
                ($cid, 'Eidam', 0, 'Mléko', 3),
                ($cid, 'Hermelín', 6, 'Mléko', 4),
                ($cid, 'Tuňák', 10, 'Ryby', 5),
                ($cid, 'Kuřecí prsa pečená', 12, '', 6)
            ");
        } elseif (strpos($key, 'doplň') !== false || strpos($key, 'zelenin') !== false) {
            $pdo->exec("INSERT INTO mix_ingredients (category_id, nazev, priplatek_kc, alergeny, poradi) VALUES
                ($cid, 'Okurka kvašáková', 0, '', 1),
                ($cid, 'Rajče', 0, '', 2),
                ($cid, 'Salát ledový', 0, '', 3),
                ($cid, 'Cibule', 0, '', 4),
                ($cid, 'Olivy černé', 3, '', 5),
                ($cid, 'Vajíčko vařené', 4, 'Vejce', 6)
            ");
        } elseif (strpos($key, 'pečivo') !== false) {
            $pdo->exec("INSERT INTO mix_ingredients (category_id, nazev, priplatek_kc, alergeny, poradi) VALUES
                ($cid, 'Bageta klasik', 0, 'Lepek', 1),
                ($cid, 'Bageta tmavá', 3, 'Lepek', 2),
                ($cid, 'Bageta cereální', 4, 'Lepek, sezam', 3),
                ($cid, 'Bageta bezlepková', 12, '', 4)
            ");
        }
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = (int) ($_GET['id'] ?? 0);

// ────── DETAIL ŠABLONY ──────
if ($action === 'template' && $method === 'GET' && $id) {
    $tpl = $pdo->prepare("SELECT * FROM mix_templates WHERE id=:id");
    $tpl->execute(['id'=>$id]);
    $t = $tpl->fetch();
    if (!$t) json_error('Šablona neexistuje', 404);
    $cats = $pdo->prepare("SELECT * FROM mix_categories WHERE template_id=:id ORDER BY poradi, id");
    $cats->execute(['id'=>$id]);
    $t['kategorie'] = $cats->fetchAll();
    foreach ($t['kategorie'] as &$c) {
        $ing = $pdo->prepare("SELECT * FROM mix_ingredients WHERE category_id=:c AND aktivni=1 ORDER BY poradi, id");
        $ing->execute(['c'=>$c['id']]);
        $c['ingredience'] = $ing->fetchAll();
    }
    json_response($t);
}

// ────── CRUD ŠABLON ──────
if ($action === 'template' && $method === 'POST') {
    $d = json_input();
    $id   = (int) ($d['id'] ?? 0);
    $nazev= trim($d['nazev'] ?? '');
    if (!$nazev) json_error('Vyplň název', 400);
    try {
        if ($id) {
            $pdo->prepare("UPDATE mix_templates SET nazev=:n, popis=:p, ikona=:i, cena_base_kc=:c WHERE id=:id")
                ->execute(['n'=>$nazev,'p'=>$d['popis'] ?? null,'i'=>$d['ikona'] ?? '🥪','c'=>$d['cena_base_kc'] ?? 0,'id'=>$id]);
        } else {
            $pdo->prepare("INSERT INTO mix_templates (nazev,popis,ikona,cena_base_kc) VALUES (:n,:p,:i,:c)")
                ->execute(['n'=>$nazev,'p'=>$d['popis'] ?? null,'i'=>$d['ikona'] ?? '🥪','c'=>$d['cena_base_kc'] ?? 0]);
            $id = (int) $pdo->lastInsertId();
        }
        json_response(['ok'=>true,'id'=>$id]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

if ($action === 'template' && $method === 'DELETE' && $id) {
    $pdo->prepare("DELETE i FROM mix_ingredients i JOIN mix_categories c ON c.id=i.category_id WHERE c.template_id=:t")->execute(['t'=>$id]);
    $pdo->prepare("DELETE FROM mix_categories WHERE template_id=:t")->execute(['t'=>$id]);
    $pdo->prepare("DELETE FROM mix_templates WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

// ────── CRUD KATEGORIÍ ──────
if ($action === 'category' && $method === 'POST') {
    $d = json_input();
    $cid = (int) ($d['id'] ?? 0);
    $nazev = trim($d['nazev'] ?? '');
    if (!$nazev || !($d['template_id'] ?? 0)) json_error('Vyplň název + template_id', 400);
    try {
        if ($cid) {
            $pdo->prepare("UPDATE mix_categories SET nazev=:n, ikona=:i, povinne=:pv, min_vyber=:mn, max_vyber=:mx, poradi=:p WHERE id=:id")
                ->execute([
                    'n'=>$nazev,'i'=>$d['ikona'] ?? null,
                    'pv'=>!empty($d['povinne']) ? 1 : 0,
                    'mn'=>(int)($d['min_vyber'] ?? 0),
                    'mx'=>(int)($d['max_vyber'] ?? 1),
                    'p'=>(int)($d['poradi'] ?? 0),'id'=>$cid,
                ]);
        } else {
            $pdo->prepare("INSERT INTO mix_categories (template_id, nazev, ikona, povinne, min_vyber, max_vyber, poradi) VALUES (:t,:n,:i,:pv,:mn,:mx,:p)")
                ->execute([
                    't'=>(int)$d['template_id'],'n'=>$nazev,'i'=>$d['ikona'] ?? null,
                    'pv'=>!empty($d['povinne']) ? 1 : 0,
                    'mn'=>(int)($d['min_vyber'] ?? 0),
                    'mx'=>(int)($d['max_vyber'] ?? 1),
                    'p'=>(int)($d['poradi'] ?? 0),
                ]);
            $cid = (int) $pdo->lastInsertId();
        }
        json_response(['ok'=>true,'id'=>$cid]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

if ($action === 'category' && $method === 'DELETE' && $id) {
    $pdo->prepare("DELETE FROM mix_ingredients WHERE category_id=:id")->execute(['id'=>$id]);
    $pdo->prepare("DELETE FROM mix_categories WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

// ────── CRUD INGREDIENCÍ ──────
if ($action === 'ingredient' && $method === 'POST') {
    $d = json_input();
    $iid = (int) ($d['id'] ?? 0);
    $nazev = trim($d['nazev'] ?? '');
    if (!$nazev || !($d['category_id'] ?? 0)) json_error('Vyplň název + category_id', 400);
    try {
        if ($iid) {
            $pdo->prepare("UPDATE mix_ingredients SET nazev=:n, priplatek_kc=:p, alergeny=:a, aktivni=:ak, poradi=:po WHERE id=:id")
                ->execute([
                    'n'=>$nazev,'p'=>(float)($d['priplatek_kc'] ?? 0),
                    'a'=>$d['alergeny'] ?? null,
                    'ak'=>!empty($d['aktivni']) || !isset($d['aktivni']) ? 1 : 0,
                    'po'=>(int)($d['poradi'] ?? 0),'id'=>$iid,
                ]);
        } else {
            $pdo->prepare("INSERT INTO mix_ingredients (category_id, nazev, priplatek_kc, alergeny, poradi) VALUES (:c,:n,:p,:a,:po)")
                ->execute([
                    'c'=>(int)$d['category_id'],'n'=>$nazev,
                    'p'=>(float)($d['priplatek_kc'] ?? 0),
                    'a'=>$d['alergeny'] ?? null,
                    'po'=>(int)($d['poradi'] ?? 0),
                ]);
            $iid = (int) $pdo->lastInsertId();
        }
        json_response(['ok'=>true,'id'=>$iid]);
    } catch (Throwable $e) { json_error_safe('DB', $e, 500); }
}

if ($action === 'ingredient' && $method === 'DELETE' && $id) {
    $pdo->prepare("DELETE FROM mix_ingredients WHERE id=:id")->execute(['id'=>$id]);
    json_response(['ok'=>true]);
}

// ────── QUOTE ──────
if ($action === 'quote' && $method === 'POST') {
    $d = json_input();
    $tplId = (int) ($d['template_id'] ?? 0);
    $picks = $d['ingredients'] ?? [];   // pole id ingrediencí
    $mnozstvi = max(1, (int) ($d['mnozstvi'] ?? 1));
    if (!$tplId) json_error('Chybí template_id', 400);

    $tpl = $pdo->prepare("SELECT cena_base_kc, nazev, ikona FROM mix_templates WHERE id=:id");
    $tpl->execute(['id'=>$tplId]);
    $t = $tpl->fetch();
    if (!$t) json_error('Šablona neexistuje', 404);

    $polozky = [['nazev' => $t['ikona'].' '.$t['nazev'], 'cena_kc' => (float)$t['cena_base_kc']]];
    $alergenSet = [];
    if (!empty($picks)) {
        $in = implode(',', array_fill(0, count($picks), '?'));
        $stmt = $pdo->prepare("SELECT nazev, priplatek_kc, alergeny FROM mix_ingredients WHERE id IN ($in)");
        $stmt->execute(array_map('intval', $picks));
        foreach ($stmt->fetchAll() as $ing) {
            $polozky[] = ['nazev' => '+ '.$ing['nazev'], 'cena_kc' => (float)$ing['priplatek_kc']];
            if (!empty($ing['alergeny'])) {
                foreach (preg_split('/[,;]\s*/', $ing['alergeny']) as $a) {
                    $a = trim($a); if ($a) $alergenSet[$a] = true;
                }
            }
        }
    }
    $cenaJednoho = array_sum(array_column($polozky, 'cena_kc'));
    $cenaCelkem = $cenaJednoho * $mnozstvi;
    json_response([
        'polozky' => $polozky,
        'cena_jednoho' => $cenaJednoho,
        'mnozstvi' => $mnozstvi,
        'cena_celkem' => $cenaCelkem,
        'alergeny' => array_keys($alergenSet),
    ]);
}

// ────── DEFAULT: seznam šablon ──────
$templates = $pdo->query("
    SELECT t.*,
        (SELECT COUNT(*) FROM mix_categories WHERE template_id=t.id) AS kategorii,
        (SELECT COUNT(*) FROM mix_ingredients i JOIN mix_categories c ON c.id=i.category_id WHERE c.template_id=t.id) AS ingredienci
    FROM mix_templates t
    ORDER BY t.aktivni DESC, t.nazev
")->fetchAll();

json_response(['templates' => $templates]);
