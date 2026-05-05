<?php
/**
 * _check_origin.php — strict Origin/Referer host validation.
 *
 * Replaces the previous `stripos($header, $allowedHost) !== false` check,
 * which was a substring match — `https://industrialfinishes.com.attacker.com`
 * and `https://attacker.com/?industrialfinishes.com` both passed.
 *
 * NOTE: Origin and Referer are forgeable by non-browser clients (curl, scripts,
 * bots) — this only stops accidental cross-site browser fetches. It is NOT a
 * defense against deliberate API abuse. Combine with rate limits + (eventually)
 * a server-issued nonce for anything that costs money or writes user data.
 *
 * Include via:
 *     define('IFS_INTERNAL', true);
 *     require __DIR__ . '/_check_origin.php';
 */

if (!defined('IFS_INTERNAL')) {
    http_response_code(403);
    exit;
}

/**
 * Returns true if Origin or Referer (whichever is present) belongs to
 * $allowedHost or a direct subdomain (e.g. app.industrialfinishes.com).
 *
 * Semantic mirrors the prior code: at least one present header must match.
 * If both headers are missing, the check fails — production callers must
 * supply at least one. Same-origin browser GETs always send Referer.
 */
function checkOrigin($allowedHost) {
    $origin  = isset($_SERVER['HTTP_ORIGIN'])  ? $_SERVER['HTTP_ORIGIN']  : '';
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';

    $matched = false;
    if ($origin !== '') {
        $h = parse_url($origin, PHP_URL_HOST);
        if (is_string($h) && hostMatches($h, $allowedHost)) $matched = true;
    }
    if (!$matched && $referer !== '') {
        $h = parse_url($referer, PHP_URL_HOST);
        if (is_string($h) && hostMatches($h, $allowedHost)) $matched = true;
    }
    return $matched;
}

/**
 * Strict host comparison: exact match OR direct subdomain.
 * `industrialfinishes.com` and `app.industrialfinishes.com` pass;
 * `industrialfinishes.com.attacker.com` and `evilindustrialfinishes.com`
 * do NOT.
 */
function hostMatches($host, $allowedHost) {
    $host        = strtolower($host);
    $allowedHost = strtolower($allowedHost);
    if ($host === $allowedHost) return true;

    $suffix = '.' . $allowedHost;
    $sLen   = strlen($suffix);
    if (strlen($host) <= $sLen) return false;
    return substr($host, -$sLen) === $suffix;
}

/**
 * Convenience wrapper: emits 403 + JSON error and exits when the check
 * fails. Caller must have already sent its Content-Type header.
 */
function enforceOrigin($allowedHost) {
    if (!checkOrigin($allowedHost)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied.']);
        exit;
    }
}
