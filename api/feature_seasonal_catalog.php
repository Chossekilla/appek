<?php
/**
 * 🍂 SEZÓNNÍ KATALOG — feature 'seasonal_pricing' (balíček Sezónní akce).
 *
 * Z vybraných sezónních výrobků generuje:
 *   - tematický HTML e-mail (Vánoce/Velikonoce/Valentýn/Halloween/Jaro/Léto/Podzim)
 *   - tisknutelný PDF/print katalog
 *   - může odeslat odběratelům přes vendor SMTP
 *
 * GET                                  → list všech sezónních produktů
 * POST  ?action=preview                → render HTML email pro vybrané produkty + téma
 *       Body: { theme, subject, intro, vyrobek_ids: [], recipient_filter: 'all'|'typ:penzion'|'ids:1,2,3' }
 * POST  ?action=send                   → odešle e-mail
 *       Body: stejné + send=true
 *
 * Gating: feature_enabled('seasonal_pricing') — Sezónní balíček
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_admin_auth.php';
require_once __DIR__ . '/_features.php';

header('Content-Type: application/json; charset=UTF-8');
if (function_exists('cors_headers')) cors_headers();

if (!feature_enabled('seasonal_pricing')) {
    http_response_code(402);
    echo json_encode([
        'error' => 'upsell',
        'feature' => 'seasonal_pricing',
        'package' => 'sezona',
        'message' => 'Sezónní katalog je součástí balíčku Sezónní akce.',
    ]);
    exit;
}

require_admin();

/**
 * Tématické styly pro e-maily — barvy, font, dekorační SVG.
 */
const SEASONAL_THEMES = [
    'vanoce' => [
        'name'      => '🎄 Vánoce',
        'color_1'   => '#c41e3a',   // červená
        'color_2'   => '#0a5d2a',   // zelená
        'bg'        => '#fff5f5',
        'accent'    => '#d4af37',   // zlatá
        'emoji_dec' => '🎄✨❄️🎁',
        'intro_default' => 'Vážený zákazníku, blíží se Vánoce. Připravili jsme pro vás speciální vánoční sortiment — od vánočky a perníčků po linecké cukroví.',
        'subject_default' => '🎄 Vánoční sortiment APPEK — objednejte včas',
    ],
    'velikonoce' => [
        'name'      => '🐰 Velikonoce',
        'color_1'   => '#FFB347',   // pastelová oranžová
        'color_2'   => '#7BC74D',   // jarní zelená
        'bg'        => '#fffbe6',
        'accent'    => '#FF6B9D',   // pink
        'emoji_dec' => '🐰🥚🌷🐣',
        'intro_default' => 'Přejeme krásné jarní dny! Velikonoční sortiment je v plné kráse — mazance, beránci, perníčky.',
        'subject_default' => '🐰 Velikonoce s APPEK — sortiment 2026',
    ],
    'valentyn' => [
        'name'      => '💝 Valentýn',
        'color_1'   => '#e91e63',   // pink
        'color_2'   => '#ad1457',
        'bg'        => '#fff0f4',
        'accent'    => '#FFD700',
        'emoji_dec' => '💝💖🌹🍫',
        'intro_default' => 'Den zamilovaných — to nesmí chybět dort, makronky nebo perníkové srdce. Nabídka platí jen do 14. února.',
        'subject_default' => '💝 Valentýn — sladkosti pro zamilované',
    ],
    'halloween' => [
        'name'      => '🎃 Halloween',
        'color_1'   => '#ff6f00',
        'color_2'   => '#4a148c',
        'bg'        => '#1a1a1a',
        'accent'    => '#fff200',
        'emoji_dec' => '🎃👻🦇🍬',
        'intro_default' => 'Halloween se blíží — máme dýňové perníky, strašidelné cupcake a sušenky ve tvaru duchů.',
        'subject_default' => '🎃 Halloween sortiment — strašidelně dobré!',
    ],
    'jaro' => [
        'name'      => '🌸 Jaro',
        'color_1'   => '#7BC74D',
        'color_2'   => '#FF6B9D',
        'bg'        => '#f0fff4',
        'accent'    => '#FFD700',
        'emoji_dec' => '🌸🌷🐦🌿',
        'intro_default' => 'Jaro je tady — čerstvé výrobky, jarní speciality a sezónní ovoce. Užijte si lehkou kuchyni.',
        'subject_default' => '🌸 Jarní sortiment APPEK',
    ],
    'leto' => [
        'name'      => '☀️ Léto',
        'color_1'   => '#FFB300',
        'color_2'   => '#2196F3',
        'bg'        => '#fff8e1',
        'accent'    => '#FF6F61',
        'emoji_dec' => '☀️🍓🍒🌻',
        'intro_default' => 'Letní speciality — osvěžující limonády, jahodový dort, lehké saláty.',
        'subject_default' => '☀️ Letní sortiment — osvěžte se',
    ],
    'podzim' => [
        'name'      => '🍂 Podzim',
        'color_1'   => '#BA7517',
        'color_2'   => '#8B4513',
        'bg'        => '#fef7e6',
        'accent'    => '#D2691E',
        'emoji_dec' => '🍂🎃🌰🍎',
        'intro_default' => 'Podzimní chuti — dýňové výrobky, jablečné koláče, oříškové cukroví.',
        'subject_default' => '🍂 Podzimní sortiment — útulné chvíle',
    ],
];

try {
    $pdo = db();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'POST' && in_array($action, ['preview', 'send'], true)) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $themeKey = $body['theme'] ?? 'vanoce';
        $subject  = trim($body['subject'] ?? '');
        $intro    = trim($body['intro']   ?? '');
        $vyrobekIds = array_map('intval', (array)($body['vyrobek_ids'] ?? []));
        $recipientFilter = $body['recipient_filter'] ?? 'all';

        if (!isset(SEASONAL_THEMES[$themeKey])) {
            json_error('Neznámé téma: ' . $themeKey, 400);
        }
        if (empty($vyrobekIds)) {
            json_error('Vyber alespoň 1 výrobek.', 400);
        }

        $theme = SEASONAL_THEMES[$themeKey];
        if ($subject === '') $subject = $theme['subject_default'];
        if ($intro === '')   $intro   = $theme['intro_default'];

        // Načti vybrané výrobky
        $placeholders = implode(',', array_fill(0, count($vyrobekIds), '?'));
        $stmt = $pdo->prepare("
            SELECT v.id, v.nazev, v.cislo, v.popis, v.cena_bez_dph, v.hmotnost_g, v.alergeny,
                   k.nazev AS kategorie_nazev, k.ikona AS kategorie_ikona,
                   j.kod AS jednotka_kod,
                   s.sazba AS dph_sazba
            FROM vyrobky v
            LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
            LEFT JOIN jednotky j ON j.id = v.jednotka_id
            LEFT JOIN sazby_dph s ON s.id = v.sazba_dph_id
            WHERE v.id IN ($placeholders) AND v.aktivni = 1
            ORDER BY v.poradi, v.id
        ");
        $stmt->execute($vyrobekIds);
        $vyrobky = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$vyrobky) {
            json_error('Žádné platné výrobky pro generování katalogu.', 400);
        }

        $html = render_seasonal_email($theme, $subject, $intro, $vyrobky);

        // Recipients
        $recipients = [];
        if ($action === 'send') {
            if ($recipientFilter === 'all') {
                $r = $pdo->query("SELECT id, nazev, email FROM odberatele WHERE blokovan = 0 AND email IS NOT NULL AND email <> ''")->fetchAll(PDO::FETCH_ASSOC);
                $recipients = $r;
            } elseif (str_starts_with($recipientFilter, 'typ:')) {
                $typ = substr($recipientFilter, 4);
                $stmt = $pdo->prepare("SELECT id, nazev, email FROM odberatele WHERE blokovan = 0 AND email IS NOT NULL AND email <> '' AND typ = :t");
                $stmt->execute(['t' => $typ]);
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif (str_starts_with($recipientFilter, 'ids:')) {
                $ids = array_filter(array_map('intval', explode(',', substr($recipientFilter, 4))));
                if ($ids) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("SELECT id, nazev, email FROM odberatele WHERE blokovan = 0 AND email IS NOT NULL AND email <> '' AND id IN ($ph)");
                    $stmt->execute($ids);
                    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            // Pokus o odeslání přes vendor SMTP (pokud config existuje) nebo fallback mail()
            $sent = 0; $failed = 0;
            $errors = [];
            $fromEmail = nastaveni()['firma_email'] ?? 'noreply@appek.cz';
            $fromName  = nastaveni()['firma_nazev'] ?? 'APPEK';
            foreach ($recipients as $r) {
                $headers = [
                    'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>',
                    'Content-Type: text/html; charset=UTF-8',
                    'MIME-Version: 1.0',
                    'X-Mailer: APPEK Seasonal Catalog',
                ];
                $ok = @mail(
                    $r['email'],
                    '=?UTF-8?B?' . base64_encode($subject) . '?=',
                    $html,
                    implode("\r\n", $headers)
                );
                if ($ok) $sent++;
                else {
                    $failed++;
                    $errors[] = $r['email'] . ' selhalo';
                }
                // Throttle — Hostinger může mít rate limit
                if (count($recipients) > 5) usleep(200000);  // 200ms mezi emaily
            }

            json_response([
                'ok' => true,
                'theme' => $themeKey,
                'sent' => $sent,
                'failed' => $failed,
                'total' => count($recipients),
                'errors' => $errors,
                'html_preview' => $html,
            ]);
        }

        // Preview
        json_response([
            'theme' => $theme,
            'subject' => $subject,
            'intro' => $intro,
            'vyrobky_count' => count($vyrobky),
            'html' => $html,
        ]);
    }

    // GET — list všech aktivních produktů + témata + odběratele
    $vyrobky = $pdo->query("
        SELECT v.id, v.cislo, v.nazev, v.cena_bez_dph, v.hmotnost_g, v.alergeny,
               v.je_novinka, v.je_akce, v.aktivni,
               k.nazev AS kategorie_nazev, k.ikona AS kategorie_ikona
        FROM vyrobky v
        LEFT JOIN kategorie_vyrobku k ON k.id = v.kategorie_id
        WHERE v.aktivni = 1
        ORDER BY k.poradi, v.poradi, v.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Type counts pro recipient filter
    $typeCounts = $pdo->query("
        SELECT typ, COUNT(*) AS pocet
        FROM odberatele
        WHERE blokovan = 0 AND email IS NOT NULL AND email <> ''
        GROUP BY typ
        ORDER BY pocet DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $totalRecipients = (int) $pdo->query("
        SELECT COUNT(*) FROM odberatele WHERE blokovan = 0 AND email IS NOT NULL AND email <> ''
    ")->fetchColumn();

    json_response([
        'themes'     => SEASONAL_THEMES,
        'vyrobky'    => $vyrobky,
        'type_counts'=> $typeCounts,
        'total_recipients' => $totalRecipients,
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Vyrenderuje HTML email pro daný theme + výrobky.
 */
function render_seasonal_email(array $theme, string $subject, string $intro, array $vyrobky): string {
    $c1 = htmlspecialchars($theme['color_1']);
    $c2 = htmlspecialchars($theme['color_2']);
    $bg = htmlspecialchars($theme['bg']);
    $accent = htmlspecialchars($theme['accent']);
    $emoji = htmlspecialchars($theme['emoji_dec']);
    $sub = htmlspecialchars($subject);
    $intro_html = nl2br(htmlspecialchars($intro));

    // Firma info pro patičku
    $firmaNazev = htmlspecialchars(nastaveni()['firma_nazev'] ?? 'APPEK');
    $firmaEmail = htmlspecialchars(nastaveni()['firma_email'] ?? 'info@appek.cz');
    $firmaTel   = htmlspecialchars(nastaveni()['firma_telefon'] ?? '');
    $firmaWeb   = htmlspecialchars(nastaveni()['firma_web'] ?? 'https://appek.cz');

    $itemsHtml = '';
    foreach ($vyrobky as $v) {
        $nazev = htmlspecialchars($v['nazev']);
        $cislo = htmlspecialchars($v['cislo'] ?? '');
        $popis = htmlspecialchars($v['popis'] ?? '');
        $cena = number_format((float)$v['cena_bez_dph'], 2, ',', ' ');
        $jedn = htmlspecialchars($v['jednotka_kod'] ?? 'ks');
        $hmot = $v['hmotnost_g'] ? number_format($v['hmotnost_g'], 0, ',', ' ') . ' g · ' : '';
        $kat  = htmlspecialchars(($v['kategorie_ikona'] ?? '') . ' ' . ($v['kategorie_nazev'] ?? ''));
        $alergeny = htmlspecialchars($v['alergeny'] ?? '');

        $itemsHtml .= <<<HTML
<tr>
  <td style="padding:14px 20px;border-bottom:1px solid #f0f0f3;vertical-align:top">
    <div style="font-size:16px;font-weight:700;color:#1d1d1f;margin-bottom:4px">{$nazev}</div>
    <div style="font-size:11px;color:#86868b;margin-bottom:6px">{$cislo} · {$kat}</div>
    <div style="font-size:13px;color:#424245;line-height:1.5;margin-bottom:6px">{$popis}</div>
    <div style="font-size:11px;color:#86868b">{$hmot}Alergeny: {$alergeny}</div>
  </td>
  <td style="padding:14px 20px;text-align:right;vertical-align:top;white-space:nowrap">
    <div style="font-size:20px;font-weight:800;color:{$c1}">{$cena} Kč</div>
    <div style="font-size:11px;color:#86868b">/ {$jedn} bez DPH</div>
  </td>
</tr>
HTML;
    }

    $rok = date('Y');
    $datum = date('d.m.Y');
    $isDark = $bg === '#1a1a1a';
    $textColor = $isDark ? '#fff' : '#1d1d1f';
    $textMuted = $isDark ? '#aaa' : '#6e6e73';

    return <<<HTML
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$sub}</title>
</head>
<body style="margin:0;padding:0;font-family:-apple-system,Segoe UI,Roboto,sans-serif;background:{$bg};color:{$textColor}">

<div style="max-width:680px;margin:0 auto;padding:24px 16px">

  <!-- HEADER s gradientem -->
  <div style="background:linear-gradient(135deg,{$c1} 0%,{$c2} 100%);border-radius:14px;padding:36px 24px;text-align:center;color:#fff;margin-bottom:24px">
    <div style="font-size:40px;margin-bottom:8px;letter-spacing:6px">{$emoji}</div>
    <h1 style="margin:0;font-size:26px;font-weight:800;line-height:1.2">{$sub}</h1>
    <div style="font-size:12px;opacity:0.85;margin-top:8px">{$datum} · {$firmaNazev}</div>
  </div>

  <!-- INTRO -->
  <div style="background:#fff;border-radius:14px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.04);color:#1d1d1f;font-size:15px;line-height:1.65">
    {$intro_html}
  </div>

  <!-- PRODUCTS -->
  <div style="background:#fff;border-radius:14px;overflow:hidden;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.04)">
    <div style="background:{$accent};padding:14px 20px;color:#1d1d1f;font-weight:800;font-size:13px;letter-spacing:0.5px;text-transform:uppercase">
      🛒 Vybraný sortiment ({$emoji})
    </div>
    <table style="width:100%;border-collapse:collapse">
      {$itemsHtml}
    </table>
  </div>

  <!-- CTA -->
  <div style="text-align:center;margin-bottom:24px">
    <a href="{$firmaWeb}/b2b/" style="display:inline-block;background:{$c1};color:#fff;padding:14px 32px;border-radius:10px;font-weight:700;font-size:15px;text-decoration:none;box-shadow:0 4px 12px rgba(0,0,0,0.15)">
      📋 Objednat v B2B portálu
    </a>
    <div style="font-size:11px;color:{$textMuted};margin-top:10px">
      Nebo odpovědí na tento e-mail
    </div>
  </div>

  <!-- FOOTER -->
  <div style="text-align:center;padding:24px 16px;border-top:1px solid #e5e5e7;font-size:12px;color:{$textMuted};line-height:1.6">
    <strong style="color:{$textColor}">{$firmaNazev}</strong><br>
    📧 {$firmaEmail} · 📞 {$firmaTel}<br>
    🌐 <a href="{$firmaWeb}" style="color:{$c1};text-decoration:none">{$firmaWeb}</a><br>
    <small style="opacity:0.7;display:block;margin-top:10px">© {$rok} {$firmaNazev} · Sezónní katalog · automaticky vygenerováno APPEK</small>
  </div>

</div>
</body>
</html>
HTML;
}
