# Architektura synchronizace (Hybrid mode)

**Technická dokumentace hybridní synchronizace mezi lokálním PC a cloudem.**

| | |
|---|---|
| **Verze** | 2.0.4 |
| **Aktualizováno** | 2026-05-17 |
| **Audience** | Vývojáři, system architekti |
| **Status** | Production-ready |

---

## Obsah

1. [Cíl](#cíl)
2. [Tři režimy provozu](#tři-režimy-provozu)
3. [Topologie](#topologie)
4. [Datový tok](#datový-tok)
5. [Bezpečnost](#bezpečnost)
6. [Konfigurace](#konfigurace)
7. [Konflikt resolution](#konflikt-resolution)
8. [Monitoring](#monitoring)
9. [Troubleshooting](#troubleshooting)

---

## Cíl

Umožnit provoz APPEK B2B ve **třech různých režimech** podle potřeb zákazníka:

| Režim | Kde běží admin | Kde běží B2B portál | Pro koho |
|-------|----------------|---------------------|----------|
| **Local only** | Lokální PC | Není dostupný | Offline-first, žádný internet |
| **Hybrid** | Lokální PC | Cloud (mirror) | Slabé připojení, mobilní B2B |
| **Cloud only** | Cloud | Cloud | Standardní webhosting |

Volbu zákazník provádí v instalátoru, lze později změnit v Nastavení.

---

## Tři režimy provozu

### Local only

```
┌─────────────────────────────┐
│      LOKÁLNÍ PC             │
│                             │
│  XAMPP / MAMP / Laragon     │
│  ┌─────────────────────┐    │
│  │ admin/ admin panel  │    │
│  │ MySQL local DB      │    │
│  │ api/ backend        │    │
│  └─────────────────────┘    │
│                             │
│  Bez internetu funguje      │
└─────────────────────────────┘
```

**Vlastnosti:**

- Žádný cloud, žádný sync
- B2B portál není dostupný z internetu
- Funguje plně offline
- Vhodné pro interní použití bez B2B distribuce

### Cloud only (standardní)

```
┌─────────────────────────────┐
│         CLOUD HOSTING       │
│                             │
│  Hostinger / Wedos / atd.   │
│  ┌─────────────────────┐    │
│  │ admin/ admin        │    │
│  │ b2b/ portál         │    │
│  │ MySQL cloud DB      │    │
│  │ api/ backend        │    │
│  └─────────────────────┘    │
│                             │
│  Dostupný odkudkoliv        │
└─────────────────────────────┘
```

**Vlastnosti:**

- Vše na hostingu
- B2B portál dostupný 24/7
- Vyžaduje spolehlivé připojení
- Standardní volba pro většinu provozů

### Hybrid (Local + Cloud sync)

```
┌──────────────────────────┐                    ┌──────────────────────────┐
│   PEKÁRNA PC (PRIMARY)   │                    │      CLOUD (MIRROR)      │
│                          │                    │                          │
│  ┌────────────────────┐  │  HTTPS + HMAC      │  ┌────────────────────┐  │
│  │ Admin panel        │  │  ─────────►        │  │ B2B portál         │  │
│  │ MySQL primary DB   │  │   PUSH změny       │  │ MySQL mirror DB    │  │
│  │ Sync agent (cron)  │  │                    │  │ Sync receiver      │  │
│  └────────────────────┘  │  ◄─────────        │  └────────────────────┘  │
│                          │  PULL objednávky   │                          │
│                          │                    │  Read-only mirror admin  │
│  Internet je optional    │                    │  Příjem nových objednávek│
└──────────────────────────┘                    └──────────────────────────┘
        ↑                                                    ↑
        │                                                    │
   Pekař/admin                                          Odběratel B2B
   (vždy funguje)                                       (čte / objednává)
```

**Vlastnosti:**

- Hlavní data na lokálním PC (vždy dostupná pekařům)
- Cloud zrcadlí pro mobilní přístup odběratelů
- Cron-based sync (interval volitelný, default 15 min)
- Fronta odolává krátkodobým výpadkům internetu
- Konflikt resolution: last-write-wins s audit logem

---

## Topologie

### Hybrid mode — komunikační uzly

```
┌────────────────────────────────────────────────────────┐
│                  HYBRID DATA FLOW                       │
├────────────────────────────────────────────────────────┤
│                                                         │
│  ┌──────────────┐                  ┌──────────────┐    │
│  │ MASTER (PC)  │ ◄─── HMAC ────► │ MIRROR(Cloud)│    │
│  │              │                  │              │    │
│  │ • Výrobky    │  PUSH:           │ • Výrobky    │    │
│  │ • Odběratele │   - changes      │ • Odběratele │    │
│  │ • Skladem    │   - prices       │ • Ceny       │    │
│  │              │                  │              │    │
│  │ • Příchozí   │ ◄─── PULL ───── │ • Nové obj.  │    │
│  │   objednávky │   - new orders   │ • Z B2B port.│    │
│  └──────────────┘                  └──────────────┘    │
│                                                         │
└────────────────────────────────────────────────────────┘
```

### Role serverů

| Role | Co umí | Co neumí |
|------|--------|----------|
| **Master** (PC) | Vše — výroba, fakturace, sklad | — |
| **Mirror** (Cloud) | Read-only admin pohled, B2B objednávky | Vystavovat faktury, mazat výrobky |

> **Note:** Mirror je read-only z hlediska admin operací. Jediné write operace na mirroru jsou nové B2B objednávky, které sync agent přenese na master.

---

## Datový tok

### Push (Master → Mirror)

**Frekvence:** Každých 15 minut (cron job na master PC) nebo on-demand.

**Obsah:**

- Změny v tabulce `vyrobky` (insert/update/delete)
- Změny v tabulce `odberatele`
- Změny v tabulce `ceniky` a `cenove_skupiny`
- Změny v `nastaveni` (firma_*, brand_*)
- Změny v `sazby_dph`, `kategorie`, `jednotky`

**Mechanismus:**

1. Master vede tabulku `sync_outbox` se změnami od posledního pushu
2. Cron agent volá `https://cloud-mirror/api/sync/receive.php` s HMAC podpisem
3. Cloud ověří podpis a aplikuje změny do své DB
4. Cloud odpoví HTTP 200 + checksum → master vymaže odpovídající záznamy z `sync_outbox`

### Pull (Mirror → Master)

**Frekvence:** Stejně jako push (sdílený cron).

**Obsah:**

- Nové objednávky vytvořené v B2B portálu
- Změny stavu existujících objednávek (zákazník upravil)
- Komentáře k objednávkám

**Mechanismus:**

1. Master volá `https://cloud-mirror/api/sync/pull.php?since=...`
2. Cloud vrací JSON pole nových/změněných záznamů s HMAC podpisem
3. Master ověří podpis a importuje do své DB
4. Master pošle ACK na `https://cloud-mirror/api/sync/ack.php?ids=...`

---

## Bezpečnost

### HMAC SHA-256 podpis

Všechny sync požadavky jsou podepsány sdíleným tajným klíčem (shared secret), který se vytvoří v instalátoru.

**Příklad podpisu:**

```php
$signature = hash_hmac('sha256', $payload . $timestamp, $shared_secret);
```

**Ověření:**

- Timestamp nesmí být starší než 5 minut (replay attack protection)
- Signature musí odpovídat HMAC z payloadu + timestamp + secret

### Shared secret

- Délka 64 znaků (256 bit)
- Vygenerován při instalaci přes `random_bytes(32)`
- Uložen v `api/config.local.php` na obou stranách
- **Nikdy** neposíláme po síti, ani v logech

### HTTPS

Sync probíhá výhradně přes HTTPS. Mirror musí mít platný SSL certifikát.

### Rate limiting

Mirror omezuje frekvenci sync požadavků:

- Max 60 požadavků / minutu z jedné IP
- Po překročení HTTP 429 (Too Many Requests)

---

## Konfigurace

### Master PC (lokální)

`api/config.local.php`:

```php
<?php
// Hybrid mode konfigurace
define('SYNC_MODE', 'master');
define('SYNC_PEER_URL', 'https://b2b.vase-domena.cz/api/sync');
define('SYNC_SHARED_SECRET', 'XXXXXXXXXXXXXXXXXXXXXXXXXXXX');
define('SYNC_INTERVAL_MINUTES', 15);
```

### Cron job na master PC

```cron
*/15 * * * * php /var/www/appek/api/sync/agent.php >> /var/log/appek-sync.log 2>&1
```

### Mirror (cloud)

`api/config.local.php`:

```php
<?php
define('SYNC_MODE', 'mirror');
define('SYNC_PEER_URL', 'https://pekarna-internal-ip/api/sync');
define('SYNC_SHARED_SECRET', 'XXXXXXXXXXXXXXXXXXXXXXXXXXXX');  // STEJNÝ jako master
define('SYNC_READ_ONLY', true);  // Blokuje destruktivní operace
```

---

## Konflikt resolution

### Strategie: Last-Write-Wins (LWW)

Při konfliktu (stejný záznam upraven souběžně na obou stranách) vítězí novější timestamp.

```php
if ($master_updated_at > $mirror_updated_at) {
    // Master verze vítězí
    $apply_master_version();
} else {
    // Mirror verze vítězí
    $apply_mirror_version();
}
```

### Audit log

Každý konflikt se loguje do `sync_conflicts`:

```sql
CREATE TABLE sync_conflicts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50),
    entity_id INT,
    master_value TEXT,
    mirror_value TEXT,
    winner ENUM('master', 'mirror'),
    resolved_at DATETIME,
    INDEX idx_resolved (resolved_at)
);
```

### Manuální revize

V adminu `admin/` je sekce **Sync → Konflikty** kde lze:

- Zobrazit historii konfliktů
- Manuálně přepsat výsledek
- Vyloučit problematický záznam ze sync

---

## Monitoring

### Sync dashboard

`admin/ → Nastavení → 🔄 Sync` zobrazuje:

- **Stav posledního syncu** (úspěch / chyba / probíhající)
- **Čas posledního syncu** (s timestamp)
- **Latence** (ms — čas mezi push a ACK)
- **Velikost fronty** (počet záznamů čekajících na sync)
- **Konflikt counter** (kolik konfliktů za 30 dní)

### Health endpoint

```
GET https://cloud-mirror/api/sync/health.php
```

Vrací JSON:

```json
{
  "status": "ok",
  "last_sync": "2026-05-17T14:23:45Z",
  "queue_size": 3,
  "uptime_seconds": 1284923
}
```

Pro externí monitoring (UptimeRobot, Pingdom).

### Logy

```bash
# Master sync agent log
tail -f /var/log/appek-sync.log

# Mirror receiver log
tail -f /home/user/public_html/api/.logs/sync-receive.log
```

---

## Troubleshooting

### "Invalid HMAC signature"

**Příčina:** Master a Mirror mají různý `SYNC_SHARED_SECRET`.

**Řešení:** Ověřte, že hodnota v `config.local.php` je **identická** na obou stranách.

### "Timestamp too old"

**Příčina:** Hodiny na master PC nebo mirror nejsou synchronizovány.

**Řešení:**

```bash
# Linux/macOS
sudo timedatectl set-ntp true

# Windows: Time and Language → Date and Time → Sync now
```

### "Connection refused" v cron logu

**Příčina:** Mirror je nedostupný (DNS, firewall, SSL).

**Řešení:**

1. Test ručně: `curl -I https://cloud-mirror.vase-domena.cz/api/sync/health.php`
2. Pokud nedostupné, kontrola u hostingu mirror serveru
3. Pokud network problém na master PC, sync agent automaticky zopakuje při dalším cronu

### Fronta roste, ale nepřenáší se

**Příčina:** Sync agent neběží nebo padá při startu.

**Řešení:**

```bash
# Manuální spuštění s výpisem
php /var/www/appek/api/sync/agent.php --verbose --dry-run

# Kontrola permissions
ls -la /var/www/appek/api/sync/agent.php
```

### Konflikt řešení

Pokud automatické LWW nestačí (např. ručně chcete vybrat řešení):

1. `admin/ → Nastavení → 🔄 Sync → Konflikty`
2. Vyberte konflikt
3. Klik "Přepsat master verzí" nebo "Přepsat mirror verzí"
4. Volitelně: vyloučit entitu ze sync (např. nepřenášet záznam č. X)

---

## Související dokumenty

- [README.md](README.md) — Přehled produktu
- [HOSTING_SETUP.md](deploy/docs/HOSTING_SETUP.md) — Nasazení
- [SECURITY.md](deploy/docs/SECURITY.md) — Bezpečnostní průvodce

---

**Kontakt:** support@appek.cz
**Aktualizováno:** 2026-05-17
