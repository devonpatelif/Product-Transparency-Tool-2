<?php
/**
 * api-email.php — Email lead capture endpoint
 *
 * Receives an email (+ trigger context) from the email gate modal,
 * appends to leads.csv. Same origin-validation pattern as api-data.php.
 */

// ─── Load local config if present (gitignored) ─────────────
// Prefer parent dir (outside web root); fall back to legacy in-project path.
$localConf = file_exists(dirname(__DIR__) . '/config.local.php')
    ? dirname(__DIR__) . '/config.local.php'
    : __DIR__ . '/config.local.php';
if (file_exists($localConf)) require_once $localConf;

define('IFS_INTERNAL', true);
require __DIR__ . '/_ratelimit.php';
require __DIR__ . '/_check_origin.php';

// ─── Configuration ───────────────────────────────────────────
$allowedHost = 'industrialfinishes.com';
// Prefer parent dir (outside web root) so a misconfigured/missing .htaccess
// doesn't expose captured PII. Falls back to legacy in-project location only
// if a leads.csv already exists there (smooth migration: move the file once
// and writes auto-switch to the secure path on the next request).
$legacyLog   = __DIR__ . '/leads.csv';
$logFile     = file_exists($legacyLog) ? $legacyLog : (dirname(__DIR__) . '/leads.csv');

// Rate limit: legitimate users hit the email gate ~once per browser
// (it never re-prompts after capture). 3 / hour leaves headroom for
// shared NAT / coffee-shop IPs while denying scripted leads.csv pollution.
$rateLimitDir = __DIR__ . '/.ratelimit';
$rateWindow   = 3600;
$rateMax      = 3;

$serverHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$isProd     = stripos($serverHost, $allowedHost) !== false;

// ─── Headers ────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ─── Origin / Referer Check (production only) ───────────────
if ($isProd) enforceOrigin($allowedHost);

// ─── Rate limit ─────────────────────────────────────────────
$clientIp = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
enforceRateLimit($clientIp, $rateLimitDir, $rateWindow, $rateMax, 'email');

// ─── Validate Request ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required.']);
    exit;
}

$email   = isset($_POST['email'])   ? trim($_POST['email'])   : '';
$phone   = isset($_POST['phone'])   ? trim($_POST['phone'])   : '';
$trigger = isset($_POST['trigger']) ? trim($_POST['trigger']) : '';

// Either email OR phone must be supplied. Both can be present (the gate
// only sends one at a time today, but accept both gracefully if a future
// caller submits them together).
if ($email === '' && $phone === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Email or phone required.']);
    exit;
}

// Email validation — RFC-ish check via PHP's built-in filter, plus length cap.
if ($email !== '') {
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid email.']);
        exit;
    }
}

// Phone validation — strip all non-digits, then require 10-15 digits, plus
// reject obvious fake patterns and invalid NANP shapes.
//   - 10 covers NANP (US/CA), 15 is the E.164 maximum
//   - Reject letters / unexpected punctuation BEFORE counting digits, so
//     garbage like "drop table" can't sneak through if it happens to have
//     10+ embedded digits
//   - Block all-same (5555555555) and pure ascending/descending sequences
//     (1234567890, 0987654321) — the patterns people type to bypass forms
//   - For NANP-shaped numbers (10 digits, or 11 starting with country code
//     1), enforce that area code & exchange first digits are 2-9, and that
//     the area code is not the reserved fictional "555".
$phoneDigits = '';
if ($phone !== '') {
    if (strlen($phone) > 32 || !preg_match('/^[\d\s+().\-]+$/', $phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid phone.']);
        exit;
    }
    $phoneDigits = preg_replace('/\D+/', '', $phone);
    $len = strlen($phoneDigits);
    if ($len < 10 || $len > 15 || hasObviousFakePhonePattern($phoneDigits)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid phone.']);
        exit;
    }
    $nanp = null;
    if ($len === 10)                                 $nanp = $phoneDigits;
    elseif ($len === 11 && $phoneDigits[0] === '1')  $nanp = substr($phoneDigits, 1);
    if ($nanp !== null) {
        $area = substr($nanp, 0, 3);
        $exch = substr($nanp, 3, 3);
        if ($area[0] === '0' || $area[0] === '1' ||
            $exch[0] === '0' || $exch[0] === '1' ||
            $area === '555') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid phone.']);
            exit;
        }
    }
}

// Length caps + whitelist trigger to avoid log injection
$email   = substr($email, 0, 254);
$trigger = preg_replace('/[^a-z0-9_-]/i', '', substr($trigger, 0, 32));

// CSV formula-injection guard: any field starting with =, +, -, @, \t, \r is
// interpreted as a formula by Excel/Sheets/LibreOffice. Prefix '\'' to
// neutralize. fputcsv() handles commas/quotes/newlines, but does NOT do this.
// Phone is stored as the digits-only normalized form so the CSV is clean and
// dialable without re-parsing.
$row = [
    date('c'),                                       // ISO timestamp — always digit-leading
    csvSafe($email),                                 // FILTER_VALIDATE_EMAIL allows +/=/- in local-part
    csvSafe($phoneDigits),                           // digits only — phone stored normalized
    csvSafe($trigger),                               // whitelist permits leading '-'
    csvSafe(isset($_SERVER['REMOTE_ADDR'])     ? $_SERVER['REMOTE_ADDR'] : ''),
    csvSafe(isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : ''),
];

$fp = @fopen($logFile, 'a');
if ($fp) {
    if (flock($fp, LOCK_EX)) {
        if (ftell($fp) === 0) {
            fputcsv($fp, ['timestamp', 'email', 'phone', 'trigger', 'ip', 'user_agent']);
        }
        fputcsv($fp, $row);
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save.']);
    exit;
}

echo json_encode(['ok' => true]);


/**
 * Disarm CSV-formula injection. Excel/Sheets/LibreOffice parse cells whose
 * first character is =, +, -, @, \t, or \r as formulas. A leading single
 * quote tells the spreadsheet to treat the cell as literal text and is
 * itself rendered invisibly.
 */
function csvSafe($s) {
    $s = (string) $s;
    if ($s !== '' && preg_match("/^[=+\\-@\t\r]/", $s)) return "'" . $s;
    return $s;
}

/**
 * Block obvious fake phone patterns: all-same-digit (e.g. 5555555555) and
 * fully ascending/descending mod-10 sequences (1234567890, 0987654321).
 * Mirrors the client-side check so /api-email can't be bypassed by hitting
 * the endpoint directly with junk data.
 */
function hasObviousFakePhonePattern(string $d): bool {
    if (preg_match('/^(\d)\1+$/', $d)) return true;
    $len = strlen($d);
    $asc = true; $desc = true;
    for ($i = 1; $i < $len; $i++) {
        $prev = (int) $d[$i - 1];
        $curr = (int) $d[$i];
        if (($prev + 1) % 10 !== $curr) $asc  = false;
        if (($prev + 9) % 10 !== $curr) $desc = false;
        if (!$asc && !$desc) break;
    }
    return $asc || $desc;
}
