<?php
/**
 * Knihovna 8 HTML designů e-mailů pro pekárenskou aplikaci.
 * Každý design je email-safe (inline styly, tabulky pro layout, žádné externí CSS).
 * Funguje v Gmail, Outlook, Apple Mail, Seznam, atd.
 *
 * Použití: email_html_design('hero', $vars)  →  vrátí HTML string.
 */

function email_html_designs_list(): array {
    return [
        'modern'    => '🎨 Modern (clean, světlé)',
        'hero'      => '⭐ Hero (velký nadpis, výrazné CTA)',
        'card'      => '📋 Card (kompaktní karta)',
        'vintage'   => '📜 Vintage (warm, retro)',
        'premium'   => '✨ Premium (tmavé pozadí, gold)',
        'eco'       => '🌿 Eco (zelená, BIO feel)',
        'minimalist'=> '⚪ Minimalist (jen text + cena)',
        'colorful'  => '🌈 Colorful (barevné akcenty)',
    ];
}

/**
 * Vygeneruje HTML šablonu daného stylu se zachováním placeholders.
 * Placeholders ({cislo}, {datum}, {firma}...) zůstávají — budou nahrazeny později v email_template_render().
 */
function email_html_design(string $style, string $klic = 'objednavka_expedovana'): string {
    // Pre-rendered titles podle klíče šablony
    $titles = [
        'objednavka_nova'        => ['✓ Objednávka přijata', '#22863a'],
        'objednavka_potvrzena'   => ['✓ Objednávka potvrzena', '#22863a'],
        'objednavka_ve_vyrobe'   => ['🔥 Objednávka ve výrobě', '#BA7517'],
        'objednavka_pripravena'  => ['📦 Připravena k expedici', '#3B5BAB'],
        'objednavka_expedovana'  => ['🚚 Objednávka na cestě!', '#3B5BAB'],
        'objednavka_dorucena'    => ['✅ Doručeno', '#22863a'],
        'objednavka_zrusena'     => ['❌ Objednávka zrušena', '#dc2626'],
        'admin_nova_objednavka'  => ['🔔 Nová objednávka', '#BA7517'],
    ];
    $title = $titles[$klic][0] ?? '📬 APPEK B2B';
    $accentColor = $titles[$klic][1] ?? '#BA7517';

    $designs = [
        'modern' => _html_modern($title, $accentColor),
        'hero'   => _html_hero($title, $accentColor),
        'card'   => _html_card($title, $accentColor),
        'vintage'=> _html_vintage($title, $accentColor),
        'premium'=> _html_premium($title, $accentColor),
        'eco'    => _html_eco($title, $accentColor),
        'minimalist' => _html_minimalist($title, $accentColor),
        'colorful' => _html_colorful($title, $accentColor),
    ];
    return $designs[$style] ?? $designs['modern'];
}

// ============== JEDNOTLIVÉ DESIGNY ==============

function _html_modern(string $title, string $color): string {
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f7f8fa;padding:20px 0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.06);max-width:600px">
      <!-- Header -->
      <tr><td style="background:{$color};padding:30px 30px 26px;text-align:center">
        <div style="color:#fff;font-size:24px;font-weight:700;letter-spacing:-0.3px">{$title}</div>
        <div style="color:rgba(255,255,255,0.85);font-size:13px;margin-top:6px">Objednávka #{cislo}</div>
      </td></tr>
      <!-- Body -->
      <tr><td style="padding:30px">
        <p style="font-size:15px;line-height:1.6;color:#1a1a1a;margin:0 0 18px">Vážení,</p>
        <p style="font-size:14px;line-height:1.6;color:#444;margin:0 0 20px">objednávka <strong>#{cislo}</strong> má aktuální stav: <strong style="color:{$color}">{stav}</strong>.</p>

        <!-- Info box -->
        <table width="100%" cellpadding="14" cellspacing="0" border="0" style="background:#f7f8fa;border-radius:8px;margin-bottom:20px">
          <tr><td style="font-size:13px;color:#555">
            <strong style="color:#1a1a1a">📅 Datum dodání:</strong> {datum}<br>
            <strong style="color:#1a1a1a">📍 Místo:</strong> {misto}<br>
            <strong style="color:#1a1a1a">💰 Celkem s DPH:</strong> {castka_celkem}
          </td></tr>
        </table>

        <p style="font-size:13px;line-height:1.6;color:#666;margin:0 0 6px;font-weight:600">📦 Položky:</p>
        <pre style="background:#fafaf9;padding:14px;border-radius:8px;font-size:12px;color:#333;border:1px solid #eee;white-space:pre-wrap;font-family:'SF Mono',Menlo,Consolas,monospace;margin:0">{polozky_text}</pre>

        <p style="font-size:14px;line-height:1.6;color:#444;margin:20px 0 0">Děkujeme za Vaši důvěru.</p>
        <p style="font-size:14px;color:#1a1a1a;margin:6px 0 0"><strong>{firma}</strong></p>
      </td></tr>
      <!-- Footer -->
      <tr><td style="background:#f7f8fa;padding:18px 30px;text-align:center;font-size:11px;color:#888;border-top:1px solid #eee">
        Tento e-mail je automaticky vygenerovaný systémem {firma}.
      </td></tr>
    </table>
  </td></tr>
</table>
HTML;
}

function _html_hero(string $title, string $color): string {
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fff;padding:30px 0;font-family:Georgia,'Times New Roman',serif">
  <tr><td align="center">
    <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px">
      <tr><td style="text-align:center;padding:50px 30px;background:linear-gradient(135deg,{$color},#1a1a1a);border-radius:16px">
        <div style="color:#fff;font-size:38px;font-weight:900;line-height:1.2;letter-spacing:-0.5px;margin-bottom:14px">{$title}</div>
        <div style="color:rgba(255,255,255,0.9);font-size:18px;font-weight:300;margin-bottom:28px">Objednávka <strong>#{cislo}</strong></div>
        <div style="display:inline-block;background:rgba(255,255,255,0.18);padding:14px 24px;border-radius:30px;color:#fff;font-size:14px">
          📅 {datum} · 📍 {misto}
        </div>
      </td></tr>
      <tr><td style="padding:30px 30px 10px;font-family:-apple-system,Arial,sans-serif">
        <p style="font-size:16px;color:#1a1a1a;line-height:1.6;margin:0 0 16px">Vážení zákazníci,</p>
        <p style="font-size:14px;color:#444;line-height:1.7">stav vaší objednávky byl aktualizován. Vše potřebné najdete níže.</p>
        <hr style="border:none;border-top:1px solid #eee;margin:24px 0">
        <p style="font-size:13px;font-weight:700;color:#1a1a1a;margin-bottom:8px">Položky objednávky</p>
        <pre style="background:#fafaf9;padding:16px;border-radius:8px;font-size:12px;color:#333;font-family:Menlo,Consolas,monospace;white-space:pre-wrap;margin:0">{polozky_text}</pre>
        <div style="text-align:right;font-size:18px;color:{$color};font-weight:700;margin-top:16px">{castka_celkem}</div>
      </td></tr>
      <tr><td style="padding:30px;text-align:center;font-size:14px;color:#888;font-family:Georgia,serif">
        S pozdravem,<br><strong style="color:#1a1a1a;font-size:16px">{firma}</strong>
      </td></tr>
    </table>
  </td></tr>
</table>
HTML;
}

function _html_card(string $title, string $color): string {
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#FAFAF7;padding:20px;font-family:-apple-system,'Segoe UI',Arial,sans-serif">
  <tr><td align="center">
    <table width="480" cellpadding="0" cellspacing="0" border="0" style="background:#fff;border-radius:8px;border:1px solid #e5e5e5;max-width:480px">
      <tr><td style="padding:24px 24px 0">
        <div style="display:inline-block;background:{$color};color:#fff;padding:4px 12px;border-radius:6px;font-size:11px;font-weight:700;letter-spacing:0.5px;text-transform:uppercase">{$title}</div>
      </td></tr>
      <tr><td style="padding:14px 24px 4px">
        <div style="font-size:20px;font-weight:700;color:#1a1a1a">Objednávka #{cislo}</div>
        <div style="font-size:12px;color:#888;margin-top:2px">📅 {datum} · 📍 {misto}</div>
      </td></tr>
      <tr><td style="padding:18px 24px">
        <p style="margin:0 0 12px;font-size:14px;color:#333;line-height:1.5">Vážení, stav Vaší objednávky byl aktualizován.</p>
        <div style="background:#FAFAF7;border-left:3px solid {$color};padding:12px 14px;font-size:12px;color:#444;line-height:1.6;border-radius:4px">
          <strong>Stav:</strong> {stav}<br>
          <strong>Celkem:</strong> {castka_celkem}
        </div>
        <p style="margin:18px 0 6px;font-size:12px;font-weight:700;color:#666">Položky:</p>
        <pre style="background:#fafaf9;padding:12px;font-size:11px;color:#333;font-family:Menlo,monospace;white-space:pre-wrap;margin:0;border-radius:4px">{polozky_text}</pre>
      </td></tr>
      <tr><td style="padding:18px 24px;border-top:1px solid #eee;font-size:12px;color:#888;text-align:center">
        — <strong>{firma}</strong>
      </td></tr>
    </table>
  </td></tr>
</table>
HTML;
}

function _html_vintage(string $title, string $color): string {
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F4ECDB;padding:30px 0;font-family:Georgia,'Times New Roman',serif">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" border="0" style="background:#FFF8E7;border:2px solid #BA7517;border-radius:4px;max-width:560px;box-shadow:6px 6px 0 #C5A876">
      <tr><td style="border-bottom:2px dashed #BA7517;padding:24px;text-align:center">
        <div style="font-size:11px;color:#854F0B;letter-spacing:3px;text-transform:uppercase;margin-bottom:8px">— ✿ APPEK B2B ✿ —</div>
        <div style="font-size:28px;color:#854F0B;font-style:italic;font-weight:700">{$title}</div>
        <div style="font-size:14px;color:#A87E33;margin-top:6px">Objednávka #{cislo}</div>
      </td></tr>
      <tr><td style="padding:28px 30px">
        <p style="font-size:15px;color:#5C3608;line-height:1.7;font-style:italic;margin:0 0 18px">Vážení a milí zákazníci,</p>
        <p style="font-size:14px;color:#5C3608;line-height:1.7;margin:0">
          dovolujeme si Vám oznámit, že stav Vaší objednávky byl právě aktualizován.
          Datum dodání: <strong>{datum}</strong>, místo: <em>{misto}</em>.
        </p>
        <table width="100%" cellpadding="12" cellspacing="0" border="0" style="margin-top:18px;background:#FFFFFF;border:1px solid #E8C988;border-radius:4px">
          <tr><td style="font-size:12px;color:#5C3608;font-family:Georgia,serif">
            <strong>Stav:</strong> {stav}<br>
            <strong>Celková částka:</strong> <span style="font-size:16px;color:#854F0B">{castka_celkem}</span>
          </td></tr>
        </table>
        <p style="font-size:12px;color:#A87E33;line-height:1.7;margin:18px 0 6px;font-style:italic">Položky:</p>
        <pre style="background:#FFFFFF;border:1px solid #E8C988;padding:12px;font-size:11px;color:#5C3608;font-family:Georgia,serif;white-space:pre-wrap;margin:0">{polozky_text}</pre>
      </td></tr>
      <tr><td style="border-top:2px dashed #BA7517;padding:18px 30px;text-align:center;font-style:italic;color:#854F0B;font-size:14px">
        S úctou,<br><strong>{firma}</strong>
      </td></tr>
    </table>
  </td></tr>
</table>
HTML;
}

function _html_premium(string $title, string $color): string {
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#0a0a0a;padding:30px 0;font-family:-apple-system,'Segoe UI',Arial,sans-serif">
  <tr><td align="center">
    <table width="580" cellpadding="0" cellspacing="0" border="0" style="background:#1a1a1a;border-radius:12px;max-width:580px;box-shadow:0 12px 40px rgba(0,0,0,0.6)">
      <tr><td style="padding:50px 40px 30px;text-align:center;border-bottom:1px solid #333">
        <div style="font-size:11px;color:#D89940;letter-spacing:4px;text-transform:uppercase;margin-bottom:14px">— Premium service —</div>
        <div style="color:#fff;font-size:32px;font-weight:300;letter-spacing:-0.5px;margin-bottom:8px">{$title}</div>
        <div style="color:#D89940;font-size:18px;font-weight:700">Objednávka #{cislo}</div>
      </td></tr>
      <tr><td style="padding:36px 40px">
        <p style="color:#e8e8e8;font-size:15px;line-height:1.8;margin:0 0 22px">Vážení zákazníci,</p>
        <p style="color:#c8c8c8;font-size:14px;line-height:1.8;margin:0 0 26px">stav Vaší objednávky byl aktualizován. Více informací níže.</p>
        <table width="100%" cellpadding="16" cellspacing="0" border="0" style="background:#222;border-radius:8px;border-left:3px solid #D89940">
          <tr><td style="color:#e8e8e8;font-size:13px;line-height:1.8">
            <div style="margin-bottom:6px"><span style="color:#888">Datum dodání</span><br><strong style="font-size:15px;color:#fff">{datum}</strong></div>
            <div style="margin-bottom:6px;padding-top:10px;border-top:1px dashed #444"><span style="color:#888">Stav</span><br><strong style="font-size:15px;color:#D89940">{stav}</strong></div>
            <div style="padding-top:10px;border-top:1px dashed #444"><span style="color:#888">Celkem s DPH</span><br><strong style="font-size:22px;color:#D89940">{castka_celkem}</strong></div>
          </td></tr>
        </table>
      </td></tr>
      <tr><td style="padding:24px 40px 36px;border-top:1px solid #333;text-align:center">
        <div style="color:#888;font-size:12px;margin-bottom:6px">S pozdravem</div>
        <strong style="color:#D89940;font-size:16px;letter-spacing:0.5px">{firma}</strong>
      </td></tr>
    </table>
  </td></tr>
</table>
HTML;
}

function _html_eco(string $title, string $color): string {
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F0F7E6;padding:30px 0;font-family:-apple-system,'Segoe UI',Arial,sans-serif">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" border="0" style="background:#fff;border-radius:14px;max-width:560px;overflow:hidden;border:2px solid #15803d">
      <tr><td style="background:linear-gradient(120deg,#15803d,#22863a);padding:32px 30px;text-align:center">
        <div style="font-size:36px;margin-bottom:8px">🌿</div>
        <div style="color:#fff;font-size:24px;font-weight:700;letter-spacing:-0.3px">{$title}</div>
        <div style="color:rgba(255,255,255,0.9);font-size:14px;margin-top:6px">Čerstvé pečivo z naší pekárny</div>
      </td></tr>
      <tr><td style="padding:30px">
        <div style="background:#F0F7E6;padding:14px 16px;border-radius:8px;font-size:13px;color:#15803d;margin-bottom:20px;text-align:center">
          🥖 Objednávka #{cislo} · 📅 {datum}
        </div>
        <p style="font-size:14px;color:#333;line-height:1.7;margin:0 0 16px">Dobrý den,</p>
        <p style="font-size:14px;color:#555;line-height:1.7;margin:0">stav Vaší objednávky byl aktualizován na <strong style="color:#15803d">{stav}</strong>.</p>
        <table width="100%" cellpadding="12" cellspacing="0" border="0" style="margin-top:20px;background:#F0F7E6;border-radius:8px">
          <tr><td style="font-size:13px;color:#15803d">
            <strong>📍 Místo dodání:</strong> {misto}<br>
            <strong>💰 Celkem:</strong> <span style="font-size:18px">{castka_celkem}</span>
          </td></tr>
        </table>
        <p style="font-size:12px;color:#666;margin:20px 0 6px;font-weight:600">📦 Objednané položky:</p>
        <pre style="background:#fafaf9;padding:12px;font-size:11px;color:#333;font-family:Menlo,monospace;white-space:pre-wrap;margin:0;border-radius:6px;border:1px solid #e5e5e5">{polozky_text}</pre>
      </td></tr>
      <tr><td style="padding:18px 30px;background:#F0F7E6;text-align:center;font-size:12px;color:#15803d;font-weight:600">
        🌱 <strong>{firma}</strong> · S láskou pečeno
      </td></tr>
    </table>
  </td></tr>
</table>
HTML;
}

function _html_minimalist(string $title, string $color): string {
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fff;padding:60px 0;font-family:'Helvetica Neue',Helvetica,Arial,sans-serif">
  <tr><td align="center">
    <table width="440" cellpadding="0" cellspacing="0" border="0" style="max-width:440px">
      <tr><td style="padding:0 30px">
        <div style="color:#1a1a1a;font-size:30px;font-weight:300;letter-spacing:-0.8px;margin-bottom:6px">{$title}</div>
        <div style="color:#999;font-size:13px;letter-spacing:0.5px;margin-bottom:40px">Objednávka #{cislo}</div>
        <hr style="border:none;border-top:1px solid #e5e5e5;margin:0 0 24px">
        <p style="font-size:14px;line-height:1.8;color:#444;margin:0">Vážení,</p>
        <p style="font-size:14px;line-height:1.8;color:#444;margin:14px 0 0">
          dodání: <strong>{datum}</strong><br>
          místo: {misto}<br>
          stav: <strong style="color:{$color}">{stav}</strong>
        </p>
        <p style="font-size:26px;line-height:1;color:{$color};margin:30px 0 0;font-weight:700">{castka_celkem}</p>
        <hr style="border:none;border-top:1px solid #e5e5e5;margin:36px 0 24px">
        <pre style="font-size:11px;color:#666;font-family:'Courier New',monospace;white-space:pre-wrap;margin:0;line-height:1.8">{polozky_text}</pre>
        <hr style="border:none;border-top:1px solid #e5e5e5;margin:36px 0 24px">
        <div style="color:#999;font-size:12px;text-align:center">{firma}</div>
      </td></tr>
    </table>
  </td></tr>
</table>
HTML;
}

function _html_colorful(string $title, string $color): string {
    return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:linear-gradient(135deg,#FFE5D9,#F0F0FF);padding:30px 0;font-family:-apple-system,'Segoe UI',Arial,sans-serif">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" border="0" style="background:#fff;border-radius:20px;max-width:560px;box-shadow:0 8px 32px rgba(167,89,255,0.12);overflow:hidden">
      <tr><td style="background:linear-gradient(90deg,#FF6B6B,#FFB04C,{$color});height:8px"></td></tr>
      <tr><td style="padding:36px 30px 20px;text-align:center">
        <div style="font-size:42px;margin-bottom:10px">🎉</div>
        <div style="font-size:26px;font-weight:800;color:#1a1a1a;letter-spacing:-0.3px">{$title}</div>
        <div style="display:inline-block;background:linear-gradient(90deg,#FFE5D9,#F0F0FF);padding:6px 16px;border-radius:20px;font-size:13px;color:#666;margin-top:14px">
          Obj. <strong>#{cislo}</strong>
        </div>
      </td></tr>
      <tr><td style="padding:0 30px 30px">
        <p style="font-size:14px;color:#333;line-height:1.7;margin:0 0 18px;text-align:center">Stav vaší objednávky byl právě aktualizován! 🎊</p>
        <table width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="50%" valign="top" style="padding:10px;background:#FFE5D9;border-radius:12px;text-align:center">
              <div style="font-size:10px;color:#FF6B6B;letter-spacing:1px;text-transform:uppercase;font-weight:700">📅 Datum</div>
              <div style="font-size:14px;color:#1a1a1a;font-weight:700;margin-top:4px">{datum}</div>
            </td>
            <td width="10"></td>
            <td width="50%" valign="top" style="padding:10px;background:#F0F0FF;border-radius:12px;text-align:center">
              <div style="font-size:10px;color:#5B47E8;letter-spacing:1px;text-transform:uppercase;font-weight:700">💰 Celkem</div>
              <div style="font-size:14px;color:#1a1a1a;font-weight:700;margin-top:4px">{castka_celkem}</div>
            </td>
          </tr>
        </table>
        <pre style="background:#fafaf9;padding:14px;font-size:11px;color:#444;font-family:Menlo,monospace;white-space:pre-wrap;margin:20px 0 0;border-radius:10px">{polozky_text}</pre>
      </td></tr>
      <tr><td style="background:linear-gradient(90deg,#FFE5D9,#F0F0FF);padding:18px 30px;text-align:center;font-size:13px;color:#555">
        Děkujeme za Vaši objednávku! <strong>{firma}</strong> 💛
      </td></tr>
    </table>
  </td></tr>
</table>
HTML;
}
