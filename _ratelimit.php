<?php
/**
 * _ratelimit.php — shared per-IP sliding-window limiter for the api-* endpoints.
 *
 * Each endpoint passes its own $bucket so a busy /api-data.php can't burn the
 * /api-analyze.php (Claude Vision $$) or /api-email.php (leads.csv) budget.
 * State lives under .ratelimit/<sha1(bucket:ip)>, gated by a sibling .lock file.
 *
 * Fail-open on disk/lock errors: a transient hosting hiccup shouldn't take
 * the site down. Errors are surfaced via error_log() so fail-open isn't silent.
 *
 * Include via:
 *     define('IFS_INTERNAL', true);
 *     require __DIR__ . '/_ratelimit.php';
 */

if (!defined('IFS_INTERNAL')) {
    http_response_code(403);
    exit;
}

/**
 * Returns true if the caller is within the budget, false if exceeded.
 */
function checkRateLimit($ip, $dir, $window, $max, $bucket = '') {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    // is_writable() false-negatives on Windows directories — rely on the
    // actual write failing gracefully instead.
    if (!is_dir($dir)) {
        error_log('[ratelimit] cannot create state dir; failing open: ' . $dir);
        return true;
    }

    $file     = $dir . '/' . sha1($bucket . ':' . $ip);
    $lockFile = $file . '.lock';
    $now      = time();
    $cutoff   = $now - $window;

    $lockFp = @fopen($lockFile, 'c');
    if (!$lockFp) {
        error_log('[ratelimit] cannot open lock file; failing open: ' . $lockFile);
        return true;
    }
    if (!flock($lockFp, LOCK_EX)) {
        fclose($lockFp);
        error_log('[ratelimit] cannot acquire lock; failing open: ' . $lockFile);
        return true;
    }

    $contents = file_exists($file) ? @file_get_contents($file) : '';
    $kept = [];
    if (is_string($contents) && $contents !== '') {
        foreach (explode("\n", $contents) as $line) {
            $t = (int) trim($line);
            if ($t > $cutoff) $kept[] = $t;
        }
    }

    if (count($kept) >= $max) {
        flock($lockFp, LOCK_UN);
        fclose($lockFp);
        return false;
    }

    $kept[] = $now;
    @file_put_contents($file, implode("\n", $kept));

    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    return true;
}

/**
 * Convenience wrapper: emits 429 + Retry-After and exits when over budget.
 * Caller must have already sent its Content-Type header.
 */
function enforceRateLimit($ip, $dir, $window, $max, $bucket) {
    if (!checkRateLimit($ip, $dir, $window, $max, $bucket)) {
        http_response_code(429);
        header('Retry-After: ' . $window);
        echo json_encode(['error' => 'Too many requests. Please slow down.']);
        exit;
    }
}
