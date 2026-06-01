<?php
/**
 * 🍔 DELIVERY AGGREGATOR INTEGRATIONS — Wolt / Bolt Food / Dáme jídlo / Foodora
 *
 * Plnohodnotná live integrace s 4 hlavními food delivery službami v CZ/SK.
 *
 * Architektura:
 *   - Každá služba má svou třídu (Wolt_Client, BoltFood_Client, DameJidlo_Client, Foodora_Client)
 *   - Společný interface: test(), receiveOrder($payload), pushStatus($externalId, $stav), syncMenu($items), getWebhookUrl()
 *   - HTTP klient přes curl (žádný Composer dependencies)
 *   - Webhook signature verification (HMAC SHA256)
 *   - Mapping našich stavů → jejich stavů (a obráceně)
 *
 * Inbound flow (webhook):
 *   Service → wolt_webhook.php → da_handle_inbound_order('wolt', $payload)
 *     → vytvoří objednavku v naší DB s puvod='wolt' + uloží external_id pro budoucí status sync
 *
 * Outbound flow (manuální stav update v POS):
 *   POS změní stav → admin_pos.php → da_push_status('wolt', $externalId, 'doruceno')
 *     → POST na Wolt API
 *
 * Test flow (admin klikne "Test"):
 *   admin_couriers.php?action=test_integration&sluzba=wolt
 *     → Wolt_Client::test() → GET /merchant/me → ok/error
 *
 * Důležité:
 *   - Bez veřejné dokumentace nešlo otestovat realní endpoints. Stavba je dle veřejných
 *     blog postů + GitHub partner samples. Po prvním reálném použití s partner credentials
 *     může být potřeba doladit URL paths a payload mapping.
 *   - Wolt Merchant API: https://developer.wolt.com/docs/api/order-api
 *   - Bolt Food Restaurant Partner API: https://partners.bolt.eu/food (request access)
 *   - Dáme jídlo: REST API přes restaurátorský portál (https://restaurace.damejidlo.cz)
 *   - Foodora (Delivery Hero CEEMEA): vendor.delivery-hero.com partner portal
 */

require_once __DIR__ . '/config.php';

// =============================================================
// 🔧 SHARED HTTP CLIENT
// =============================================================

/**
 * Pošle HTTP request s curl (bez Composer). Vrátí ['status' => int, 'body' => mixed, 'headers' => array].
 * Body je auto-decoded JSON pokud Content-Type je json, jinak raw string.
 *
 * @param string $method  GET|POST|PUT|PATCH|DELETE
 * @param string $url     Plné URL
 * @param array  $headers Asociativní pole: ['Authorization' => 'Bearer xxx', ...]
 * @param mixed  $body    null | string | array (auto-JSON serialized pokud array)
 * @param int    $timeoutSec
 * @return array
 */
function da_http(string $method, string $url, array $headers = [], $body = null, int $timeoutSec = 12): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'APPEK/' . (defined('APP_VERSION') ? APP_VERSION : '3.0') . ' (+https://appek.cz)');
    // Capture response headers
    $respHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$respHeaders) {
        $len = strlen($header);
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
        return $len;
    });

    // Headers
    $hdrList = [];
    $hasContentType = false;
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'content-type') $hasContentType = true;
        $hdrList[] = "$k: $v";
    }
    // Body
    if ($body !== null) {
        if (is_array($body) || is_object($body)) {
            if (!$hasContentType) $hdrList[] = 'Content-Type: application/json';
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if (!empty($hdrList)) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrList);

    $rawBody = curl_exec($ch);
    $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno   = curl_errno($ch);
    $err     = curl_error($ch);
    curl_close($ch);

    if ($errno) {
        return ['status' => 0, 'body' => null, 'headers' => $respHeaders, 'error' => "curl: $err ($errno)"];
    }

    // Auto-decode JSON
    $contentType = $respHeaders['content-type'] ?? '';
    $parsedBody = $rawBody;
    if (strpos($contentType, 'application/json') !== false || strpos($contentType, 'json') !== false) {
        $decoded = json_decode($rawBody, true);
        if (json_last_error() === JSON_ERROR_NONE) $parsedBody = $decoded;
    }

    return ['status' => $status, 'body' => $parsedBody, 'headers' => $respHeaders, 'raw' => $rawBody];
}

/**
 * Vrátí integration row z DB.
 */
function da_get_integration(string $sluzba): ?array {
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT * FROM courier_integrations WHERE sluzba = :s");
        $st->execute(['s' => $sluzba]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Vrátí veřejnou webhook URL pro službu (kterou user vloží do partner portalu).
 */
function da_webhook_url(string $sluzba): string {
    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
    $files = [
        'wolt'       => '/api/wolt_webhook.php',
        'bolt'       => '/api/bolt_food_webhook.php',
        'dame_jidlo' => '/api/damejidlo_webhook.php',
        'foodora'    => '/api/foodora_webhook.php',
    ];
    if (!isset($files[$sluzba])) return '';
    return $base . $files[$sluzba];
}

/**
 * Zajistí, že existuje tabulka pro external order mapping (náš id ↔ jejich id).
 * Volá se z webhook + push_status.
 */
function da_ensure_mapping_table(): void {
    static $done = false;
    if ($done) return;
    try {
        db()->exec("
            CREATE TABLE IF NOT EXISTS delivery_external_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sluzba VARCHAR(20) NOT NULL,
                external_id VARCHAR(100) NOT NULL,
                objednavka_id INT NULL,
                stav VARCHAR(30) NOT NULL DEFAULT 'received',
                raw_payload MEDIUMTEXT NULL,
                last_status_push DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_ext (sluzba, external_id),
                INDEX idx_obj (objednavka_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $done = true;
    } catch (Throwable $e) {
        error_log('[appek/da] mapping table fail: ' . $e->getMessage());
    }
}

/**
 * Bezpečně zapíše webhook event do logu (pro debug + audit).
 */
function da_log_webhook(string $sluzba, string $event, array $payload, ?int $objId = null): void {
    try {
        $pdo = db();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS delivery_webhook_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sluzba VARCHAR(20) NOT NULL,
                event VARCHAR(60) NOT NULL,
                objednavka_id INT NULL,
                payload MEDIUMTEXT NULL,
                ip VARCHAR(45) NULL,
                received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_sluzba (sluzba),
                INDEX idx_recv (received_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->prepare("INSERT INTO delivery_webhook_log (sluzba, event, objednavka_id, payload, ip)
                       VALUES (:s, :e, :o, :p, :ip)")
            ->execute([
                's' => $sluzba, 'e' => $event, 'o' => $objId,
                'p' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable $e) {
        error_log('[appek/da] webhook log fail: ' . $e->getMessage());
    }
}

// =============================================================
// 🟢 WOLT CLIENT
// =============================================================
// Docs: https://developer.wolt.com/docs/api/order-api
// Base: https://pos-integration-service.wolt.com
//
// Stavy Wolt → naše:
//   CREATED/PRODUCTION → received (čeká na ack)
//   ACKNOWLEDGED      → vyrabi_se
//   READY             → pripravena
//   DELIVERED         → dorucena
//   REJECTED/CANCELLED → zrusena
class Wolt_Client {
    private string $apiKey;
    private string $storeId;
    private string $baseUrl = 'https://pos-integration-service.wolt.com';

    public function __construct(?string $apiKey = null, ?string $storeId = null) {
        $row = da_get_integration('wolt');
        $this->apiKey  = $apiKey  ?? ($row['api_key'] ?? '');
        $this->storeId = $storeId ?? ($row['store_id'] ?? '');
    }

    private function headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ];
    }

    /**
     * Ping API — ověří že credentials fungují. Vrátí ['ok' => bool, 'message' => string, 'details' => ...].
     */
    public function test(): array {
        if (!$this->apiKey)  return ['ok' => false, 'message' => 'Chybí API klíč'];
        if (!$this->storeId) return ['ok' => false, 'message' => 'Chybí Store ID (Venue ID v Wolt portalu)'];

        // Wolt /venues/{venueId}/status — vrátí current stav venue
        $r = da_http('GET', $this->baseUrl . "/venues/{$this->storeId}/status", $this->headers());
        if ($r['status'] === 200) {
            $body = $r['body'];
            return ['ok' => true, 'message' => '✅ Wolt connected — venue ' . ($body['status'] ?? 'online'), 'details' => $body];
        }
        if ($r['status'] === 401) return ['ok' => false, 'message' => '❌ Neplatný API klíč (401 Unauthorized)', 'details' => $r['body']];
        if ($r['status'] === 404) return ['ok' => false, 'message' => '❌ Venue ID nenalezeno (404). Zkontroluj Store ID.', 'details' => $r['body']];
        if ($r['status'] === 0)   return ['ok' => false, 'message' => '❌ Síťová chyba: ' . ($r['error'] ?? 'unknown')];
        return ['ok' => false, 'message' => "❌ Wolt API vrátilo HTTP {$r['status']}", 'details' => $r['body']];
    }

    /**
     * Acknowledge nově přijatou objednávku.
     */
    public function acknowledgeOrder(string $externalId): array {
        if (!$this->apiKey) return ['ok' => false, 'message' => 'Chybí API klíč'];
        $r = da_http('PUT', $this->baseUrl . "/orders/{$externalId}/accept", $this->headers());
        return ['ok' => $r['status'] === 200 || $r['status'] === 204, 'status' => $r['status'], 'body' => $r['body']];
    }

    /**
     * Push status update — naše stavy → Wolt stavy.
     */
    public function pushStatus(string $externalId, string $localStav): array {
        if (!$this->apiKey) return ['ok' => false, 'message' => 'Chybí API klíč'];

        // Mapping naše → Wolt action
        $action = null;
        switch ($localStav) {
            case 'nova':         $action = 'accept'; break;
            case 'vyrabi_se':    $action = 'mark_preparing'; break;
            case 'pripravena':
            case 'pripravene':   $action = 'mark_ready'; break;
            case 'expedovana':
            case 'na_ceste':     $action = 'mark_picked_up'; break;
            case 'dorucena':
            case 'doruceno':     $action = 'mark_delivered'; break;
            case 'zrusena':
            case 'zruseno':      $action = 'reject'; break;
        }
        if (!$action) return ['ok' => false, 'message' => "Neznámý mapping stavu: $localStav"];

        $r = da_http('PUT', $this->baseUrl . "/orders/{$externalId}/{$action}", $this->headers());
        $ok = in_array($r['status'], [200, 204], true);
        return ['ok' => $ok, 'status' => $r['status'], 'body' => $r['body'], 'action' => $action];
    }

    /**
     * Push menu — pošle náš katalog na Wolt.
     * Wolt: POST /venues/{venueId}/menu
     */
    public function syncMenu(array $items, array $categories = []): array {
        if (!$this->apiKey || !$this->storeId) return ['ok' => false, 'message' => 'Chybí credentials'];

        // Mapping naše vyrobky → Wolt menu items
        $woltItems = [];
        foreach ($items as $it) {
            $woltItems[] = [
                'external_id'  => 'appek-' . ($it['id'] ?? ''),
                'name'         => ['value' => $it['nazev'] ?? '', 'language' => 'cs'],
                'description'  => ['value' => $it['popis'] ?? '', 'language' => 'cs'],
                'price'        => (int) round(((float)($it['cena_s_dph'] ?? $it['cena'] ?? 0)) * 100), // cents
                'enabled'      => empty($it['neaktivni']),
                'category_id'  => 'appek-cat-' . ($it['kategorie_id'] ?? 0),
                'image_url'    => $it['obrazek_url'] ?? null,
            ];
        }
        $woltCategories = [];
        foreach ($categories as $c) {
            $woltCategories[] = [
                'external_id' => 'appek-cat-' . ($c['id'] ?? 0),
                'name'        => ['value' => $c['nazev'] ?? '', 'language' => 'cs'],
            ];
        }
        $payload = [
            'currency'   => 'CZK',
            'categories' => $woltCategories,
            'items'      => $woltItems,
        ];
        $r = da_http('POST', $this->baseUrl . "/venues/{$this->storeId}/menu", $this->headers(), $payload, 30);
        $ok = in_array($r['status'], [200, 201, 202], true);
        return ['ok' => $ok, 'status' => $r['status'], 'body' => $r['body'], 'items_count' => count($woltItems)];
    }

    /**
     * Verifikace webhook signature.
     * Wolt používá HMAC-SHA256 přes raw body s X-Wolt-Signature headerem.
     */
    public static function verifySignature(string $rawBody, string $signature, string $secret): bool {
        if (!$signature || !$secret) return false;
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Parse incoming webhook payload na náš formát.
     * Returns: ['external_id', 'cas_doruceni', 'castka', 'polozky', 'kontakt', 'adresa', 'note']
     */
    public static function parseInboundOrder(array $payload): array {
        $order = $payload['order'] ?? $payload;
        $items = [];
        foreach (($order['items'] ?? []) as $it) {
            $items[] = [
                'nazev'    => $it['name'] ?? '',
                'mnozstvi' => (int) ($it['count'] ?? 1),
                'cena_ks'  => ((float) ($it['unit_price'] ?? 0)) / 100, // cents → Kč
                'external_id' => $it['external_id'] ?? null,
            ];
        }
        $consumer = $order['consumer'] ?? [];
        $delivery = $order['delivery'] ?? [];
        $addr = $delivery['location'] ?? [];
        return [
            'external_id'  => $order['id'] ?? '',
            'cas_doruceni' => $order['pre_order'] ?? $order['delivery_time'] ?? null,
            'castka'       => ((float) ($order['price']['amount'] ?? 0)) / 100,
            'polozky'      => $items,
            'kontakt_jmeno'   => trim(($consumer['first_name'] ?? '') . ' ' . ($consumer['last_name'] ?? '')),
            'kontakt_telefon' => $consumer['phone_number'] ?? null,
            'kontakt_email'   => $consumer['email'] ?? null,
            'adresa'       => $addr['street_address'] ?? '',
            'mesto'        => $addr['city'] ?? '',
            'psc'          => $addr['postal_code'] ?? '',
            'note'         => $order['consumer_comment'] ?? '',
            'typ'          => $delivery['type'] ?? 'homedelivery', // homedelivery | takeaway | eatin
        ];
    }
}

// =============================================================
// 🟩 BOLT FOOD CLIENT
// =============================================================
// Docs: Bolt Food Restaurant Partner API (request-only access via partners.bolt.eu/food)
// Base: https://node.bolt.eu/foodora-restaurant-api (assumed — varies per region)
class BoltFood_Client {
    private string $apiKey;
    private string $storeId;
    private string $baseUrl = 'https://node.bolt.eu/restaurant-api';

    public function __construct(?string $apiKey = null, ?string $storeId = null) {
        $row = da_get_integration('bolt');
        $this->apiKey  = $apiKey  ?? ($row['api_key'] ?? '');
        $this->storeId = $storeId ?? ($row['store_id'] ?? '');
    }

    private function headers(): array {
        return [
            'X-Auth-Token' => $this->apiKey,
            'Accept'       => 'application/json',
        ];
    }

    public function test(): array {
        if (!$this->apiKey)  return ['ok' => false, 'message' => 'Chybí API klíč'];
        if (!$this->storeId) return ['ok' => false, 'message' => 'Chybí Store ID (Provider ID v Bolt portalu)'];

        $r = da_http('GET', $this->baseUrl . "/v1/providers/{$this->storeId}", $this->headers());
        if ($r['status'] === 200) return ['ok' => true, 'message' => '✅ Bolt Food connected', 'details' => $r['body']];
        if ($r['status'] === 401) return ['ok' => false, 'message' => '❌ Neplatný X-Auth-Token (401)'];
        if ($r['status'] === 0)   return ['ok' => false, 'message' => '❌ Síť: ' . ($r['error'] ?? 'unknown')];
        return ['ok' => false, 'message' => "❌ Bolt API HTTP {$r['status']}", 'details' => $r['body']];
    }

    public function pushStatus(string $externalId, string $localStav): array {
        if (!$this->apiKey) return ['ok' => false, 'message' => 'Chybí API klíč'];
        $statusMap = [
            'nova'         => 'received',
            'vyrabi_se'    => 'accepted',
            'pripravena'   => 'ready',
            'pripravene'   => 'ready',
            'expedovana'   => 'collected',
            'na_ceste'     => 'collected',
            'dorucena'     => 'delivered',
            'doruceno'     => 'delivered',
            'zrusena'      => 'cancelled',
            'zruseno'      => 'cancelled',
        ];
        if (!isset($statusMap[$localStav])) return ['ok' => false, 'message' => "Neznámý stav: $localStav"];

        $r = da_http('POST', $this->baseUrl . "/v1/orders/{$externalId}/status", $this->headers(),
            ['status' => $statusMap[$localStav]]);
        $ok = in_array($r['status'], [200, 204], true);
        return ['ok' => $ok, 'status' => $r['status'], 'body' => $r['body']];
    }

    public function syncMenu(array $items, array $categories = []): array {
        if (!$this->apiKey || !$this->storeId) return ['ok' => false, 'message' => 'Chybí credentials'];

        $boltItems = [];
        foreach ($items as $it) {
            $boltItems[] = [
                'external_id' => 'appek-' . ($it['id'] ?? ''),
                'name'        => $it['nazev'] ?? '',
                'description' => $it['popis'] ?? '',
                'price'       => (float) ($it['cena_s_dph'] ?? $it['cena'] ?? 0),
                'currency'    => 'CZK',
                'category_id' => 'appek-cat-' . ($it['kategorie_id'] ?? 0),
                'image_url'   => $it['obrazek_url'] ?? null,
                'available'   => empty($it['neaktivni']),
            ];
        }
        $boltCategories = [];
        foreach ($categories as $c) {
            $boltCategories[] = [
                'external_id' => 'appek-cat-' . ($c['id'] ?? 0),
                'name'        => $c['nazev'] ?? '',
            ];
        }
        $r = da_http('PUT', $this->baseUrl . "/v1/providers/{$this->storeId}/menu", $this->headers(),
            ['categories' => $boltCategories, 'items' => $boltItems], 30);
        $ok = in_array($r['status'], [200, 201, 202, 204], true);
        return ['ok' => $ok, 'status' => $r['status'], 'body' => $r['body'], 'items_count' => count($boltItems)];
    }

    public static function verifySignature(string $rawBody, string $signature, string $secret): bool {
        if (!$signature || !$secret) return false;
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public static function parseInboundOrder(array $payload): array {
        $order = $payload['order'] ?? $payload;
        $items = [];
        foreach (($order['items'] ?? []) as $it) {
            $items[] = [
                'nazev'    => $it['name'] ?? '',
                'mnozstvi' => (int) ($it['quantity'] ?? 1),
                'cena_ks'  => (float) ($it['price'] ?? 0),
                'external_id' => $it['external_id'] ?? null,
            ];
        }
        $customer = $order['customer'] ?? [];
        $addr = $order['delivery_address'] ?? [];
        return [
            'external_id'  => (string) ($order['id'] ?? ''),
            'cas_doruceni' => $order['scheduled_for'] ?? null,
            'castka'       => (float) ($order['total_price'] ?? 0),
            'polozky'      => $items,
            'kontakt_jmeno'   => $customer['name'] ?? '',
            'kontakt_telefon' => $customer['phone'] ?? null,
            'kontakt_email'   => $customer['email'] ?? null,
            'adresa'       => $addr['street'] ?? '',
            'mesto'        => $addr['city'] ?? '',
            'psc'          => $addr['postal_code'] ?? '',
            'note'         => $order['customer_note'] ?? '',
            'typ'          => $order['type'] ?? 'delivery',
        ];
    }
}

// =============================================================
// 🟧 DÁME JÍDLO CLIENT
// =============================================================
// Docs: Restaurátorský portál https://restaurace.damejidlo.cz (request-only)
// Base: https://api.damejidlo.cz/v1 (assumed)
class DameJidlo_Client {
    private string $apiKey;
    private string $storeId;
    private string $baseUrl = 'https://api.damejidlo.cz/v1';

    public function __construct(?string $apiKey = null, ?string $storeId = null) {
        $row = da_get_integration('dame_jidlo');
        $this->apiKey  = $apiKey  ?? ($row['api_key'] ?? '');
        $this->storeId = $storeId ?? ($row['store_id'] ?? '');
    }

    private function headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
            'Accept-Language' => 'cs',
        ];
    }

    public function test(): array {
        if (!$this->apiKey)  return ['ok' => false, 'message' => 'Chybí API klíč'];
        if (!$this->storeId) return ['ok' => false, 'message' => 'Chybí Restaurant ID'];

        $r = da_http('GET', $this->baseUrl . "/restaurants/{$this->storeId}", $this->headers());
        if ($r['status'] === 200) return ['ok' => true, 'message' => '✅ Dáme jídlo connected', 'details' => $r['body']];
        if ($r['status'] === 401) return ['ok' => false, 'message' => '❌ Neplatný Bearer token (401)'];
        if ($r['status'] === 404) return ['ok' => false, 'message' => '❌ Restaurant ID nenalezeno (404)'];
        if ($r['status'] === 0)   return ['ok' => false, 'message' => '❌ Síť: ' . ($r['error'] ?? 'unknown')];
        return ['ok' => false, 'message' => "❌ Dáme jídlo API HTTP {$r['status']}", 'details' => $r['body']];
    }

    public function pushStatus(string $externalId, string $localStav): array {
        if (!$this->apiKey) return ['ok' => false, 'message' => 'Chybí API klíč'];
        $statusMap = [
            'nova'         => 'received',
            'vyrabi_se'    => 'confirmed',
            'pripravena'   => 'ready_for_pickup',
            'pripravene'   => 'ready_for_pickup',
            'expedovana'   => 'on_the_way',
            'na_ceste'     => 'on_the_way',
            'dorucena'     => 'delivered',
            'doruceno'     => 'delivered',
            'zrusena'      => 'cancelled',
            'zruseno'      => 'cancelled',
        ];
        if (!isset($statusMap[$localStav])) return ['ok' => false, 'message' => "Neznámý stav: $localStav"];

        $r = da_http('PATCH', $this->baseUrl . "/orders/{$externalId}", $this->headers(),
            ['status' => $statusMap[$localStav]]);
        $ok = in_array($r['status'], [200, 204], true);
        return ['ok' => $ok, 'status' => $r['status'], 'body' => $r['body']];
    }

    public function syncMenu(array $items, array $categories = []): array {
        if (!$this->apiKey || !$this->storeId) return ['ok' => false, 'message' => 'Chybí credentials'];
        $dItems = [];
        foreach ($items as $it) {
            $dItems[] = [
                'external_id' => 'appek-' . ($it['id'] ?? ''),
                'name'        => $it['nazev'] ?? '',
                'description' => $it['popis'] ?? '',
                'price'       => (float) ($it['cena_s_dph'] ?? $it['cena'] ?? 0),
                'currency'    => 'CZK',
                'category_external_id' => 'appek-cat-' . ($it['kategorie_id'] ?? 0),
                'photo_url'   => $it['obrazek_url'] ?? null,
                'available'   => empty($it['neaktivni']),
            ];
        }
        $dCats = [];
        foreach ($categories as $c) {
            $dCats[] = [
                'external_id' => 'appek-cat-' . ($c['id'] ?? 0),
                'name'        => $c['nazev'] ?? '',
            ];
        }
        $r = da_http('PUT', $this->baseUrl . "/restaurants/{$this->storeId}/menu", $this->headers(),
            ['categories' => $dCats, 'items' => $dItems], 30);
        $ok = in_array($r['status'], [200, 201, 202, 204], true);
        return ['ok' => $ok, 'status' => $r['status'], 'body' => $r['body'], 'items_count' => count($dItems)];
    }

    public static function verifySignature(string $rawBody, string $signature, string $secret): bool {
        if (!$signature || !$secret) return false;
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public static function parseInboundOrder(array $payload): array {
        $order = $payload['order'] ?? $payload;
        $items = [];
        foreach (($order['items'] ?? []) as $it) {
            $items[] = [
                'nazev'    => $it['name'] ?? '',
                'mnozstvi' => (int) ($it['quantity'] ?? 1),
                'cena_ks'  => (float) ($it['unit_price'] ?? $it['price'] ?? 0),
            ];
        }
        $cust = $order['customer'] ?? [];
        $addr = $order['delivery_address'] ?? $cust['address'] ?? [];
        return [
            'external_id'  => (string) ($order['id'] ?? ''),
            'cas_doruceni' => $order['delivery_at'] ?? null,
            'castka'       => (float) ($order['total'] ?? $order['amount'] ?? 0),
            'polozky'      => $items,
            'kontakt_jmeno'   => $cust['name'] ?? '',
            'kontakt_telefon' => $cust['phone'] ?? null,
            'kontakt_email'   => $cust['email'] ?? null,
            'adresa'       => is_array($addr) ? ($addr['street'] ?? '') : (string) $addr,
            'mesto'        => is_array($addr) ? ($addr['city'] ?? '') : '',
            'psc'          => is_array($addr) ? ($addr['postal_code'] ?? '') : '',
            'note'         => $order['notes'] ?? '',
            'typ'          => $order['delivery_type'] ?? 'delivery',
        ];
    }
}

// =============================================================
// 🩷 FOODORA CLIENT (Delivery Hero CEEMEA)
// =============================================================
// Docs: vendor.delivery-hero.com partner portal
// Base: https://api-sg.deliveryhero.io/ord/v1 (region-specific)
class Foodora_Client {
    private string $apiKey;
    private string $storeId;
    private string $baseUrl = 'https://api-sg.deliveryhero.io/ord/v1';

    public function __construct(?string $apiKey = null, ?string $storeId = null) {
        $row = da_get_integration('foodora');
        $this->apiKey  = $apiKey  ?? ($row['api_key'] ?? '');
        $this->storeId = $storeId ?? ($row['store_id'] ?? '');
    }

    private function headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept'        => 'application/json',
        ];
    }

    public function test(): array {
        if (!$this->apiKey)  return ['ok' => false, 'message' => 'Chybí API klíč'];
        if (!$this->storeId) return ['ok' => false, 'message' => 'Chybí Chain ID nebo Vendor ID'];

        $r = da_http('GET', $this->baseUrl . "/vendors/{$this->storeId}", $this->headers());
        if ($r['status'] === 200) return ['ok' => true, 'message' => '✅ Foodora connected', 'details' => $r['body']];
        if ($r['status'] === 401) return ['ok' => false, 'message' => '❌ Neplatný Bearer token (401)'];
        if ($r['status'] === 404) return ['ok' => false, 'message' => '❌ Vendor ID nenalezeno (404)'];
        if ($r['status'] === 0)   return ['ok' => false, 'message' => '❌ Síť: ' . ($r['error'] ?? 'unknown')];
        return ['ok' => false, 'message' => "❌ Foodora API HTTP {$r['status']}", 'details' => $r['body']];
    }

    public function pushStatus(string $externalId, string $localStav): array {
        if (!$this->apiKey) return ['ok' => false, 'message' => 'Chybí API klíč'];
        $statusMap = [
            'nova'         => 'received',
            'vyrabi_se'    => 'accepted',
            'pripravena'   => 'ready_for_pickup',
            'pripravene'   => 'ready_for_pickup',
            'expedovana'   => 'picked_up',
            'na_ceste'     => 'picked_up',
            'dorucena'     => 'delivered',
            'doruceno'     => 'delivered',
            'zrusena'      => 'rejected',
            'zruseno'      => 'rejected',
        ];
        if (!isset($statusMap[$localStav])) return ['ok' => false, 'message' => "Neznámý stav: $localStav"];

        $r = da_http('POST', $this->baseUrl . "/orders/{$externalId}/status", $this->headers(),
            ['status' => $statusMap[$localStav]]);
        $ok = in_array($r['status'], [200, 204], true);
        return ['ok' => $ok, 'status' => $r['status'], 'body' => $r['body']];
    }

    public function syncMenu(array $items, array $categories = []): array {
        if (!$this->apiKey || !$this->storeId) return ['ok' => false, 'message' => 'Chybí credentials'];
        $fItems = [];
        foreach ($items as $it) {
            $fItems[] = [
                'product_code' => 'appek-' . ($it['id'] ?? ''),
                'name'         => $it['nazev'] ?? '',
                'description'  => $it['popis'] ?? '',
                'price'        => (float) ($it['cena_s_dph'] ?? $it['cena'] ?? 0),
                'currency'     => 'CZK',
                'category_code'=> 'appek-cat-' . ($it['kategorie_id'] ?? 0),
                'image_url'    => $it['obrazek_url'] ?? null,
                'active'       => empty($it['neaktivni']),
            ];
        }
        $fCats = [];
        foreach ($categories as $c) {
            $fCats[] = [
                'category_code' => 'appek-cat-' . ($c['id'] ?? 0),
                'name'          => $c['nazev'] ?? '',
            ];
        }
        $r = da_http('PUT', $this->baseUrl . "/vendors/{$this->storeId}/catalog", $this->headers(),
            ['categories' => $fCats, 'products' => $fItems], 30);
        $ok = in_array($r['status'], [200, 201, 202, 204], true);
        return ['ok' => $ok, 'status' => $r['status'], 'body' => $r['body'], 'items_count' => count($fItems)];
    }

    public static function verifySignature(string $rawBody, string $signature, string $secret): bool {
        if (!$signature || !$secret) return false;
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    public static function parseInboundOrder(array $payload): array {
        $order = $payload['order'] ?? $payload;
        $items = [];
        foreach (($order['products'] ?? $order['items'] ?? []) as $it) {
            $items[] = [
                'nazev'    => $it['name'] ?? '',
                'mnozstvi' => (int) ($it['quantity'] ?? 1),
                'cena_ks'  => (float) ($it['unit_price'] ?? $it['price'] ?? 0),
            ];
        }
        $cust = $order['customer'] ?? [];
        $addr = $order['delivery'] ?? [];
        return [
            'external_id'  => (string) ($order['code'] ?? $order['id'] ?? ''),
            'cas_doruceni' => $order['expected_delivery_time'] ?? null,
            'castka'       => (float) ($order['price']['total'] ?? $order['total'] ?? 0),
            'polozky'      => $items,
            'kontakt_jmeno'   => trim(($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? '')),
            'kontakt_telefon' => $cust['mobile_phone'] ?? $cust['phone'] ?? null,
            'kontakt_email'   => $cust['email'] ?? null,
            'adresa'       => $addr['address'] ?? $addr['street'] ?? '',
            'mesto'        => $addr['city'] ?? '',
            'psc'          => $addr['postcode'] ?? $addr['postal_code'] ?? '',
            'note'         => $order['comments'] ?? '',
            'typ'          => $order['order_type'] ?? 'delivery',
        ];
    }
}

// =============================================================
// 🎯 UNIFIED FACADE — pomocné funkce pro UI/webhook handlery
// =============================================================

/**
 * Vrátí klienta pro danou službu.
 */
function da_client(string $sluzba) {
    switch ($sluzba) {
        case 'wolt':       return new Wolt_Client();
        case 'bolt':       return new BoltFood_Client();
        case 'dame_jidlo': return new DameJidlo_Client();
        case 'foodora':    return new Foodora_Client();
    }
    return null;
}

/**
 * Test connectivity.
 */
function da_test(string $sluzba): array {
    $c = da_client($sluzba);
    if (!$c) return ['ok' => false, 'message' => "Neznámá služba: $sluzba"];
    return $c->test();
}

/**
 * Push status do externí služby.
 */
function da_push_status(string $sluzba, string $externalId, string $localStav): array {
    $c = da_client($sluzba);
    if (!$c) return ['ok' => false, 'message' => "Neznámá služba: $sluzba"];
    da_ensure_mapping_table();
    $r = $c->pushStatus($externalId, $localStav);
    // Update mapping
    try {
        db()->prepare("UPDATE delivery_external_orders SET stav = :s, last_status_push = NOW()
                       WHERE sluzba = :sl AND external_id = :ext")
            ->execute(['s' => $localStav, 'sl' => $sluzba, 'ext' => $externalId]);
    } catch (Throwable $e) {}
    return $r;
}

/**
 * Sync menu — vezme naše vyrobky + kategorie z DB a pošle do služby.
 */
function da_sync_menu(string $sluzba): array {
    $c = da_client($sluzba);
    if (!$c) return ['ok' => false, 'message' => "Neznámá služba: $sluzba"];

    try {
        $pdo = db();
        // Vezmi jen restaurační produkty (R-* prefix nebo restaurační kategorie)
        $items = [];
        try {
            $items = $pdo->query("
                SELECT v.id, v.nazev, v.popis, v.cena, v.cena_s_dph, v.obrazek_url, v.kategorie_id, v.neaktivni
                FROM vyrobky v
                WHERE v.cislo LIKE 'R-%' OR v.kategorie_id IN (
                    SELECT id FROM kategorie_vyrobku
                    WHERE nazev LIKE '%pizza%' OR nazev LIKE '%nápoj%' OR nazev LIKE '%jídl%'
                       OR nazev LIKE '%káva%' OR nazev LIKE '%drink%' OR nazev LIKE '%pasta%'
                       OR nazev LIKE '%burger%' OR nazev LIKE '%dezert%'
                )
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $items = []; }

        $categories = [];
        try {
            $catIds = array_unique(array_filter(array_map(fn($i) => (int)$i['kategorie_id'], $items)));
            if (!empty($catIds)) {
                $ph = implode(',', array_fill(0, count($catIds), '?'));
                $st = $pdo->prepare("SELECT id, nazev FROM kategorie_vyrobku WHERE id IN ($ph)");
                $st->execute($catIds);
                $categories = $st->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {}

        if (empty($items)) {
            return ['ok' => false, 'message' => 'Žádné restaurační výrobky v DB (potřebuji R-* prefix nebo restaurační kategorii)'];
        }
        return $c->syncMenu($items, $categories);
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Sync menu chyba: ' . $e->getMessage()];
    }
}

/**
 * Zpracuje příchozí webhook order — vytvoří objednavku v DB.
 *
 * @param string $sluzba 'wolt' | 'bolt' | 'dame_jidlo' | 'foodora'
 * @param array  $parsed  výstup z parseInboundOrder()
 * @return array ['ok' => bool, 'objednavka_id' => int|null, 'message' => string]
 */
function da_handle_inbound_order(string $sluzba, array $parsed): array {
    if (empty($parsed['external_id'])) {
        return ['ok' => false, 'message' => 'Chybí external_id v payloadu'];
    }
    da_ensure_mapping_table();
    $pdo = db();

    // Anti-duplicate: jestli už mapování existuje, vrať tu objednavku
    $st = $pdo->prepare("SELECT objednavka_id FROM delivery_external_orders WHERE sluzba = :s AND external_id = :ext");
    $st->execute(['s' => $sluzba, 'ext' => $parsed['external_id']]);
    $existingObjId = (int) ($st->fetchColumn() ?: 0);
    if ($existingObjId > 0) {
        return ['ok' => true, 'objednavka_id' => $existingObjId, 'message' => 'duplicate (already received)'];
    }

    try {
        $pdo->beginTransaction();

        // 1) Vytvoř/najdi delivery odběratele "walk-in" pro tuto službu
        $sluzbaName = ['wolt' => 'Wolt', 'bolt' => 'Bolt Food', 'dame_jidlo' => 'Dáme jídlo', 'foodora' => 'Foodora'][$sluzba] ?? $sluzba;
        $odbStmt = $pdo->prepare("SELECT id FROM odberatele WHERE nazev = :n LIMIT 1");
        $odbStmt->execute(['n' => "🍔 $sluzbaName zákazníci"]);
        $odbId = (int) ($odbStmt->fetchColumn() ?: 0);
        if (!$odbId) {
            $pdo->prepare("INSERT INTO odberatele (nazev, email, telefon, mesto) VALUES (:n, :e, :t, :m)")
                ->execute(['n' => "🍔 $sluzbaName zákazníci", 'e' => null, 't' => null, 'm' => 'Online delivery']);
            $odbId = (int) $pdo->lastInsertId();
        }

        // 2) Vytvoř objednavku
        $cislo = 'D-' . strtoupper(substr($sluzba, 0, 2)) . '-' . date('ymd') . '-' . substr(uniqid(), -5);
        $datumDodani = !empty($parsed['cas_doruceni']) ? date('Y-m-d', strtotime($parsed['cas_doruceni'])) : date('Y-m-d');

        // Detekuj sloupce v objednavky
        $cols = [];
        try {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM objednavky")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        } catch (Throwable $e) {}
        $hasPuvod = in_array('puvod', $cols, true);
        $hasMisto = in_array('misto_dodani', $cols, true);
        $hasPozn  = in_array('poznamka', $cols, true);
        $hasKont  = in_array('kontakt_jmeno', $cols, true);

        // 🆕 datum_objednani — NOT NULL bez defaultu → na strict MySQL by INSERT spadl
        //   (stejný root cause jako admin_objednavky vytvorit). Příchozí Wolt/Bolt objednávka.
        $fields  = ['cislo', 'odberatel_id', 'datum_objednani', 'datum_dodani', 'castka_celkem', 'stav'];
        $vals    = [':c', ':o', ':doo', ':dd', ':ck', ':st'];
        $params  = ['c' => $cislo, 'o' => $odbId, 'doo' => date('Y-m-d'), 'dd' => $datumDodani, 'ck' => $parsed['castka'], 'st' => 'nova'];

        if ($hasPuvod) { $fields[] = 'puvod';        $vals[] = ':pu';   $params['pu'] = $sluzba; }
        if ($hasPozn)  {
            $fields[] = 'poznamka';
            $vals[] = ':pz';
            $note = trim(($parsed['note'] ?? '') . "\n\n— Doručit na: "
                . trim($parsed['adresa'] . ', ' . $parsed['psc'] . ' ' . $parsed['mesto'], ', ')
                . ($parsed['kontakt_telefon'] ? "\n📞 " . $parsed['kontakt_telefon'] : '')
                . ($parsed['kontakt_jmeno'] ? "\n👤 " . $parsed['kontakt_jmeno'] : ''));
            $params['pz'] = $note;
        }
        if ($hasMisto) {
            $fields[] = 'misto_dodani';
            $vals[] = ':md';
            $params['md'] = trim($parsed['adresa'] . ', ' . $parsed['mesto'], ', ');
        }
        if ($hasKont && !empty($parsed['kontakt_jmeno'])) {
            $fields[] = 'kontakt_jmeno';
            $vals[] = ':kj';
            $params['kj'] = $parsed['kontakt_jmeno'];
        }

        $sql = "INSERT INTO objednavky (" . implode(',', $fields) . ") VALUES (" . implode(',', $vals) . ")";
        $pdo->prepare($sql)->execute($params);
        $objId = (int) $pdo->lastInsertId();

        // 3) Vlož položky (jen nazev + cena + mnozstvi — chytré matchování by bylo přes external_id)
        $hasPolozky = false;
        try {
            $pdo->query("SELECT 1 FROM objednavky_polozky LIMIT 1");
            $hasPolozky = true;
        } catch (Throwable $e) {}
        if ($hasPolozky) {
            $polCols = array_column($pdo->query("SHOW COLUMNS FROM objednavky_polozky")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $hasVyrobekId = in_array('vyrobek_id', $polCols, true);
            $hasNazev     = in_array('vyrobek_nazev', $polCols, true) || in_array('nazev', $polCols, true);
            $nazevCol     = in_array('vyrobek_nazev', $polCols, true) ? 'vyrobek_nazev' : 'nazev';
            $hasMnoz      = in_array('mnozstvi', $polCols, true);
            $hasCena      = in_array('cena_ks', $polCols, true) || in_array('cena', $polCols, true);
            $cenaCol      = in_array('cena_ks', $polCols, true) ? 'cena_ks' : 'cena';

            foreach ($parsed['polozky'] as $pol) {
                $pf = ['objednavka_id'];
                $pv = [':o'];
                $pp = ['o' => $objId];
                if ($hasNazev) { $pf[] = $nazevCol;  $pv[] = ':n'; $pp['n'] = $pol['nazev']; }
                if ($hasMnoz)  { $pf[] = 'mnozstvi'; $pv[] = ':m'; $pp['m'] = $pol['mnozstvi']; }
                if ($hasCena)  { $pf[] = $cenaCol;   $pv[] = ':ck';$pp['ck'] = $pol['cena_ks']; }
                if ($hasVyrobekId) {
                    // Pokusíme se najít náš vyrobek podle external_id (které jsme my poslali jako appek-{id})
                    $vyrId = null;
                    if (!empty($pol['external_id']) && str_starts_with($pol['external_id'], 'appek-')) {
                        $vyrId = (int) substr($pol['external_id'], 6);
                    }
                    $pf[] = 'vyrobek_id'; $pv[] = ':vi'; $pp['vi'] = $vyrId;
                }
                $polSql = "INSERT INTO objednavky_polozky (" . implode(',', $pf) . ") VALUES (" . implode(',', $pv) . ")";
                try { $pdo->prepare($polSql)->execute($pp); } catch (Throwable $e) { /* skip položku */ }
            }
        }

        // 4) Mapping
        $pdo->prepare("INSERT INTO delivery_external_orders
            (sluzba, external_id, objednavka_id, stav, raw_payload)
            VALUES (:s, :ext, :o, 'received', :rp)")
            ->execute([
                's' => $sluzba, 'ext' => $parsed['external_id'],
                'o' => $objId, 'rp' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
            ]);

        $pdo->commit();

        // 5) Auto-acknowledge u Woltu (musí být do ~5 minut nebo se zruší)
        if ($sluzba === 'wolt') {
            try { (new Wolt_Client())->acknowledgeOrder($parsed['external_id']); } catch (Throwable $e) {}
        }

        // 6) Push notifikace (pokud máme infrastrukturu)
        try {
            if (function_exists('push_send_to_role')) {
                push_send_to_role('admin', '🍔 Nová ' . $sluzbaName . ' objednávka',
                    "Č. $cislo · " . number_format($parsed['castka'], 0, ',', ' ') . ' Kč',
                    ['link' => "/admin/?page=objednavky&open=$objId"]);
            }
        } catch (Throwable $e) {}

        return ['ok' => true, 'objednavka_id' => $objId, 'message' => "Vytvořena objednávka $cislo"];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'message' => 'DB chyba: ' . $e->getMessage()];
    }
}
