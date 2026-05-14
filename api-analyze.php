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

// PHP's max_execution_time defaults to 30s on the built-in dev server, but
// the Vision call on a multi-page PDF legitimately needs longer. Bump to
// 180s so curl_exec() can finish; cURL caps itself at 150s below so a slow
// upstream surfaces as a clean error path rather than a PHP fatal.
@set_time_limit(180);

// Fatal-error guard. If anything below trips a fatal (exec time exceeded,
// memory exhausted, parse error in a required file), PHP's default response
// is an HTML error block — which JSON.parse on the client chokes on with
// "Unexpected token '<'". Emit a JSON body instead so the front end can
// surface a meaningful message.
register_shutdown_function(function() {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    $msg = (stripos($err['message'], 'Maximum execution time') !== false
         || stripos($err['message'], 'Allowed memory size') !== false)
        ? 'Document is too large or has too many pages. Try uploading a single invoice (under ~10 pages).'
        : 'Server error while reading the document. Please try again.';
    echo json_encode(['error' => $msg]);
});

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

// Page count guard for PDFs. The tool's data model assumes one invoice per
// upload — batch scans (multiple invoices merged into one PDF) blow past
// max_tokens, mix line items across suppliers/dates, and burn vision-API
// budget. Cap at 10 pages so padded single invoices still work but batch
// dumps fail fast. Counts /Type /Page object markers in the raw bytes —
// cheap and works on flat scanned PDFs; some heavily compressed PDFs may
// undercount, but that errs toward accepting borderline files rather than
// blocking valid ones.
// Optional client-side opt-in to scan only the first N pages of an oversized
// PDF (e.g. user uploaded a batch scan and chose "Scan first 10 pages" on the
// error screen). Bounded by $maxPages below so the bypass can't exceed the
// page guard's intent.
$firstNPages = isset($_POST['firstNPages']) ? (int) $_POST['firstNPages'] : 0;
$pageLimit   = 0; // 0 = no limit; set below if firstNPages bypass is active

if ($mimeType === 'application/pdf') {
    $maxPages = 10;
    $pageCount = preg_match_all('/\/Type\s*\/Page[^s]/', $fileData);
    if ($pageCount > $maxPages) {
        if ($firstNPages > 0 && $firstNPages <= $maxPages) {
            // User explicitly opted to scan only the first N pages. Pass the
            // full PDF to Claude but instruct it to ignore pages past N below.
            // Note: input-token cost still reflects all pages (Claude reads the
            // whole document); the benefit is bounded output and reduced
            // cross-invoice contamination.
            $pageLimit = $firstNPages;
        } else {
            http_response_code(400);
            echo json_encode([
                'error'     => "This PDF has {$pageCount} pages. Please upload a single invoice (max {$maxPages} pages). For batch scans, split the file and upload one invoice at a time.",
                'pageCount' => $pageCount,
                'maxPages'  => $maxPages,
            ]);
            exit;
        }
    }
}

$base64Data  = base64_encode($fileData);

// ─── Build Claude API Request ───────────────────────────────
$prompt = 'You are analyzing a supplier pricing document (invoice, quote, or price list) for industrial paint and coatings products. Extract every line item you can find.

For each item, extract:
- partNumber: The part/item/SKU number from the part-number column ONLY (look for columns labeled Part #, Item #, SKU, Product Code, etc.). If the invoice has a separate manufacturer/brand column (often labeled H/M, Brand, Mfr, Mfg, or Vendor) printed adjacent to the part-number column with values like "3M", "PPG", "SEM", "AX", do NOT include that brand code in partNumber — extract only the part-number column value.
- description: Product name/description
- quantity: Number of units (default 1 if not shown)
- unitPrice: Per-unit price the customer pays AFTER any discount (numeric, no $ sign). Often labeled Net, Unit Price, Your Price, or Price Each.
- listPrice: Per-unit list/retail price BEFORE any discount (numeric, no $ sign). Look for columns labeled List, List Price, MSRP, Retail, or SRP. Return 0 if not on the document.
- discountPercent: Explicit discount percentage if printed on the document (numeric, no % sign; 0 if not listed). Do NOT calculate this yourself from list and net — only report it when the document shows an actual "% off" value.

Return ONLY a JSON array with no markdown formatting, no code fences, no other text:
[{"partNumber":"...","description":"...","quantity":1,"unitPrice":0.00,"listPrice":0.00,"discountPercent":0}]

If you cannot find any line items, return an empty array: []';

// Page-range cap. When the user opted in to scanning only the first N pages
// of an oversized PDF, prepend a strict instruction so Vision ignores items
// past page N. This keeps the output bounded and avoids mixing line items
// across the multiple invoices that batch-scan PDFs typically contain.
if ($pageLimit > 0) {
    $prompt = "STRICT PAGE LIMIT: This PDF has multiple pages, but you must ONLY extract line items from the FIRST {$pageLimit} PAGES. Completely ignore anything on page " . ($pageLimit + 1) . " or later — do NOT include those items in your output, even if they look like valid line items.\n\n" . $prompt;
}

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

$apiResult = postJsonToClaude($claudeUrl, $claudeHeaders, $claudeBody, 150);

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
            $claudeUrl, $claudeHeaders, $claudeBody, 150,
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
        'listPrice'       => isset($item['listPrice']) ? max(0, round((float) $item['listPrice'], 2)) : 0,
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
