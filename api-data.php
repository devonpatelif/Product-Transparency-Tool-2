<?php
/**
 * api-data.php — Server-side search/filter for the product catalog.
 *
 * The full catalog is NEVER shipped to the browser. The front end queries
 * this endpoint as the user types/filters/paginates, and we return only
 * the rows matching the current view.
 *
 * Modes:
 *   GET  ?meta=1                      → vendors, categories, totals (no rows)
 *   GET  ?q=&category=&vendor=
 *        &sort=&dir=&limit=&offset=   → paginated search results
 *   POST ?op=match  + JSON body       → fuzzy-match scanner items by part #
 *
 * Origin-validated (production only) and per-IP rate-limited.
 */

// PHP arrays carry ~6× the size of the underlying JSON. Decoding the
// 8 MB catalog peaks at ~150 MB before normalization frees the source.
// 256 MB gives comfortable headroom; the cache hit path stays cheap.
ini_set('memory_limit', '256M');

define('IFS_INTERNAL', true);
require __DIR__ . '/_ratelimit.php';
require __DIR__ . '/_check_origin.php';

// ─── Configuration ───────────────────────────────────────────
$jsonFile      = __DIR__ . '/IVData.json';
$cacheFile     = __DIR__ . '/.cache/IVData.normalized.php';
$rateLimitDir  = __DIR__ . '/.ratelimit';
$allowedHost   = 'industrialfinishes.com';
$rateWindow    = 60;     // seconds
$rateMax       = 120;    // requests per window per IP
$maxLimit      = 100;    // max rows per page
$matchMaxItems = 50;     // max items per scanner-match request

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
enforceRateLimit($clientIp, $rateLimitDir, $rateWindow, $rateMax, 'data');

// ─── Load (cached) normalized catalog ───────────────────────
$catalog = loadCatalog($jsonFile, $cacheFile);
if ($catalog === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Catalog unavailable.']);
    exit;
}

// ─── Route ──────────────────────────────────────────────────
$op = isset($_GET['op']) ? $_GET['op'] : '';

if (isset($_GET['meta'])) {
    echo json_encode(buildMeta($catalog));
    exit;
}

if ($op === 'match') {
    handleMatch($catalog, $matchMaxItems);
    exit;
}

echo json_encode(handleSearch($catalog, $maxLimit));
exit;


// ────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────

/**
 * Load the catalog as a normalized array of rows. Caches the parsed +
 * normalized version to a PHP file so opcache can serve subsequent
 * requests without re-parsing the 8 MB JSON. Falls back to APCu if
 * available, then to plain decode if neither cache works.
 */
function loadCatalog($jsonFile, $cacheFile) {
    $sourceMtime = @filemtime($jsonFile);
    if ($sourceMtime === false) return null;

    // 1. APCu in-memory cache (best case)
    if (function_exists('apcu_fetch')) {
        $cached = apcu_fetch('ifs_catalog_v1');
        if (is_array($cached) && isset($cached['mtime']) && $cached['mtime'] >= $sourceMtime) {
            return $cached['data'];
        }
    }

    // 2. File-based cache (serialized PHP — ~10× faster than re-parsing JSON)
    if (file_exists($cacheFile) && filemtime($cacheFile) >= $sourceMtime) {
        $blob = @file_get_contents($cacheFile);
        if ($blob !== false) {
            // allowed_classes => false: refuse to instantiate any objects.
            // Cache holds plain arrays only, so loss-free. Closes PHP-object-
            // injection RCE if .cache/ ever becomes attacker-writable.
            $cached = @unserialize($blob, ['allowed_classes' => false]);
            if (is_array($cached)) {
                if (function_exists('apcu_store')) {
                    apcu_store('ifs_catalog_v1', ['mtime' => $sourceMtime, 'data' => $cached], 3600);
                }
                return $cached;
            }
        }
    }

    // 3. Build from source (cold path — ~1 sec for the full 8 MB catalog)
    $raw = @file_get_contents($jsonFile);
    if ($raw === false) return null;
    $decoded = json_decode($raw, true);
    unset($raw);                       // free 8 MB string ASAP
    if ($decoded === null) return null;

    $normalized = normalizeCatalog($decoded);
    unset($decoded);                   // free decoded source after normalize
    if (count($normalized) === 0) return null;

    // Best-effort cache write (atomic rename, serialize is ~6× more memory-
    // efficient than var_export and keeps PHP from materializing a huge
    // intermediate string)
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
        @file_put_contents($cacheDir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    // Skip is_writable() check — it false-negatives on Windows. Just attempt
    // the write; failure is handled by the @ suppression and the fallback path.
    if (is_dir($cacheDir)) {
        $tmp = $cacheFile . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, serialize($normalized)) !== false) {
            @rename($tmp, $cacheFile);
        }
    }

    if (function_exists('apcu_store')) {
        apcu_store('ifs_catalog_v1', ['mtime' => $sourceMtime, 'data' => $normalized], 3600);
    }

    return $normalized;
}

/**
 * Convert raw decoded JSON into a clean array of records with stable
 * field names. Handles flat arrays, Power BI wrapper objects, and
 * "TableName[field]" key-prefix variants. Drops rows with price ≤ 0.
 */
function normalizeCatalog(&$decoded) {
    // Locate the actual rows array, regardless of which Power BI export
    // shape we're handed. We deliberately take a reference into $decoded
    // so the caller can free it after we finish.
    $arr = null;
    if (isset($decoded['firstTableRows']) && is_array($decoded['firstTableRows'])) {
        $arr = &$decoded['firstTableRows'];
    } elseif (isset($decoded['results'][0]['tables'][0]['rows'])) {
        $arr = &$decoded['results'][0]['tables'][0]['rows'];
    } elseif (isset($decoded['tables'][0]['rows'])) {
        $arr = &$decoded['tables'][0]['rows'];
    } elseif (isset($decoded[0]) && is_array($decoded[0])) {
        $arr = &$decoded;
    } elseif (isset($decoded['data'])) {
        $arr = &$decoded['data'];
    } elseif (isset($decoded['rows'])) {
        $arr = &$decoded['rows'];
    } elseif (isset($decoded['value'])) {
        $arr = &$decoded['value'];
    }

    if (!is_array($arr) || count($arr) === 0) return [];

    // Detect Power BI "TableName[field]" key prefix from the first row.
    $firstKey = '';
    foreach ($arr[0] as $k => $_) { $firstKey = $k; break; }
    $needsStrip = (strpos($firstKey, '[') !== false);

    // Build keyMap once from a stripped sample (so detectKeys's name
    // matching works regardless of whether the prefix is present).
    $sample = $arr[0];
    if ($needsStrip) {
        $stripped = [];
        foreach ($sample as $k => $v) {
            if (preg_match('/\[(.+)\]$/', $k, $m)) $stripped[$m[1]] = $v;
            else $stripped[$k] = $v;
        }
        $sample = $stripped;
    }
    $keyMap = detectKeys($sample);
    unset($sample, $stripped);

    // For prefixed inputs, precompute the lookup keys once instead of
    // running preg_match on every field of every row. Map each
    // normalized field name (e.g. 'desc') to the actual key we'll
    // index $row by — either the bare name or the bracketed form.
    $resolved = [];
    if ($needsStrip) {
        foreach ($keyMap as $field => $bareKey) {
            $resolved[$field] = $bareKey === null ? null : null;
        }
        // Find which prefixed key each bare key came from
        foreach ($arr[0] as $k => $_) {
            if (preg_match('/\[(.+)\]$/', $k, $m)) {
                foreach ($keyMap as $field => $bareKey) {
                    if ($bareKey !== null && $m[1] === $bareKey) {
                        $resolved[$field] = $k;
                    }
                }
            }
        }
    } else {
        foreach ($keyMap as $field => $bareKey) {
            $resolved[$field] = $bareKey;
        }
    }

    $catMap = categoryMap();
    $out = [];

    // Stream: pull each row out of the source array as we normalize it
    // so peak memory is roughly source_size + output_size, not 2× both.
    foreach ($arr as $idx => $row) {
        $priceKey = $resolved['price'];
        $price = ($priceKey !== null && isset($row[$priceKey])) ? (float) $row[$priceKey] : 0;
        if ($price <= 0) {
            unset($arr[$idx]);
            continue;
        }

        $catKey = $resolved['category'];
        $cat = ($catKey !== null && isset($row[$catKey])) ? (string) $row[$catKey] : '';

        $catNameKey = $resolved['catName'];
        if ($catNameKey !== null && !empty($row[$catNameKey])) {
            $catName = (string) $row[$catNameKey];
        } elseif ($cat !== '' && isset($catMap[$cat])) {
            $catName = $catMap[$cat];
        } else {
            $catName = $cat;
        }

        $out[] = [
            'desc'     => fieldStr($row, $resolved['desc']),
            'part'     => fieldStr($row, $resolved['part']),
            'size'     => fieldStr($row, $resolved['size']),
            'price'    => $price,
            'vendor'   => fieldStr($row, $resolved['vendor']),
            'category' => $cat,
            'catName'  => $catName,
            'plDesc'   => fieldStr($row, $resolved['plDesc']),
            'splDesc'  => fieldStr($row, $resolved['splDesc']),
            'decGal'   => fieldFloat($row, $resolved['decGal']),
            'grit'     => fieldInt($row, $resolved['grit']),
            'vpn'      => fieldStr($row, $resolved['vpn']),
        ];
        unset($arr[$idx]);
    }
    return $out;
}

function fieldStr($row, $key) {
    if ($key === null || !isset($row[$key])) return '';
    return (string) $row[$key];
}
function fieldFloat($row, $key) {
    if ($key === null || !isset($row[$key])) return 0.0;
    return (float) $row[$key];
}
function fieldInt($row, $key) {
    if ($key === null || !isset($row[$key])) return 0;
    return (int) $row[$key];
}

/**
 * Auto-detect JSON field names (mirrors detectKeys() in index.html).
 */
function detectKeys($sample) {
    $lk = [];
    foreach (array_keys($sample) as $k) {
        $lk[strtolower(preg_replace('/[\s_\-\/]/', '', $k))] = $k;
    }
    $find = function() use ($sample, $lk) {
        $candidates = func_get_args();
        foreach ($candidates as $c) {
            if (array_key_exists($c, $sample)) return $c;
        }
        foreach ($candidates as $c) {
            $norm = strtolower(preg_replace('/[\s_\-\/]/', '', $c));
            if (isset($lk[$norm])) return $lk[$norm];
        }
        return null;
    };
    return [
        'desc'     => $find('part_nbr/description','part_nbr_description','description','Description','ProductDescription'),
        'part'     => $find('part_nbr','PartNumber','Part Number','partNbr'),
        'size'     => $find('size','Size'),
        'price'    => $find('std_price','StdPrice','Price','stdprice','StandardPrice'),
        'vendor'   => $find('vendor_name','VendorName','Vendor','vendorname'),
        'category' => $find('category','Category'),
        'catName'  => $find('Category Name','CategoryName','category_name'),
        'plDesc'   => $find('Product_line_desc','pl_desc','ProductLine','Product Line','productlinedesc'),
        'splDesc'  => $find('spl_desc','SubProductLine','SubProductline','splDesc','spldesc'),
        'decGal'   => $find('decimal_gallons','DecimalGallons','decimalgallons'),
        'grit'     => $find('grit','Grit'),
        'vpn'      => $find('vendor_part_nbr','VendorPartNumber','vendorpartnbr'),
    ];
}

function categoryMap() {
    return [
        'PNT' => 'Paints & Coatings', 'MNT' => 'Maintenance', 'PSM' => 'Paint Sundries',
        'SAF' => 'Safety', 'EQ' => 'Equipment', 'ITM' => 'Industrial Items',
        'BSM' => 'Body Shop', 'SMT' => 'Sheet Metal & Tools', 'DET' => 'Detailing',
        'SUP' => 'Supplies', 'COM' => 'Commercial', 'LIT' => 'Literature',
        'SVC' => 'Service', 'OFF' => 'Office', 'TRN' => 'Training', 'OTHER' => 'Other',
    ];
}

/**
 * Returns just the metadata the front end needs to populate filter
 * dropdowns and the hero stat cards. Does NOT include any product rows.
 */
function buildMeta($catalog) {
    $cats = [];
    $vendors = [];
    foreach ($catalog as $r) {
        $c = $r['category'];
        if ($c !== '' && !isset($cats[$c])) {
            $cats[$c] = $r['catName'] !== '' ? $r['catName'] : $c;
        }
        $v = $r['vendor'];
        if ($v !== '' && !isset($vendors[$v])) {
            $vendors[$v] = true;
        }
    }
    $vendorList = array_keys($vendors);
    sort($vendorList);
    return [
        'total'      => count($catalog),
        'brands'     => count($vendors),
        'categories' => $cats,
        'vendors'    => $vendorList,
    ];
}

/**
 * Search/filter/sort/paginate. Returns at most $maxLimit rows.
 */
function handleSearch($catalog, $maxLimit) {
    $q       = trim((string)(isset($_GET['q']) ? $_GET['q'] : ''));
    $cat     = trim((string)(isset($_GET['category']) ? $_GET['category'] : ''));
    $vendor  = trim((string)(isset($_GET['vendor']) ? $_GET['vendor'] : ''));
    $sort    = (string)(isset($_GET['sort']) ? $_GET['sort'] : '');
    $dir     = (string)(isset($_GET['dir']) ? $_GET['dir'] : 'asc');
    $limit   = max(1, min($maxLimit, (int)(isset($_GET['limit']) ? $_GET['limit'] : 25)));
    $offset  = max(0, (int)(isset($_GET['offset']) ? $_GET['offset'] : 0));

    // Cap query length to prevent abuse
    if (strlen($q) > 200) $q = substr($q, 0, 200);

    $qLow = strtolower($q);

    $matched = [];
    foreach ($catalog as $r) {
        if ($cat !== ''    && $r['category'] !== $cat)    continue;
        if ($vendor !== '' && $r['vendor']   !== $vendor) continue;
        if ($qLow !== '') {
            $hay = strtolower(
                $r['desc'] . ' ' . $r['part'] . ' ' . $r['size'] . ' ' .
                $r['vendor'] . ' ' . $r['plDesc'] . ' ' . $r['splDesc'] . ' ' . $r['vpn']
            );
            if (strpos($hay, $qLow) === false) continue;
        }
        $matched[] = $r;
    }

    $allowedSorts = ['desc', 'part', 'size', 'vendor', 'price'];
    if (in_array($sort, $allowedSorts, true)) {
        $asc = ($dir !== 'desc');
        usort($matched, function($a, $b) use ($sort, $asc) {
            $x = $a[$sort]; $y = $b[$sort];
            if (is_string($x)) { $x = strtolower($x); $y = strtolower((string)$y); }
            if ($x == $y) return 0;
            $cmp = ($x < $y) ? -1 : 1;
            return $asc ? $cmp : -$cmp;
        });
    }

    $total = count($matched);
    if ($offset >= $total) {
        return ['items' => [], 'total' => $total, 'offset' => $offset, 'limit' => $limit];
    }
    $page = array_slice($matched, $offset, $limit);
    return ['items' => $page, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
}

/**
 * Match scanner-extracted items against the catalog. Strict matching:
 * exact normalized part number, with a high-confidence description-keyword
 * fallback for cases where the AI mis-OCR'd the part number but got the
 * description right. False positives in this code mislead customers, so
 * we err on the side of "Not Found" rather than guessing.
 *
 * Removed (was a false-positive factory): substring/partial part-number
 * matching. With short catalog parts (e.g. "100"), any longer extracted
 * part containing those characters (e.g. "MB100") would falsely match.
 */
function handleMatch($catalog, $maxItems) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST required.']);
        return;
    }

    $body = file_get_contents('php://input');
    $payload = json_decode($body, true);
    if (!is_array($payload) || !isset($payload['items']) || !is_array($payload['items'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing items array.']);
        return;
    }

    $items = array_slice($payload['items'], 0, $maxItems);

    // Build part-number index over the catalog (cheap on every request — ~25k entries).
    // Also build a prefix index for sized-variant matching: an invoice that lists the
    // base part "WB2040" should still match catalog entries "WB2040-3.5L" and
    // "WB2040-1L". Indexed by the alphanumeric run before the FIRST separator, with a
    // 4-char minimum to avoid spurious matches on short stems (e.g. "100" → "100-X").
    $partIndex   = [];
    $prefixIndex = [];
    foreach ($catalog as $i => $r) {
        $norm = normalizePartNum($r['part']);
        if ($norm !== '') {
            if (!isset($partIndex[$norm])) $partIndex[$norm] = [];
            $partIndex[$norm][] = $i;

            $orig = strtoupper((string) $r['part']);
            if (preg_match('/^([A-Z0-9]+)[\-\.\/]/', $orig, $m) && strlen($m[1]) >= 4) {
                $prefix = $m[1];
                if (!isset($prefixIndex[$prefix])) $prefixIndex[$prefix] = [];
                $prefixIndex[$prefix][] = $i;
            }
        }
    }

    $results = [];
    $descCap = min(5000, count($catalog));

    // Cap how many ambiguous matches we surface per item. A part number
    // mapping to >8 catalog entries is unusual; presenting that many in a
    // dropdown is also a UX problem. Most legit cases will be 2–3.
    $maxOptions = 8;

    foreach ($items as $ext) {
        $extPart = isset($ext['partNumber']) ? (string) $ext['partNumber'] : '';
        $extDesc = isset($ext['description']) ? (string) $ext['description'] : '';
        $normPart = normalizePartNum($extPart);

        // 1. Exact part-number match. When a single part number maps to
        //    multiple catalog entries (different vendors, sizes, etc.) we
        //    return ALL of them so the user can disambiguate in the UI.
        $matchOptions = [];
        if ($normPart !== '' && isset($partIndex[$normPart])) {
            foreach (array_slice($partIndex[$normPart], 0, $maxOptions) as $idx) {
                $matchOptions[] = $catalog[$idx];
            }
        }

        // 1b. Prefix-of-catalog fallback. If the invoice listed only the base
        //     part ("WB2040") but the catalog only carries sized variants
        //     ("WB2040-3.5L", "WB2040-1L"), surface all of them as ambiguous
        //     options. Directional (extracted shorter, must be the alphanumeric
        //     prefix that ends at a separator), so this does NOT reintroduce the
        //     bidirectional substring matching that previously caused false
        //     positives like catalog "100" matching extracted "MB100".
        if (count($matchOptions) === 0 && $normPart !== '' && isset($prefixIndex[$normPart])) {
            foreach (array_slice($prefixIndex[$normPart], 0, $maxOptions) as $idx) {
                $matchOptions[] = $catalog[$idx];
            }
        }

        // 2. Description-keyword fallback — only when the AI gave us a
        //    description AND we can find enough product-identifying word
        //    overlaps. Returns at most one option (the highest-scoring
        //    catalog row).
        //
        //    Two guards against false positives (e.g. "MIRLON SCUFF PAD VERY
        //    FINE RED" matching "FIBRATEX SCUFF PAD VERY FINE MAROON" via
        //    the generic words alone):
        //      a. Stop-word list strips noisy descriptors that appear across
        //         many SKUs and don't identify a product (sizes, colors-only,
        //         abrasive grades, packaging units, status flags).
        //      b. Score threshold of 4 (was 3) — three generic-adjective
        //         overlaps is no longer enough; the match needs real
        //         brand/noun-level overlap.
        $descStopWords = [
            'VERY','FINE','MEDIUM','COARSE','EXTRA','SUPER','ULTRA',
            'BOX','CASE','PACK','EACH','ROLL','KIT','SET','BAG','PAIR',
            'WITH','THIS','THAT','FROM','EACH','UNIT',
            'NLA','NEW','USED','ONLY','SIZE',
        ];
        if (count($matchOptions) === 0 && $extDesc !== '') {
            $words = array_filter(
                preg_split('/\s+/', strtoupper($extDesc)),
                function($w) use ($descStopWords) {
                    return strlen($w) > 3 && !in_array($w, $descStopWords, true);
                }
            );
            if (count($words) >= 4) {
                $bestScore = 0; $bestIdx = -1;
                for ($i = 0; $i < $descCap; $i++) {
                    $d = strtoupper($catalog[$i]['desc']);
                    $score = 0;
                    foreach ($words as $w) {
                        if ($w !== '' && strpos($d, $w) !== false) $score++;
                    }
                    if ($score > $bestScore && $score >= 4) {
                        $bestScore = $score;
                        $bestIdx = $i;
                    }
                }
                if ($bestIdx >= 0) $matchOptions[] = $catalog[$bestIdx];
            }
        }

        $primary = count($matchOptions) > 0 ? $matchOptions[0] : null;
        $results[] = [
            'extracted'    => $ext,
            'catalog'      => $primary,           // currently-selected match
            'matchOptions' => $matchOptions,      // all candidates (for dropdown)
            'ambiguous'    => count($matchOptions) > 1,
            'matched'      => $primary !== null,
            'checked'      => $primary !== null,
        ];
    }

    echo json_encode(['results' => $results]);
}

function normalizePartNum($p) {
    // Strip whitespace and common separator punctuation, uppercase the rest.
    // Leading zeros are LOAD-BEARING in this catalog — `06405` and `6405`
    // are distinct SKUs. Do NOT trim zeros here; doing so silently conflated
    // them and produced false matches in the scanner.
    $s = (string) $p;
    $s = preg_replace('/[\s\-\.\/]/', '', $s);
    return strtoupper($s);
}
