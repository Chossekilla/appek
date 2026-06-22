// =============================================================
// 🎓 ONBOARDING HELPERS — pomocné funkce pro nové kroky
// =============================================================

function _onboardLocalInstallGuide(os) {
  // OS-switcher pills — uživatel může přepnout, i když detekce by byla špatně
  const tabs = [
    { id: 'mac',     label: '🍎 macOS' },
    { id: 'windows', label: '🪟 Windows' },
    { id: 'linux',   label: '🐧 Linux' },
  ];
  const switcher = `
    <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">
      <span style="font-size:12px;color:var(--text-3);padding:6px 4px 6px 0;font-weight:600">Pro který systém:</span>
      ${tabs.map(t => `
        <button onclick="window._onboardSetLocalOs('${t.id}')" style="padding:6px 12px;border:2px solid ${os===t.id?'#BA7517':'var(--border)'};background:${os===t.id?'#BA7517':'var(--surface)'};color:${os===t.id?'#fff':'var(--text-2)'};border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit">${t.label}</button>
      `).join('')}
    </div>`;

  let body = '';
  if (os === 'mac') {
    body = `
      <h4 style="margin:0 0 8px;font-size:15px;color:#854F0B">🍎 Lokální instalace na macOS</h4>
      <p style="font-size:12.5px;color:var(--text-3);margin:0 0 12px">Doporučujeme zdarma <strong>MAMP</strong> — funguje na všech Macách včetně M1/M2/M3.</p>
      <ol style="margin:0;padding-left:22px;font-size:13.5px;line-height:1.8;color:var(--text-2)">
        <li>📥 Stáhni si <a href="https://www.mamp.info/en/downloads/" target="_blank" style="color:#854F0B;font-weight:600">MAMP zdarma</a> (cca 200 MB)</li>
        <li>🚀 Otevři <code>MAMP.app</code> → klikni <strong>Start Servers</strong></li>
        <li>📁 Rozbal Appek do <code>~/MAMP/htdocs/appek/</code></li>
        <li>🌐 Otevři v prohlížeči: <code>http://localhost:8888/appek/install.php</code></li>
        <li>✅ Spusť instalační wizard (DB host: <code>localhost</code>, user: <code>root</code>, heslo: <code>root</code>, DB port: <code>8889</code>)</li>
      </ol>
      <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="https://www.mamp.info/en/downloads/" target="_blank" class="btn-primary" style="font-size:13px;padding:8px 14px">📥 Stáhnout MAMP</a>
        <a href="../instalace.html" target="_blank" class="btn-secondary" style="font-size:13px;padding:8px 14px">📄 PDF návod</a>
      </div>`;
  } else if (os === 'windows') {
    body = `
      <h4 style="margin:0 0 8px;font-size:15px;color:#854F0B">🪟 Lokální instalace na Windows</h4>
      <p style="font-size:12.5px;color:var(--text-3);margin:0 0 12px">Doporučujeme zdarma <strong>XAMPP</strong> — funguje na Win 10/11.</p>
      <ol style="margin:0;padding-left:22px;font-size:13.5px;line-height:1.8;color:var(--text-2)">
        <li>📥 Stáhni si <a href="https://www.apachefriends.org/download.html" target="_blank" style="color:#854F0B;font-weight:600">XAMPP zdarma</a> (cca 150 MB)</li>
        <li>🚀 Nainstaluj → otevři <strong>XAMPP Control Panel</strong></li>
        <li>▶️ Spusť <strong>Apache</strong> + <strong>MySQL</strong> (zelená kontrolka)</li>
        <li>📁 Rozbal Appek do <code>C:\\xampp\\htdocs\\appek\\</code></li>
        <li>🌐 Otevři v prohlížeči: <code>http://localhost/appek/install.php</code></li>
        <li>✅ Spusť wizard (DB host: <code>localhost</code>, user: <code>root</code>, heslo: <em>prázdné</em>)</li>
      </ol>
      <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="https://www.apachefriends.org/download.html" target="_blank" class="btn-primary" style="font-size:13px;padding:8px 14px">📥 Stáhnout XAMPP</a>
        <a href="../instalace.html" target="_blank" class="btn-secondary" style="font-size:13px;padding:8px 14px">📄 PDF návod</a>
      </div>`;
  } else if (os === 'linux') {
    body = `
      <h4 style="margin:0 0 8px;font-size:15px;color:#854F0B">🐧 Lokální instalace na Linuxu</h4>
      <p style="font-size:12.5px;color:var(--text-3);margin:0 0 12px">Doporučuje se LAMP stack přes balíčkový manažer (Apache + PHP + MySQL):</p>
      <pre style="background:#1d1d1f;color:#fff;padding:12px;border-radius:6px;font-size:12px;overflow-x:auto;line-height:1.5;margin:0"># Ubuntu / Debian
sudo apt install apache2 php8.2 mysql-server php8.2-mysql php8.2-gd php8.2-curl php8.2-zip

# Fedora / CentOS / RHEL
sudo dnf install httpd php php-mysqlnd mariadb-server php-gd php-curl

# Nakopíruj Appek
sudo cp -r appek /var/www/html/
sudo chown -R www-data:www-data /var/www/html/appek

# Otevři
xdg-open http://localhost/appek/install.php</pre>
      <p style="font-size:12px;color:var(--text-3);margin:10px 0 0">💡 Pro pokročilé: Docker / Nginx → ${'<a href="../instalace.html" target="_blank" style="color:#854F0B;font-weight:600">PDF návod</a>'}</p>`;
  }

  return `
    <div style="background:#FFF8E5;border:1px solid #FBBF24;border-radius:12px;padding:18px;margin-bottom:16px">
      ${switcher}
      ${body}
    </div>`;
}

function _onboardCloudInstallGuide() {
  return `
    <div style="background:#E8F5E8;border:1px solid #16a34a;border-radius:12px;padding:18px;margin-bottom:16px">
      <h4 style="margin:0 0 10px;font-size:15px;color:#15803d">🌐 Webová instalace — krok za krokem</h4>
      <p style="font-size:13px;color:var(--text-2);margin:0 0 12px;line-height:1.55">
        Nahraj Appek na <strong>jakýkoliv webhosting</strong> s PHP 8.0+ a MySQL — žádné zvláštní nároky.
      </p>

      <div style="background:#fff;border-radius:8px;padding:12px 14px;margin-bottom:12px">
        <div style="font-size:12px;color:var(--text-3);font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px">Ověřené české/SK webhostingy:</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          ${[
            {name:'Wedos',     price:'od 30 Kč/měs',  url:'https://www.wedos.cz/web-hosting/'},
            {name:'Hostinger', price:'od 35 Kč/měs',  url:'https://www.hostinger.cz/'},
            {name:'Forpsi',    price:'od 49 Kč/měs',  url:'https://www.forpsi.com/webhosting/'},
            {name:'Active24',  price:'od 89 Kč/měs',  url:'https://www.active24.cz/webhosting'},
            {name:'WebSupport',price:'od 3 €/měs',    url:'https://www.websupport.sk/hosting/'},
            {name:'Endora',    price:'zdarma možnost',url:'https://www.endora.cz/'},
          ].map(h => `<a href="${h.url}" target="_blank" style="background:var(--surface-2);border:1px solid var(--border);padding:5px 10px;border-radius:6px;font-size:12px;color:var(--text-2);text-decoration:none;font-weight:600">${h.name} <span style="color:var(--text-3);font-weight:400">· ${h.price}</span></a>`).join('')}
        </div>
      </div>

      <ol style="margin:0;padding-left:22px;font-size:13.5px;line-height:1.8;color:var(--text-2)">
        <li>📦 Objednej si webhosting (PHP 8.0+, MySQL 5.7+ / MariaDB) — stačí ten nejlevnější tarif</li>
        <li>🌐 Nasměruj svou doménu na hosting (mojefirma.cz, mojeb2b.cz, mujpodnik.cz...)</li>
        <li>📤 Nahraj Appek přes FTP do <code>public_html/</code> (FileZilla / Cyberduck zdarma)</li>
        <li>🗄️ Vytvoř MySQL databázi v admin panelu hostingu (poznamenej si název / user / heslo)</li>
        <li>🌐 Otevři <code>https://tvojedomena.cz/install.php</code> a vyplň wizard</li>
        <li>✅ Hotovo — instalace běží online, automaticky se aktualizuje</li>
      </ol>

      <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="../instalace.html" target="_blank" class="btn-primary" style="font-size:13px;padding:8px 14px">📄 PDF návod</a>
        <a href="mailto:podpora@appek.cz?subject=Pomoc%20s%20webovou%20instalací" class="btn-secondary" style="font-size:13px;padding:8px 14px">✉️ Pomoc s instalací</a>
      </div>

      <div style="margin-top:12px;padding:10px 12px;background:#fff;border-radius:8px;font-size:12px;color:var(--text-3);line-height:1.5">
        💼 <strong>Nemáš čas / chuť?</strong> Za poplatek ti instalaci uděláme na klíč včetně nastavení domény, SSL a první pomoci. Napiš na podpora@appek.cz.
      </div>
    </div>`;
}

function _onboardHybridInstallGuide() {
  return `
    <div style="background:#F3E8FF;border:1px solid #9333ea;border-radius:12px;padding:18px;margin-bottom:16px">
      <h4 style="margin:0 0 10px;font-size:15px;color:#6b21a8">🔄 Hybridní instalace — local + web sync</h4>
      <p style="font-size:13px;color:var(--text-2);margin:0 0 12px;line-height:1.55">
        Hlavní instance běží <strong>lokálně</strong> (rychlost, offline, data u tebe), současně synchronizuje s <strong>webovou kopií</strong> (mobil, přístup z domova, zálohy).
      </p>

      <div style="background:#fff;border-radius:8px;padding:12px 14px;margin-bottom:12px">
        <div style="font-size:12px;color:var(--text-3);font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px">⚠️ Co potřebuješ:</div>
        <ul style="margin:0;padding-left:20px;font-size:13px;line-height:1.7;color:var(--text-2)">
          <li>Počítač v provozovně (Mac / Windows / Linux) s XAMPP / MAMP</li>
          <li>Webhosting s PHP 8+ a MySQL</li>
          <li>Doménu pro mobilní/vzdálený přístup</li>
          <li>Trvalé připojení k internetu na obou stranách</li>
        </ul>
      </div>

      <ol style="margin:0;padding-left:22px;font-size:13.5px;line-height:1.8;color:var(--text-2)">
        <li>🏠 <strong>Nejdříve si nainstaluj lokální</strong> verzi (přepni nahoře na Lokální)</li>
        <li>🌐 <strong>Pak si nahraj webovou kopii</strong> na hosting (postup viz Webová instalace)</li>
        <li>🔗 V Nastavení → Synchronizace zadej URL webové kopie + API klíč</li>
        <li>⏰ Nastav frekvenci sync (každých 5 min / hodina / ručně)</li>
        <li>📱 Mobilní/vzdálený přístup pak používá webovou kopii</li>
      </ol>

      <div style="margin-top:12px;padding:10px 12px;background:#FEF3C7;border:1px solid #F59E0B;border-radius:8px;font-size:12px;color:#92400E;line-height:1.5">
        💡 <strong>Tip pro začátečníky:</strong> Začni jen s Webovou instalací — funguje z mobilu i PC. Hybridní zapneš později pokud zjistíš, že potřebuješ. Většina lidí ji nikdy nepotřebuje.
      </div>

      <div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="../instalace.html" target="_blank" class="btn-primary" style="font-size:13px;padding:8px 14px">📄 PDF návod</a>
        <a href="mailto:podpora@appek.cz?subject=Hybridní%20instalace" class="btn-secondary" style="font-size:13px;padding:8px 14px">✉️ Konzultace zdarma</a>
      </div>
    </div>`;
}

function _onboardPackagesStep() {
  const data = state._onboard.data;
  if (!data.balicky) data.balicky = [];
  const balicky = [
    { id: 'cukrarna',   ikona: '🧁', label: 'Cukrárna',          popis: 'Konfigurátor dortů, kapacita pečení, galerie', cena: '+5 000 Kč' },
    { id: 'lahudky',    ikona: '🥗', label: 'Lahůdkárna',        popis: 'Catering kalkulátor, šarže HACCP, mix-and-match', cena: '+3 000 Kč' },
    { id: 'restaurace', ikona: '🍕', label: 'Restaurace',        popis: 'Stoly, kapacita kuchyně, doba přípravy, rozvoz', cena: '+4 000 Kč' },
    { id: 'catering',   ikona: '🎉', label: 'Velký catering',    popis: 'Firemní objednávky, cenové úrovně, smlouvy', cena: '+2 500 Kč' },
    { id: 'sezona',     ikona: '🍰', label: 'Sezónní',           popis: 'Auto on/off výrobků dle data, předobjednávky', cena: '+1 500 Kč/rok' },
  ];
  return `
    <h3 style="margin:0 0 8px">🎁 Specializované balíčky (roční licence)</h3>
    <p style="font-size:13px;color:var(--text-3);margin:0 0 18px">Rozšiřují systém o funkce pro tvůj obor. Jsou součástí <strong>roční licence</strong> — označ které tě zajímají a dodavatel ti připraví licenční klíč, který je odemkne. Core (objednávky / faktury / výroba / HACCP) je v základu zdarma.</p>
    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
      ${balicky.map(b => {
        const aktiv = data.balicky.includes(b.id);
        return `<label style="display:flex;align-items:center;gap:14px;padding:14px 18px;border:2px solid ${aktiv ? 'var(--primary)' : 'var(--border)'};border-radius:10px;background:${aktiv ? 'var(--surface-2)' : 'var(--surface)'};cursor:pointer;transition:all 0.15s">
          <input type="checkbox" ${aktiv ? 'checked' : ''} onchange="window._onboardTogglePkg('${b.id}', this.checked)" style="width:20px;height:20px;cursor:pointer;accent-color:var(--primary)">
          <span style="font-size:32px;line-height:1">${b.ikona}</span>
          <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:15px;margin-bottom:2px">${b.label}</div>
            <div style="font-size:12.5px;color:var(--text-3);line-height:1.4">${b.popis}</div>
          </div>
          <div style="text-align:right;flex-shrink:0">
            <div style="font-weight:700;color:var(--primary-dark);font-size:13px">🗓️ Roční licence</div>
            <div style="font-size:11px;color:var(--text-3)">aktivní po zakoupení</div>
          </div>
        </label>`;
      }).join('')}
    </div>
    <div style="padding:12px;background:var(--info-bg);color:var(--info-text);border-radius:8px;font-size:12.5px">
      💡 Tady jen <strong>nezávazně označíš</strong>, co tě zajímá. Balíčky jsou <strong>roční licence</strong> a aktivují se <strong>po zakoupení</strong> — kdykoliv později.
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="onboardBack()">← Zpět</button>
      <button class="btn-secondary" onclick="onboardSkipStep()">Žádné, díky</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="onboardNext()" style="font-weight:700;padding:12px 24px">➜ Dál</button>
    </div>
  `;
}

function _onboardDemoDataStep() {
  // 🆕 v2.9.326 — Toggle: user vybere DEMO vs PRÁZDNÁ DB (předtím auto-naplnilo bez ptaní)
  // Tři stavy:
  //   choice === null  → ukáže 2-card volbu
  //   choice === 'yes' → spustí seed (auto-trigger po výběru)
  //   choice === 'no'  → ukáže potvrzení prázdné DB + Pokračovat
  const choice = state._onboard.data.demo_seed_choice;
  const done = state._onboard.data.demo_seed_done;
  const stats = state._onboard.data.demo_seed_stats || {};
  const err = state._onboard.data.demo_seed_error;

  // Auto-trigger seed jen pokud user explicitně vybral 'yes' a ještě neběží
  if (choice === 'yes' && !done && !err && !state._onboard.data.demo_seed_running) {
    state._onboard.data.demo_seed_running = true;
    setTimeout(() => _onboardAutoSeed(), 100);
  }

  // STAV 1: výběr (žádná akce zatím)
  if (!choice) {
    return `
      <h3 style="margin:0 0 8px">🎬 Demo data — chceš je naplnit?</h3>
      <p style="font-size:13px;color:var(--text-3);margin:0 0 18px">
        Můžeš začít buď s <strong>kompletní ukázkou</strong> (rychle si vyzkoušíš všechny funkce), nebo s <strong>prázdnou aplikací</strong> (rovnou zadáváš svoje data).
      </p>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:16px">
        <button type="button" onclick="state._onboard.data.demo_seed_choice='yes'; renderOnboardingStep()"
                style="background:linear-gradient(135deg,#FFFBEB,#FFF8F0);border:2px solid #F0D9B8;border-radius:12px;padding:20px;text-align:left;cursor:pointer;font-family:inherit;transition:all 0.15s"
                onmouseover="this.style.borderColor='#BA7517';this.style.boxShadow='0 6px 18px rgba(186,117,23,0.18)'"
                onmouseout="this.style.borderColor='#F0D9B8';this.style.boxShadow='none'">
          <div style="font-size:36px;line-height:1;margin-bottom:8px">🎬</div>
          <div style="font-weight:800;font-size:16px;color:#854F0B;margin-bottom:6px">Naplnit demo daty</div>
          <div style="font-size:12px;color:#854F0B;line-height:1.5;opacity:0.92">
            10 výrobků, 35+ surovin, recepty, kalkulace, 5 odběratelů, objednávky, POS users, stoly… Vše hned funkční.
          </div>
          <div style="margin-top:10px;display:inline-block;padding:4px 10px;background:#fff;color:#854F0B;border-radius:6px;font-size:11px;font-weight:700">⭐ Doporučeno</div>
        </button>

        <button type="button" onclick="state._onboard.data.demo_seed_choice='no'; renderOnboardingStep()"
                style="background:#F7F8FA;border:2px solid #E1E5EB;border-radius:12px;padding:20px;text-align:left;cursor:pointer;font-family:inherit;transition:all 0.15s"
                onmouseover="this.style.borderColor='#5C6370';this.style.boxShadow='0 6px 18px rgba(0,0,0,0.08)'"
                onmouseout="this.style.borderColor='#E1E5EB';this.style.boxShadow='none'">
          <div style="font-size:36px;line-height:1;margin-bottom:8px">📭</div>
          <div style="font-weight:800;font-size:16px;color:#1d1d1f;margin-bottom:6px">Začít čistě</div>
          <div style="font-size:12px;color:#5C6370;line-height:1.5">
            Prázdná aplikace — žádné výrobky, žádní odběratelé. Hodí se když přecházíš z jiného systému a chceš importovat svoje data.
          </div>
          <div style="margin-top:10px;display:inline-block;padding:4px 10px;background:#fff;color:#5C6370;border-radius:6px;font-size:11px;font-weight:700">🆕 Čistá DB</div>
        </button>
      </div>

      <div style="padding:12px;background:var(--info-bg);color:var(--info-text);border-radius:8px;font-size:12.5px">
        💡 Volbu můžeš změnit i později — demo data lze kdykoli naplnit/smazat v <strong>Nastavení → Údržba</strong> (úplně dole).
      </div>

      <div class="form-actions">
        <button class="btn-secondary" onclick="onboardBack()">← Zpět</button>
        <div style="flex:1"></div>
      </div>
    `;
  }

  // STAV 2: user vybral 'no' → potvrzení prázdné DB
  if (choice === 'no') {
    return `
      <h3 style="margin:0 0 8px">📭 Začínáš s prázdnou aplikací</h3>
      <p style="font-size:13px;color:var(--text-3);margin:0 0 18px">
        Demo data nebudou naplněna. Můžeš začít zadávat svoje výrobky, suroviny a odběratele.
      </p>

      <div style="padding:18px;background:#F7F8FA;border:1px solid #E1E5EB;border-radius:12px;margin-bottom:16px;text-align:center">
        <div style="font-size:42px;margin-bottom:10px">✅</div>
        <div style="font-weight:700;font-size:15px;color:#1d1d1f">Připraveno k prvnímu použití</div>
        <div style="font-size:12px;color:#5C6370;margin-top:8px;line-height:1.6">
          Začni v <strong>Výrobky</strong> (přidání produktu), <strong>Suroviny</strong> (sklad)<br>
          nebo <strong>Odběratelé</strong> (zákazníci).
        </div>
      </div>

      <div style="padding:12px;background:var(--info-bg);color:var(--info-text);border-radius:8px;font-size:12.5px">
        💡 Změnil ses? <button onclick="state._onboard.data.demo_seed_choice=null; renderOnboardingStep()" style="background:none;border:none;color:var(--primary);text-decoration:underline;cursor:pointer;font:inherit;padding:0">← Zpět na výběr</button>
      </div>

      <div class="form-actions">
        <button class="btn-secondary" onclick="state._onboard.data.demo_seed_choice=null; renderOnboardingStep()">← Změnit volbu</button>
        <div style="flex:1"></div>
        <button class="btn-primary btn-green" onclick="onboardNext()" style="font-weight:700;padding:12px 24px">➜ Pokračovat</button>
      </div>
    `;
  }

  // STAV 3: user vybral 'yes' → loading / done / error
  return `
    <h3 style="margin:0 0 8px">🎬 Připravujeme demo data</h3>
    <p style="font-size:13px;color:var(--text-3);margin:0 0 18px">
      Naplňujeme aplikaci kompletní funkční ukázkou — výrobky, recepty, odběratele, objednávky, suroviny naskladněné, kalkulace s marží, POS uživatele, stoly…
    </p>

    <div style="padding:18px;background:linear-gradient(135deg,#FFFBEB,#FFF8F0);border:1px solid #F0D9B8;border-radius:12px;margin-bottom:16px;text-align:center">
      ${!done && !err ? `
        <div style="font-size:42px;margin-bottom:10px;animation:spin 2s linear infinite;display:inline-block">⏳</div>
        <div style="font-weight:700;font-size:15px;color:#854F0B">Nahrávám demo data…</div>
        <div style="font-size:12px;color:#854F0B;margin-top:6px;opacity:0.8">Trvá to obvykle 5–10 sekund.</div>
      ` : err ? `
        <div style="font-size:42px;margin-bottom:10px">⚠️</div>
        <div style="font-weight:700;font-size:15px;color:#991B1B">${esc(err)}</div>
        <div style="font-size:12px;color:#7F1D1D;margin-top:6px">Můžete pokračovat — data doplníte později v Nastavení.</div>
        <button class="btn-secondary" onclick="state._onboard.data.demo_seed_running=false; state._onboard.data.demo_seed_error=null; renderOnboardingStep()" style="margin-top:10px;font-size:12px;padding:6px 14px">🔄 Zkusit znova</button>
      ` : `
        <div style="font-size:42px;margin-bottom:10px">✅</div>
        <div style="font-weight:700;font-size:15px;color:#166534">Demo data připravena</div>
        <div style="font-size:12px;color:#15803D;margin-top:8px;line-height:1.6">
          📦 ${stats.vyrobky || 0} výrobků + ${stats.suroviny || 0} surovin (naskladněno)<br>
          🧬 ${stats.recepty || 0} receptů + ${stats.kalkulace_ulozeno || 0} kalkulací s marží<br>
          👥 ${stats.odberatele || 0} odběratelů + ${stats.historie_objednavky || 0} obj historie (14 dnů)<br>
          🍕 ${stats.pos_users || 0} POS users s PIN + ${stats.stoly || 0} stolů + ${stats.kuryrky || 0} kurýrek
        </div>
      `}
    </div>

    <div style="padding:12px;background:var(--info-bg);color:var(--info-text);border-radius:8px;font-size:12.5px">
      💡 Demo data můžeš kdykoliv resetovat v <strong>Nastavení → Údržba → Demo data</strong> (úplně dole).
    </div>

    <div class="form-actions">
      <button class="btn-secondary" onclick="state._onboard.data.demo_seed_choice=null; state._onboard.data.demo_seed_running=false; renderOnboardingStep()">← Změnit volbu</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" ${!done && !err ? 'disabled style="opacity:0.5;cursor:not-allowed;padding:12px 24px;font-weight:700"' : 'onclick="onboardNext()" style="font-weight:700;padding:12px 24px"'}>
        ${done ? '➜ Pokračovat' : (err ? '➜ Pokračovat bez demo' : '⏳ Čekejte…')}
      </button>
    </div>

    <style>@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>
  `;
}

// 🆕 v2.9.292 — Auto-spuštění seedu při onboardingu (bez user akce)
async function _onboardAutoSeed() {
  try {
    const res = await api('admin_demo_seed.php?action=apply', {
      method: 'POST',
      body: JSON.stringify({ kategorie: state._onboard.data.kategorie_zvolene || [] }),
    });
    state._onboard.data.demo_seed_stats = res;
    state._onboard.data.demo_seed_done = true;
    state._onboard.data.demo_seed_error = null;
  } catch (e) {
    console.error('Onboarding auto-seed failed:', e);
    state._onboard.data.demo_seed_error = 'Demo data se nepodařilo nahrát: ' + (e.message || 'neznámá chyba');
  } finally {
    state._onboard.data.demo_seed_running = false;
    renderOnboardingStep();
  }
}

function _onboardQuickStartStep() {
  return `
    <h3 style="margin:0 0 8px">🚀 Quick Start — 7 kroků k prvním tržbám</h3>
    <p style="font-size:13px;color:var(--text-3);margin:0 0 18px">Tohle je seznam co dělat hned po dokončení onboardingu. Sleduj checklist v Přehledu.</p>
    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:18px">
      ${[
        {ikona:'🥖', label:'Přidat 5-10 výrobků s cenami', sub:'Sekce Výrobky → +Nový. Nebo nahraj z Excelu.', cas:'~10 min'},
        {ikona:'👥', label:'Vytvořit první odběratele', sub:'Sekce Odběratelé → +Nový. Stačí jméno + e-mail.', cas:'~3 min'},
        {ikona:'📋', label:'Otestovat objednávku', sub:'Sekce Objednávky → +Nová → vyber odběratele + výrobky.', cas:'~5 min'},
        {ikona:'📃', label:'Vytvořit dodací list', sub:'Z objednávky → tlačítko "📃 Vytvořit DL".', cas:'~1 min'},
        {ikona:'💰', label:'Vystavit fakturu', sub:'Z DL → "💰 Vyfakturovat". ISDOC export pro účetní.', cas:'~1 min'},
        {ikona:'🛍️', label:'Pozvat odběratele do B2B portálu', sub:'Karta odběratele → 📧 Pozvánka. Odběratel pak objedná z mobilu.', cas:'~2 min'},
        {ikona:'🏷️', label:'Vytisknout cenovky', sub:'Sekce Štítky → Cenovky z výrobků → 🖨️ Tisk.', cas:'~5 min'},
      ].map((s, i) => `
        <div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--surface-2);border-radius:10px;border-left:4px solid var(--primary)">
          <div style="background:var(--primary);color:#fff;width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0">${i+1}</div>
          <span style="font-size:24px;line-height:1">${s.ikona}</span>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:14px;margin-bottom:2px">${s.label}</div>
            <div style="font-size:12px;color:var(--text-3)">${s.sub}</div>
          </div>
          <span style="font-size:11px;color:var(--text-3);background:var(--surface);padding:3px 8px;border-radius:999px;white-space:nowrap">${s.cas}</span>
        </div>
      `).join('')}
    </div>
    <div style="padding:12px;background:#DCFCE7;color:#166534;border-radius:8px;font-size:12.5px">
      ✅ <strong>Celkem ~30 minut</strong> a budeš mít aplikaci plně rozjetou s prvními tržbami.
    </div>
    <div class="form-actions">
      <button class="btn-secondary" onclick="onboardBack()">← Zpět</button>
      <div style="flex:1"></div>
      <button class="btn-primary btn-green" onclick="onboardNext()" style="font-weight:700;padding:12px 24px">➜ Hotovo!</button>
    </div>
  `;
}

window._onboardSetLang = async function(code) {
  state._onboard.data.jazyk = code;
  localStorage.setItem('appek_lang', code);
  // Aplikuj jazyk hned, ať uživatel vidí změnu v UI
  if (typeof window.setAppekLang === 'function') window.setAppekLang(code);
  // 🌍 T4 — aplikuj lokalizované defaults (měna, DPH, region, formát data)
  // Force = false → respektuje hodnoty, které už uživatel nastavil v Nastavení
  try {
    const cfg = await window.applyLocaleDefaults(code, false);
    if (cfg) {
      const msg = ({
        cs: `🇨🇿 Nastaveno: ${cfg.currency} ${cfg.currency_symbol}, DPH ${cfg.vat_rates.map(v => v.sazba + '%').join('/')}, ${cfg.country_label}`,
        en: `🇬🇧 Set: ${cfg.currency} ${cfg.currency_symbol}, VAT ${cfg.vat_rates.map(v => v.sazba + '%').join('/')}, ${cfg.country_label}`,
        es: `🇪🇸 Configurado: ${cfg.currency} ${cfg.currency_symbol}, IVA ${cfg.vat_rates.map(v => v.sazba + '%').join('/')}, ${cfg.country_label}`,
      })[code] || '';
      if (msg && typeof toastSuccess === 'function') toastSuccess(msg);
    }
  } catch (e) { /* ignorovat — onboarding pokračuje i bez API */ }
  renderOnboardingStep();
};

window._onboardSetMode = function(mode) {
  state._onboard.data.install_mode = mode;
  renderOnboardingStep();
};

window._onboardSetLocalOs = function(os) {
  state._onboard.data.local_os = os;
  renderOnboardingStep();
};

window._onboardTogglePkg = function(pkgId, on) {
  const arr = state._onboard.data.balicky || [];
  if (on) {
    if (!arr.includes(pkgId)) arr.push(pkgId);
  } else {
    const i = arr.indexOf(pkgId);
    if (i >= 0) arr.splice(i, 1);
  }
  state._onboard.data.balicky = arr;
  renderOnboardingStep();
};

window._onboardSetSeed = function(yes) {
  state._onboard.data.demo_data_seed = yes;
  renderOnboardingStep();
};

window._onboardApplySeed = async function() {
  const data = state._onboard.data;
  if (data.demo_data_seed === true) {
    // 🆕 v2.0.97 FIX: backend má action='apply', ne 'seed'.
    // Předtím onboarding sice volal demo seed, ale endpoint vrátil 404 → John Doe se nikdy nevytvořil.
    try {
      const res = await api('admin_demo_seed.php?action=apply', { method: 'POST', body: JSON.stringify({ kategorie: data.kategorie_zvolene || [] }) });
      const summary = `Vytvořeno: ${res.kategorie || 0} kategorií, ${res.vyrobky || 0} výrobků, ${res.odberatele || 0} odběratelů, ${res.objednavky || 0} obj, ${res.dodaci_listy || 0} DL, ${res.faktury || 0} faktur`;
      toastSuccess(summary, '🎬 Demo data připravena (John Doe s.r.o.)');
    } catch (e) {
      console.error('Demo seed failed:', e);
      toastWarn('Demo data nelze nahrát: ' + (e.message || 'chyba serveru'));
    }
  }
  onboardNext();
};

window.onboardNext = function() { state._onboard.step++; renderOnboardingStep(); };
window.onboardBack = function() { state._onboard.step = Math.max(0, state._onboard.step - 1); renderOnboardingStep(); };
window.onboardSkipStep = function() { state._onboard.step++; renderOnboardingStep(); };

window.onboardSkip = async function() {
  if (!(await confirmDialog({ msg: 'Opravdu přeskočit onboarding? Všechno najdete v Nastavení a můžete dokončit kdykoliv.', danger: false }))) return;
  try { await api('admin_onboarding.php?action=dismiss', { method: 'POST' }); } catch {}
  closeModal();
};

window.onboardComplete = async function() {
  // 1) HACCP — naimportovat výchozí dokumenty firmy + automaticky personalizovat
  //    daty z onboardingu (firma_nazev, adresa, IČO, telefon, jednatel…).
  //    Backend import_default volá personalize_haccp_obsah() automaticky.
  let haccpInfo = '';
  try {
    const r = await api('admin_haccp_dokumenty.php?action=import_default', { method: 'POST' });
    if (r.ok && r.created) {
      haccpInfo = `\n📋 HACCP: vytvořeno ${r.created} dokumentů` + (r.personalizovano ? ` s daty firmy "${r.firma}"` : '');
    } else if (r.existing) {
      // Už existují — alespoň re-personalize aktuálními daty firmy
      try {
        const p = await api('admin_haccp_dokumenty.php?action=personalize', { method: 'POST' });
        if (p.aktualizovano) haccpInfo = `\n📋 HACCP: aktualizováno ${p.aktualizovano} dokumentů daty firmy`;
      } catch {}
    }
  } catch (e) { /* nezablokovat dokončení onboardingu */ }

  // 2) Označ onboarding jako dokončený
  try { await api('admin_onboarding.php?action=complete', { method: 'POST' }); } catch {}
  closeModal();
  alert('🎉 Onboarding dokončen!' + haccpInfo);
  navigate('dashboard');
};

// 🔍 v3.0.289 — ARES/RPO lookup ve formuláři ODBĚRATELE (reuse onboarding endpointu)
window.odberatelAresLookup = async function() {
  const inp = document.getElementById('od-ico');
  const ico = (inp ? inp.value : '').trim();
  if (!ico) return alert('Vyplň IČO');
  const btn = event && event.target ? event.target : null;
  const orig = btn ? btn.textContent : '';
  if (btn) { btn.disabled = true; btn.textContent = '⏳'; }
  try {
    const d = await api(`admin_onboarding.php?action=ares&ico=${encodeURIComponent(ico)}`);
    const setFld = (id, v) => { const el = document.getElementById(id); if (el && v) el.value = v; };
    setFld('od-nazev', d.nazev);
    setFld('od-dic',   d.dic);
    setFld('od-ul',    d.ulice);
    setFld('od-me',    d.mesto);
    setFld('od-psc',   d.psc);
    const zdroj = d._zdroj === 'rpo' ? '🇸🇰 RPO' : '🇨🇿 ARES';
    toast(`✅ Načteno z ${zdroj}: ${d.nazev || ''} — nezapomeň uložit`, 'success');
  } catch (e) {
    toast('❌ ' + (/nenalezen/i.test(String(e.message)) ? 'IČO nenalezeno (zkontroluj 8 číslic)' : (e.message || 'ARES chyba')), 'error');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = orig; }
  }
};

// 🔍 ARES/RPO lookup z Nastavení → Firma a doklady
window.nastaveniAresLookup = async function() {
  const ico = document.getElementById('ns-ico').value.trim();
  if (!ico) return alert('Vyplň IČO');
  try {
    const d = await api(`admin_onboarding.php?action=ares&ico=${ico}`);
    // Vyplň všechna pole, ale jen pokud server vrátil neprázdné hodnoty
    const setFld = (id, v) => { const el = document.getElementById(id); if (el && v) el.value = v; };
    setFld('ns-nazev', d.nazev);
    setFld('ns-dic',   d.dic);
    setFld('ns-ulice', d.ulice);
    setFld('ns-mesto', d.mesto);
    setFld('ns-psc',   d.psc);
    const zdroj = d._zdroj === 'rpo' ? '🇸🇰 Slovenský RPO' : '🇨🇿 ARES';
    alert(t('ares_loaded_dont_forget_save', { zdroj }));
  } catch (e) {
    const msg = String(e.message || e);
    if (/nenalezen/i.test(msg)) {
      alert(t('ares_check_ico_8', { msg }));
    } else {
      alert('Chyba: ' + msg);
    }
  }
};

window.onboardAresLookup = async function() {
  const ico = document.getElementById('ob-ico').value.trim();
  if (!ico) return alert('Zadejte IČO');
  try {
    const d = await api(`admin_onboarding.php?action=ares&ico=${ico}`);
    if (d.nazev) document.getElementById('ob-nazev').value = d.nazev;
    if (d.dic) document.getElementById('ob-dic').value = d.dic;
    if (d.ulice) document.getElementById('ob-ulice').value = d.ulice;
    if (d.mesto) document.getElementById('ob-mesto').value = d.mesto;
    if (d.psc) document.getElementById('ob-psc').value = d.psc;
    // Hláška podle zdroje (ARES CZ vs. RPO SK)
    const zdroj = d._zdroj === 'rpo' ? '🇸🇰 Slovenský RPO' : '🇨🇿 ARES';
    alert(t('ares_loaded_simple', { zdroj }));
  } catch (e) {
    // Lepší error hláška
    const msg = String(e.message || e);
    if (/nenalezen/i.test(msg)) {
      alert(t('ares_check_ico_8_long', { msg }));
    } else {
      alert('Chyba: ' + msg);
    }
  }
};

window.onboardUploadLogo = async function() {
  const file = document.getElementById('ob-logo-file')?.files?.[0];
  if (!file) return;
  const fd = new FormData();
  fd.append('logo', file);
  try {
    const r = await api('admin_nastaveni.php?action=upload_logo', { method: 'POST', body: fd });
    document.getElementById('ob-logo-prev').innerHTML = `<img src="${esc(r.logo_url)}" style="max-width:100%;max-height:100%;object-fit:contain">`;
    aplikovatFavicon(r.favicon_url);
    aplikovatLogo(r.logo_url);
  } catch (e) { alert('Chyba: ' + e.message); }
};

window.onboardSaveAndNext = async function() {
  const data = {};
  ['ico','dic','nazev','ulice','mesto','psc','email','telefon','web','banka'].forEach(k => {
    const el = document.getElementById('ob-' + k);
    if (el) data['firma_' + k] = el.value.trim();
  });
  try {
    await api('admin_nastaveni.php', { method: 'PUT', body: JSON.stringify(data) });
    onboardNext();
  } catch (e) { alert('Chyba ukládání: ' + e.message); }
};

window.onboardKategorieToggle = function(nazev, checked) {
  const arr = state._onboard.data.kategorie_zvolene || [];
  if (checked && !arr.includes(nazev)) arr.push(nazev);
  if (!checked) {
    const i = arr.indexOf(nazev);
    if (i >= 0) arr.splice(i, 1);
  }
  state._onboard.data.kategorie_zvolene = arr;
};

window.onboardSeedAndNext = async function() {
  const kategorie = state._onboard.data.kategorie_zvolene || [];
  if (kategorie.length === 0) {
    if (!(await confirmDialog({ msg: 'Žádné kategorie nejsou vybrané. Pokračovat bez vzorových dat?', danger: false }))) return;
    return onboardNext();
  }
  try {
    const r = await api('admin_onboarding.php?action=seed_demo', { method: 'POST', body: JSON.stringify({ kategorie }) });
    alert(t('demo_created_cats_products', { cats: r.kategorie_pridano, prods: r.vyrobky_pridano }));
    onboardNext();
  } catch (e) { alert('Chyba: ' + e.message); }
};

// Manuální restart pro testování — z konzole: restartOnboarding()
window.restartOnboarding = async function() {
  await api('admin_onboarding.php?action=restart', { method: 'POST' });
  openOnboarding(0);
};

// Helper pro skrývání mazacích tlačítek v UI
function isSuperAdmin() {
  return (state.admin?.role || 'admin') === 'admin';
}
// Vrátí HTML tlačítka pouze pro super admina, jinak prázdný string
function adminOnly(html) {
  return isSuperAdmin() ? html : '';
}

// 🆕 v2.9.305 — navigate(page, args?) — args se propaguje do render fn (filtry, IDs, atd.)
// Důvod: dashboard alerts a notifikace potřebují předem-vyplnit filtr (např. nefakturováno).
// Předtím dělaly `navigate('dodaci_listy'); setTimeout(()=>applyDlFilters({fakturovano:'0'}),100)`
// což nefungovalo (applyDlFilters četl jen z DOM, ignoroval arg).
async function navigate(page, args) {
  // Zkontroluj, jestli má aktuální role právo na tuto stránku
  if (!muzeNavigovat(page)) {
    // 🆕 v2.9.305 — místo TICHÉHO fallbacku ukaž toast, ať user ví proč nic nereaguje
    // (předtím: klick na dashboard alert "DL nefakturováno" pro POS usera → 0 reakce)
    const role = state.admin?.role || 'admin';
    const allowed = state.rolePrava[role] || DEFAULT_ROLE_PRAVA[role] || ['dashboard'];
    const requestedPage = page;
    page = allowed[0] || 'dashboard';
    args = undefined; // filtry pro target page nedávají smysl po fallbacku
    if (requestedPage !== page && typeof toast === 'function') {
      try { toast(t('toast_section_requires_role', { section: requestedPage, role }), 'warn'); } catch (e) {}
    }
  }
  state.current = page;
  // 🆕 v3.0.269 — data-page na #content → CSS uvolní max-width:1400 pro datové
  //   seznamy (faktury/DL/objednávky/výrobky/suroviny) → tabulky vyplní šířku
  //   monitoru („flex"). Formulářové/dashboard stránky zůstanou centrované.
  try { const _c = document.getElementById('content'); if (_c) _c.dataset.page = page; } catch (e) {}
  // 🆕 v3.0.375 — Nástroje pod-stránky (integrace/tiskárny/štítky) zvýrazní v menu rodiče „Nástroje" (jinak nic = uživatel „ztracený")
  const _navActive = (page === 'integrace' || page === 'tiskarny' || page === 'stitky') ? 'nastroje' : page;
  document.querySelectorAll('.nav-item').forEach((b) => b.classList.toggle('active', b.dataset.page === _navActive));
  // 📱 Synchronizace bottom nav (mobile) — aktivní položka
  document.querySelectorAll('.bottom-nav-item').forEach(b => b.classList.toggle('is-active', b.dataset.page === page));
  // 🎁 Re-render package badges v topbaru — aby se zvýraznil aktivní balíček
  document.querySelectorAll('.pkg-big-btn, .pkg-badge-square').forEach(b => b.classList.toggle('is-active', b.dataset.page === page));
  // 🆕 v3.0.39 — Update Floating Action Button per page (mobile)
  try { window.updateAppFAB && window.updateAppFAB(page); } catch (e) {}

  try {
    if (page === 'dashboard') await renderDashboard(args);
    else if (page === 'objednavky') await renderObjednavky(args);
    else if (page === 'vratky') await renderVratky(); // 🆕 v3.0.277 — správa vratek (VRA- + DOB-)
    else if (page === 'vouchery') await renderVouchers(); // 🆕 v3.0.281 — vouchery / dárkové karty
    else if (page === 'vyroba') await renderVyrobaHub();
    else if (page === 'vyrobni_list') await renderVyrobniList();
    else if (page === 'dodaci_listy') await renderDodaciListy(args);
    else if (page === 'rozvozy') await renderRozvozy();
    else if (page === 'recurring') await renderRecurring();
    else if (page === 'sales_report') await renderSalesReport();
    else if (page === 'faktury') await renderFaktury(args);
    else if (page === 'vyrobky') await renderVyrobky();
    else if (page === 'katalog') await renderKatalog();
    else if (page === 'stitky') await renderStitky();
    else if (page === 'nastroje') await renderNastroje();
    else if (page === 'tiskarny') await renderTiskarny(); // 🆕 v3.0.29
    else if (page === 'haccp') await renderHaccp();
    else if (page === 'odberatele') await renderOdberatele();
    else if (page === 'users') await renderUsers();
    else if (page === 'cenove_skupiny') await renderCenoveSkupiny();
    else if (page === 'kategorie') await renderKategorie();
    else if (page === 'suroviny') await renderSuroviny();
    else if (page === 'sklad') await renderSklad();
    else if (page === 'export_vyroby') await renderExportVyroby();
    else if (page === 'vyrobni_kalkulace') await renderVyrobniKalkulace();
    else if (page === 'nastaveni') { if (state._nastaveniTab === 'integrace') state._nastaveniTab = 'firma'; await renderNastaveni(); } // 🆕 v3.0.375 — neděď skrytý 'integrace' tab (jinak „Nastavení" ukáže Integrace)
    else if (page === 'integrace') { state._nastaveniTab = 'integrace'; await renderNastaveni(); } // 🆕 v3.0.370 — Integrace = samostatná stránka z Nástrojů (standalone shell v renderNastaveni)
    else if (page && page.startsWith('pkg_')) await renderPackagePage(page);
    else if (page === 'diagnostika') await renderDiagnostika();
    // Po renderu doplň data-label do <td> aby se na mobilu transformovaly v karty
    // Defer aby user viděl obsah okamžitě a nezdržoval první paint
    if ('requestIdleCallback' in window) {
      requestIdleCallback(() => labelizeTables(), { timeout: 200 });
    } else {
      setTimeout(labelizeTables, 0);
    }
    // 📱 v3.0.296 — na mobilu po navigaci odscroluj na obsah. .sidebar-nav (dlaždice menu)
    //   je na mobilu display:contents naskládaná NAD .main-content; bez tohoto zůstane
    //   uživatel koukat na menu a sekce (typicky Rozvozové trasy) vypadá „nefunkční".
    //   Dashboard = domácí launcher → nech ho být.
    try {
      if (page !== 'dashboard' && window.matchMedia && window.matchMedia('(max-width: 700px)').matches) {
        const _mc = document.getElementById('content');
        if (_mc) requestAnimationFrame(() => { try { _mc.scrollIntoView({ block: 'start' }); } catch (e) {} });
      }
    } catch (e) {}
  } catch (e) {
    document.getElementById('content').innerHTML = `<div style="color:var(--danger-text);padding:20px;background:var(--danger-bg);border-radius:8px;">Chyba: ${esc(e.message)}</div>`;
  }
}

/**
 * Pro každou .table doplní `data-label` na každý <td> podle textu odpovídajícího <th>.
 * Mobilní CSS používá data-label aby zobrazil název sloupce nad hodnotou (tabulka → karta).
 */
function labelizeTables() {
  document.querySelectorAll('.table').forEach((tbl) => {
    const headers = Array.from(tbl.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    if (headers.length === 0) return;
    tbl.querySelectorAll('tbody tr').forEach((tr) => {
      Array.from(tr.children).forEach((td, i) => {
        const lbl = headers[i] || '';
        if (!td.hasAttribute('data-label')) {
          td.setAttribute('data-label', lbl);
        }
        // Označ buňky bez label (např. sloupec s tlačítkem akce) zvláštní třídou
        if (!lbl) {
          td.classList.add('td-action');
        }
      });
    });
  });
}

// Spustit i po každém otevření modalu (dynamické tabulky uvnitř detailů) - viz openModal výše

