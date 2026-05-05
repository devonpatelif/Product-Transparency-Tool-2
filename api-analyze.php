<?php
/**
 * api-analyze.php — Claude Vision proxy for invoice/quote scanning
 *
 * Receives an image upload, sends it to the Claude Vision API,
 * and returns structured JSON with extracted line items.
 * Same origin-validation pattern as api-data.php.
 */

// ─── Load local config if present (gitignored) ─────────────
// Prefer parent dir (outside web root); fall back to legacy in-project path.
// Files outside the web root cannot be served as plain text if PHP processing
// breaks (e.g. Apache misconfig), keeping ANTHROPIC_API_KEY off the wire.
$localConf = file_exists(dirname(__DIR__) . '/config.local.php')
    ? dirname(__DIR__) . '/config.local.php'
    : __DIR__ . '/config.local.php';
if (file_exists($localConf)) require_once $localConf;

define('IFS_INTERNAL', true);
require __DIR__ . '/_ratelimit.php';
require __DIR__ . '/_check_origin.php';

// ─── Configuration ───────────────────────────────────────────
$allowedHost = 'industrialfinishes.com';
$apiKey      = getenv('ANTHROPIC_API_KEY');
// Sonnet 4.6 — chosen over Haiku 4.5 for accuracy on non-standard invoice
// layouts (brand-logo columns, mixed QTY formats like "20%" / "24 in",
// per-case list prices vs fractional units used). ~5× per-call cost but
// bounded by the global daily spend cap below.
$model       = 'claude-sonnet-4-6';
$maxTokens   = 4096;
$maxFileSize = 10 * 1024 * 1024; // 10 MB

// Rate limit: each call costs real money at the upstream Claude API,
// so we cap much tighter than /api-data.php. A legit user uploads
// 1–3 photos per visit; 10/min covers retries on bad images comfortably.
$rateLimitDir = __DIR__ . '/.ratelimit';
$rateWindow   = 60;
$rateMax      = 10;

// Global daily spend cap. Per-IP rate limits are bypassable with proxy
// rotation, so this is the financial backstop: a hard ceiling on total
// Vision-API calls per UTC day across ALL clients. Sized for ~100 legit
// visitors/day at ~3 scans each, with headroom. Tune to your budget.
$dailySpendMax  = 500;
$dailySpendFile = $rateLimitDir . '/spend-analyze-daily.txt';

// Detect production vs local development
$serverHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$isProd     = stripos($serverHost, $allowedHost) !== false;

// ─── CORS / Headers ─────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ─── Origin / Referer Check (production only) ───────────────
if ($isProd) enforceOrigin($allowedHost);

// ─── Rate limit ─────────────────────────────────────────────
// Separate bucket from /api-data.php so a busy catalog session
// doesn't burn the (much smaller) Vision-API budget.
$clientIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
enforceRateLimit($clientIp, $rateLimitDir, $rateWindow, $rateMax, 'analyze');

// ─── Validate Request ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}

if (empty($apiKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'API key not configured.']);
    exit;
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'No image uploaded or upload error.']);
    exit;
}

$file = $_FILES['image'];

if ($file['size'] > $maxFileSize) {
    http_response_code(400);
    echo json_encode(['error' => 'Image too large. Maximum 10 MB.']);
    exit;
}

// ─── Read & Encode File ─────────────────────────────────────
$allowedTypes = [
    'image/jpeg'      => 'image',
    'image/png'       => 'image',
    'image/gif'       => 'image',
    'image/webp'      => 'image',
    'application/pdf' => 'document',
];

$mimeType = detectMimeType($file['tmp_name']);

if ($mimeType === null || !isset($allowedTypes[$mimeType])) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported file type. Use JPEG, PNG, GIF, WebP, or PDF.']);
    exit;
}

$fileKind    = $allowedTypes[$mimeType]; // 'image' or 'document'
$fileData    = file_get_contents($file['tmp_name']);
$base64Data  = base64_encode($fileData);

// ─── Build Claude API Request ───────────────────────────────
$prompt = 'You are analyzing a supplier pricing document (invoice, quote, or price list) for industrial paint and coatings products. Extract every line item you can find.

For each item, extract:
- partNumber: The part/item/SKU number (look for columns labeled Part #, Item #, SKU, Product Code, etc.)
- description: Product name/description
- quantity: Number of units (default 1 if not shown)
- unitPrice: Price per unit the customer pays (numeric, no $ sign)
- discountPercent: Discount percentage if shown (numeric, no % sign; 0 if not listed)

Return ONLY a JSON array with no markdown formatting, no code fences, no other text:
[{"partNumber":"...","description":"...","quantity":1,"unitPrice":0.00,"discountPercent":0}]

If you cannot find any line items, return an empty array: []';

// Build the file content block — 'image' or 'document' type
if ($fileKind === 'document') {
    $fileBlock = [
        'type'   => 'document',
        'source' => [
            'type'         => 'base64',
            'media_type'   => $mimeType,
            'data'         => $base64Data,
        ],
    ];
} else {
    $fileBlock = [
        'type'   => 'image',
        'source' => [
            'type'         => 'base64',
            'media_type'   => $mimeType,
            'data'         => $base64Data,
        ],
    ];
}

$payload = [
    'model'      => $model,
    'max_tokens' => $maxTokens,
    'messages'   => [
        [
            'role'    => 'user',
            'content' => [
                $fileBlock,
                [
                    'type' => 'text',
                    'text' => $prompt,
                ],
            ],
        ],
    ],
];

// ─── Global daily spend cap ─────────────────────────────────
// Increment+check happens AFTER all request validation but BEFORE the
// upstream call, so malformed requests don't consume budget but every
// request that would actually pay Anthropic does count.
if (!incrementDailySpend($dailySpendFile, $dailySpendMax)) {
    http_response_code(503);
    header('Retry-After: 86400');
    error_log('[api-analyze] daily spend cap reached (' . $dailySpendMax . ')');
    echo json_encode(['error' => 'AI scan capacity reached for today. Please try again tomorrow.']);
    exit;
}

// ─── Call Claude API ────────────────────────────────────────
$claudeUrl     = 'https://api.anthropic.com/v1/messages';
$claudeHeaders = [
    'Content-Type: application/json',
    'x-api-key: ' . $apiKey,
    'anthropic-version: 2023-06-01',
];
$claudeBody    = json_encode($payload);

$apiResult = postJsonToClaude($claudeUrl, $claudeHeaders, $claudeBody, 60);

// On DNS resolution failure (errno 6 = CURLE_COULDNT_RESOLVE_HOST), bypass the
// system resolver via DNS-over-HTTPS and retry with the IP pinned. Some
// corporate DNS servers return only AAAA (or nothing) for api.anthropic.com,
// breaking IPv4-only clients. This path keeps the tool working for users
// behind those resolvers without requiring hosts-file edits or admin access.
if ($apiResult['error'] && (int)($apiResult['errno'] ?? 0) === 6) {
    $ip = resolveViaDoH('api.anthropic.com');
    if ($ip) {
        error_log("[api-analyze] local DNS failed for api.anthropic.com; retrying via DoH-resolved IP $ip");
        $apiResult = postJsonToClaude(
            $claudeUrl, $claudeHeaders, $claudeBody, 60,
            ['api.anthropic.com:443:' . $ip]
        );
    }
}

if ($apiResult['error']) {
    error_log("[api-analyze] reach-AI failed: " . ($apiResult['debug'] ?? '(no debug info)'));
    http_response_code(502);
    $errPayload = ['error' => 'Failed to reach AI service.'];
    // Surface the actual cURL/stream error in dev so future failures don't
    // require digging through PHP's error log to diagnose.
    if (!$isProd && !empty($apiResult['debug'])) {
        $errPayload['debug'] = $apiResult['debug'];
    }
    echo json_encode($errPayload);
    exit;
}
$response = $apiResult['body'];
$httpCode = $apiResult['status'];

if ($httpCode !== 200) {
    http_response_code(502);
    $err = json_decode($response, true);
    $msg = isset($err['error']['message']) ? $err['error']['message'] : 'AI service error.';
    echo json_encode(['error' => $msg]);
    exit;
}

// ─── Parse Claude Response ──────────────────────────────────
$result = json_decode($response, true);

if (!isset($result['content'][0]['text'])) {
    http_response_code(502);
    echo json_encode(['error' => 'Unexpected AI response format.']);
    exit;
}

$text = trim($result['content'][0]['text']);

// Strip markdown code fences if present
$text = preg_replace('/^```(?:json)?\s*/i', '', $text);
$text = preg_replace('/\s*```$/', '', $text);
$text = trim($text);

$items = json_decode($text, true);

if (!is_array($items)) {
    http_response_code(502);
    echo json_encode(['error' => 'AI returned invalid data. Please try again with a clearer image.']);
    exit;
}

// Sanitize output
$clean = [];
foreach ($items as $item) {
    $clean[] = [
        'partNumber'      => isset($item['partNumber']) ? (string) $item['partNumber'] : '',
        'description'     => isset($item['description']) ? (string) $item['description'] : '',
        'quantity'        => isset($item['quantity']) ? max(1, (int) $item['quantity']) : 1,
        'unitPrice'       => isset($item['unitPrice']) ? round((float) $item['unitPrice'], 2) : 0,
        'discountPercent' => isset($item['discountPercent']) ? min(99, max(0, (int) $item['discountPercent'])) : 0,
    ];
}

echo json_encode(['items' => $clean]);


/**
 * POST a JSON body to a URL. Uses cURL when available (production hosts),
 * falls back to PHP's stream wrapper when cURL is missing (Windows dev).
 * Returns ['status' => int, 'body' => string|null, 'error' => bool].
 */
function postJsonToClaude($url, array $headers, $body, $timeout, $resolveOverride = null) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
        ];
        // CURLOPT_RESOLVE pins host:port → IP without changing SNI/Host
        // headers, so TLS verification still works. Used by the DoH retry
        // path when system DNS is broken for api.anthropic.com.
        if ($resolveOverride) $opts[CURLOPT_RESOLVE] = $resolveOverride;
        curl_setopt_array($ch, $opts);
        $resp   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        $errno  = curl_errno($ch);
        return [
            'status' => $status,
            'body'   => $resp === false ? null : $resp,
            'error'  => $err !== '',
            'errno'  => $errno,
            'debug'  => $err !== '' ? 'curl(' . $errno . '): ' . $err : '',
        ];
    }

    // Stream fallback. ignore_errors lets us read the body on 4xx/5xx.
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers) . "\r\n",
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }
    $err = '';
    if ($resp === false) {
        $last = error_get_last();
        $err = $last && isset($last['message']) ? 'stream: ' . $last['message'] : 'stream: unknown';
    }
    return [
        'status' => $status,
        'body'   => $resp === false ? null : $resp,
        'error'  => $resp === false,
        'errno'  => 0,
        'debug'  => $err,
    ];
}

/**
 * Resolves a hostname's A record via DNS-over-HTTPS (Google's resolver).
 * Returns the first IPv4 address as a string, or null on failure.
 *
 * Used as a fallback when the system resolver fails to return an A record
 * (observed: corporate DNS returning AAAA-only for api.anthropic.com).
 * dns.google itself is pinned to its known anycast IPs (8.8.8.8 / 8.8.4.4)
 * so a broken local resolver can't break the bypass too — SNI still says
 * "dns.google" via CURLOPT_RESOLVE so TLS verifies cleanly.
 */
function resolveViaDoH($host, $timeout = 8) {
    if (!function_exists('curl_init')) return null;
    $url = 'https://dns.google/resolve?name=' . urlencode($host) . '&type=A';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_RESOLVE        => [
            'dns.google:443:8.8.8.8',
            'dns.google:443:8.8.4.4',
        ],
        CURLOPT_HTTPHEADER     => ['Accept: application/dns-json'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    if ($resp === false || $err !== '') {
        error_log("[api-analyze] DoH resolve failed for $host: $err");
        return null;
    }
    $data = json_decode($resp, true);
    if (!isset($data['Answer']) || !is_array($data['Answer'])) return null;
    foreach ($data['Answer'] as $ans) {
        // type 1 = A record (IPv4)
        if (isset($ans['type']) && (int)$ans['type'] === 1 && !empty($ans['data'])) {
            return $ans['data'];
        }
    }
    return null;
}

/**
 * Atomic per-day counter for the global Anthropic-API spend cap. Returns
 * true if the request is within budget (and was counted), false if the
 * cap is already hit. The counter resets at UTC midnight via the date
 * prefix; no cron needed.
 *
 * Fail-open on filesystem errors: a transient hosting hiccup shouldn't
 * take the scan feature down, and per-IP rate-limit + origin-check still
 * provide first-line defense. Errors are surfaced via error_log().
 */
function incrementDailySpend($file, $max) {
    $today = gmdate('Y-m-d');
    $dir   = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    if (!is_dir($dir)) {
        error_log('[api-analyze] spend-cap dir missing; failing open: ' . $dir);
        return true;
    }

    $lockFile = $file . '.lock';
    $fp = @fopen($lockFile, 'c');
    if (!$fp) {
        error_log('[api-analyze] spend-cap lock open failed; failing open');
        return true;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        error_log('[api-analyze] spend-cap lock acquire failed; failing open');
        return true;
    }

    $count = 0;
    $contents = file_exists($file) ? @file_get_contents($file) : '';
    if (is_string($contents) && preg_match('/^(\d{4}-\d{2}-\d{2}):(\d+)\s*$/', $contents, $m)) {
        if ($m[1] === $today) $count = (int) $m[2];
    }

    if ($count >= $max) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    @file_put_contents($file, $today . ':' . ($count + 1));
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

/**
 * Detect MIME type by sniffing the file's magic bytes. Avoids the
 * `fileinfo` PHP extension, which is disabled on some Windows builds.
 * Returns null if the bytes don't match any of the types we support.
 */
function detectMimeType($path) {
    $fp = @fopen($path, 'rb');
    if (!$fp) return null;
    $bytes = fread($fp, 12);
    fclose($fp);
    if ($bytes === false || $bytes === '') return null;

    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0)                                    return 'image/jpeg';
    if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0)                               return 'image/png';
    if (strncmp($bytes, 'GIF87a', 6) === 0 || strncmp($bytes, 'GIF89a', 6) === 0)    return 'image/gif';
    if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP')         return 'image/webp';
    if (strncmp($bytes, '%PDF',  4) === 0)                                           return 'application/pdf';
    return null;
}
