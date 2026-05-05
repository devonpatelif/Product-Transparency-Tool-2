# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Industrial Finishes True Business Intelligence (TBI) — Price Transparency Tool**

Customer-facing single-page web app that displays product pricing data, lets customers compare against supplier-quoted prices, and exposes markup inflation by deriving real vs. stated discounts. Deployed to industrialfinishes.com.

## Tech Stack

- **Frontend:** Vanilla JS, HTML5, CSS3 — all self-contained in [index.html](index.html)
- **Backend:** PHP 7.0+ — three public endpoints plus two shared helpers:
  - [api-data.php](api-data.php) — server-side search/filter/paginate of the catalog. **Never ships the full dataset to the browser.** Modes: `?meta=1` for vendor/category/total counts, default for paginated search, `?op=match` (POST) for scanner part-number matching.
  - [api-analyze.php](api-analyze.php) proxies uploaded invoice/quote images or PDFs to the Claude Vision API and returns structured line items.
  - [api-email.php](api-email.php) captures email/phone leads from the gate modal and appends them to `leads.csv`.
  - [_ratelimit.php](_ratelimit.php) — shared per-IP sliding-window limiter. Each endpoint passes a distinct `$bucket` so a busy `api-data.php` can't burn the (much costlier) Vision-API or leads-capture budgets.
  - [_check_origin.php](_check_origin.php) — strict Origin/Referer host validation (exact host or direct subdomain). Replaces the old substring match that allowed `industrialfinishes.com.attacker.com`.
- **Data:** ~15 MB JSON product catalog ([IVData.json](IVData.json)) with flexible field naming (auto-detected on the server). Normalized + serialized to `.cache/IVData.normalized.php` on first load so subsequent requests skip the parse.
- **Secrets:** `config.local.php` (gitignored) sets `ANTHROPIC_API_KEY` via `putenv()`; `api-analyze.php` reads it via `getenv()`. **Both `api-analyze.php` and `api-email.php` prefer `dirname(__DIR__) . '/config.local.php'`** (parent dir, outside web root) so a broken Apache PHP handler can't serve the file as plain text. Falls back to in-project location.

## Architecture

### Data flow

1. [index.html](index.html) always calls `api-data.php` (no environment branching). The bulk catalog is never sent to the browser.
2. On boot, the front end fetches `?meta=1` once — small payload (~10 KB) with vendor list, category map, and total counts. Used to populate filter dropdowns and the hero stat cards.
3. As the user searches/filters/sorts/paginates, the front end issues `GET api-data.php?q=…&category=…&vendor=…&sort=…&dir=…&limit=…&offset=…`. The server filters the (cached) normalized catalog and returns just the page of matching rows + `total` for pagination.
4. The mobile invoice scanner POSTs extracted line items to `api-data.php?op=match` — three-tier fuzzy matching (exact part #, partial, description-keyword) runs on the server, returning the same shape the front end used to compute locally.
5. Server-side normalization handles flexible JSON field names (`detectKeys()` mirrored from the original front-end logic) and Power BI `TableName[field]` key prefixes. Records with price ≤ 0 are dropped.
6. `selected` (comparison items) lives only on the client; persisted to `sessionStorage` (key: `ifs_sel`).

### Caching, rate limiting, and spend cap

- **Catalog cache:** `loadCatalog()` in `api-data.php` checks APCu first (if available), then a serialized PHP file at `.cache/IVData.normalized.php`. Cold-path (cache miss) decodes the 8 MB JSON, normalizes it, writes the cache atomically. Cache invalidates when `IVData.json`'s mtime changes.
- **Rate limiting (shared `_ratelimit.php`):** per-IP sliding window in `.ratelimit/<sha1(bucket:ip)>` gated by a sibling `.lock`. Buckets and budgets are per-endpoint:
  - `data` bucket — 120 req / 60 s (search traffic)
  - `analyze` bucket — 10 req / 60 s (Vision API costs real money)
  - `email` bucket — 3 req / 3600 s (leads gate is one-time per browser; this stops scripted pollution)

  Returns 429 + `Retry-After` when exceeded. **Fail-open** on disk/lock errors (transient hosting hiccups must not take the site down); failures are logged via `error_log()`.
- **Daily spend cap (`api-analyze.php`):** global hard ceiling of `$dailySpendMax = 500` Vision-API calls per UTC day across all clients, tracked in `.ratelimit/spend-analyze-daily.txt`. Per-IP limits are bypassable with proxy rotation; this is the financial backstop. Increment happens **after** request validation but **before** the upstream call. Returns 503 + `Retry-After: 86400` when exhausted.
- **Memory:** `ini_set('memory_limit', '256M')` at the top of `api-data.php`. PHP arrays carry ~6× the underlying JSON size; cold-path normalize peaks ~150 MB. Hot path (cache hit) is cheap.
- **Vision model:** `claude-sonnet-4-6` (chosen over Haiku for accuracy on non-standard invoice layouts — brand-logo columns, mixed QTY formats, per-case vs fractional units). The cost difference is bounded by the daily spend cap.
- **DoH fallback (`api-analyze.php`):** on cURL errno 6 (`CURLE_COULDNT_RESOLVE_HOST`), the endpoint resolves `api.anthropic.com` via DNS-over-HTTPS and retries with `CURLOPT_RESOLVE` pinning the IP (SNI/Host headers unchanged so TLS still verifies). Some corporate resolvers return only AAAA or nothing for that hostname; this keeps the tool working for IPv4-only clients without admin/hosts-file access.
- **Windows quirk:** `is_writable()` false-negatives on directories. Both the cache and rate-limit code skip that check and rely on the actual write attempt to fail gracefully.

### Key functions

| Function | Where | Purpose |
|---|---|---|
| `loadCatalog()` | `api-data.php` | APCu → file cache → cold-path JSON parse |
| `normalizeCatalog()` | `api-data.php` | Streaming normalize; frees source rows as it goes |
| `handleSearch()` | `api-data.php` | Filter + sort + paginate |
| `handleMatch()` | `api-data.php` | Three-tier fuzzy match for the scanner |
| `checkRateLimit()` / `enforceRateLimit()` | `_ratelimit.php` | Per-IP sliding-window limiter, shared by all three endpoints |
| `checkOrigin()` / `enforceOrigin()` / `hostMatches()` | `_check_origin.php` | Strict Origin/Referer host validation |
| `incrementDailySpend()` | `api-analyze.php` | Atomic counter for the global daily Vision-API cap |
| `postJsonToClaude()` / `resolveViaDoH()` | `api-analyze.php` | cURL-with-stream-fallback POST + DoH retry path |
| `csvSafe()` / `hasObviousFakePhonePattern()` | `api-email.php` | CSV formula-injection guard + fake-number detection |
| `loadData()` / `init()` | `index.html` | Boot: fetch `?meta=1`, populate dropdowns/stats, then fetch first page |
| `applyFilters()` / `fetchProducts()` | `index.html` | Trigger a server fetch with current state; sequence number guards against out-of-order responses |
| `render()` | `index.html` | Paginated table — `filtered` is now the current page only |
| `matchExtractedItems()` | `index.html` | POSTs scanner items to `?op=match`; returns matched results |
| `rebuildPanel()` | `index.html` | Savings calculator with markup/discount analysis |
| `exportCSV()` | `index.html` | Full comparison summary export |

### `IFS_INTERNAL` guard

`_ratelimit.php` and `_check_origin.php` both top with `if (!defined('IFS_INTERNAL')) { http_response_code(403); exit; }`. Each public endpoint sets `define('IFS_INTERNAL', true)` before requiring them. Hitting either helper directly via the web returns 403.

### Lead capture (email gate)

A modal in [index.html](index.html) blocks three high-value actions until the user submits an email or phone:
- **`compare` trigger** — firing when adding a 2nd item to the comparison panel ([index.html:1444](index.html#L1444))
- **`scan` trigger** — firing when opening the mobile invoice scanner ([index.html:2309](index.html#L2309))
- **`export` trigger** — firing when clicking the CSV export button ([index.html:1855](index.html#L1855))

Flow: `requireEmail(trigger, onSuccess)` → modal appears with trigger-specific copy from `EMAIL_GATE_COPY` → on submit, value is saved to `localStorage` (key: `ifs_email`) AND fire-and-forget POSTed to `api-email.php` with `keepalive: true` → `onSuccess` callback runs. Once captured, the gate never appears again on that browser. UX never blocks on the network call.

`api-email.php` accepts **either** `email` **or** `phone` (or both). Validation:
- Email — `FILTER_VALIDATE_EMAIL` + 254-char cap.
- Phone — strips to digits, requires 10–15 (NANP through E.164). Rejects all-same digits, ascending/descending mod-10 sequences, and invalid NANP shapes (area/exchange first digit 0/1, area code `555`). The same fake-number patterns are checked client-side; the server check exists so direct API hits can't bypass.
- Trigger — whitelisted to `[a-z0-9_-]`, 32-char cap.
- All CSV fields run through `csvSafe()`, which prefixes a literal `'` to any value starting with `= + - @ \t \r` to neutralize Excel/Sheets/LibreOffice formula injection. `fputcsv()` does NOT do this on its own.

`leads.csv` columns: `timestamp, email, phone, trigger, ip, user_agent`. Phone is stored digits-only (normalized) so the file is dialable without re-parsing. **Preferred location is `dirname(__DIR__) . '/leads.csv'`** (parent dir, outside web root); falls back to in-project path only if `leads.csv` already exists there (smooth migration: move the file once and writes auto-switch on the next request).

### Mobile invoice scanner

On mobile (`matchMedia('(max-width: 768px)')`), a scan screen lets users photograph or upload a supplier invoice/quote. Flow: image → `POST api-analyze.php` (multipart `image` field) → Claude Vision extracts line items as JSON → `matchExtractedItems()` cross-references part numbers against the catalog (normalized: stripped of spaces/dashes/dots/slashes and leading zeros, uppercased) → matched items auto-populate the comparison panel.

- PDF uploads are sent as `document` content blocks; images as `image` blocks
- Max upload 10 MB; types: JPEG, PNG, GIF, WebP, PDF
- The extraction prompt lives only in [api-analyze.php](api-analyze.php). The browser never calls Claude directly — there is no front-end fallback path that could expose the API key.

### Savings calculator logic

The panel computes supplier markup by deriving the supplier's list price from user-entered "Your Pay" and "Your Discount %", then comparing against MSRP. Key thresholds:
- **Overprice:** `youPay > MSRP` (`realDisc < -0.05%`)
- **Ripoff:** gap between stated and real discount > 3%
- **Fair:** gap ≤ 1% and not overpriced

### Theming

Dark theme (default, navy/charcoal + lime green accent `#c2d501`) and light theme (forest green `#185641`). Toggle in top-right. All styles embedded in [index.html](index.html).

## Development

**Requires PHP** — opening [index.html](index.html) directly via `file://` no longer works because the front end always fetches from `api-data.php`. Run `php -S localhost:8000` from the project root, then visit `http://localhost:8000/`.

**Local mode:** all three endpoints skip the origin check when `HTTP_HOST` does not contain `industrialfinishes.com`, so localhost dev works without spoofing headers. To exercise production-mode origin enforcement, send `Host: industrialfinishes.com` with curl. `api-analyze.php` also surfaces upstream cURL/stream errors in the JSON `debug` field when not in production, so reach-AI failures don't require digging through `error_log`.

**Cache + rate-limit dirs:** `.cache/` and `.ratelimit/` are auto-created on first request, with auto-generated `.htaccess deny` files dropped inside. Both directories are gitignored implicitly (dot-prefixed); safe to delete to force a cache rebuild or reset rate-limit state. The daily-spend counter file is `.ratelimit/spend-analyze-daily.txt` — delete to reset the cap mid-day if needed.

**Deployment:** upload [index.html](index.html), [api-data.php](api-data.php), [api-analyze.php](api-analyze.php), [api-email.php](api-email.php), [_ratelimit.php](_ratelimit.php), [_check_origin.php](_check_origin.php), [IVData.json](IVData.json), and `config.local.php` (with production key) to web root. Apply rules from [htaccess-rules.txt](htaccess-rules.txt) — these block direct public access to `IVData.json`, `leads.csv`, `.cache/`, and `.ratelimit/`. For best secrets hygiene, place `config.local.php` and `leads.csv` in the **parent** directory of web root; the endpoints look there first. `.gitignore` keeps `config.local.php` and `.env` out of commits — never commit API keys, captured leads, or cache files.

## Security considerations

- **No bulk catalog in browser.** `api-data.php` always paginates; the only thing visible in DevTools is the current page slice (~25 rows by default). The DevTools "Save as on the JSON request" attack is closed.
- **Strict Origin / Referer enforcement.** `_check_origin.php` matches exact host or direct subdomain (`hostMatches()`). The previous substring check let `industrialfinishes.com.attacker.com` and `evilindustrialfinishes.com` through. Origin/Referer are still forgeable by non-browser clients, so this is paired with rate limits and the spend cap — not relied on alone for anything that costs money.
- **Per-endpoint rate limit buckets.** Each endpoint passes its own `$bucket` so traffic on one doesn't drain another's budget.
- **Daily Vision spend cap.** Global ceiling enforced regardless of per-IP limits; the financial backstop against proxy-rotated abuse.
- **`.htaccess` denies** direct public access to `IVData.json`, `leads.csv`, `.cache/`, and `.ratelimit/`. The cache/rate-limit dirs also drop their own deny files as defense-in-depth.
- **Lead-capture hardening.** Email + phone validated, trigger whitelisted to `[a-z0-9_-]`, length caps on email (254) and user-agent (255), CSV formula-injection neutralized via `csvSafe()`.
- **XSS.** `esc()` handles HTML escaping in search highlighting and tooltips.
- **What "MSRP" means here:** the catalog's MSRP is **manufacturer** suggested retail, not Industrial Finishes' own pricing. The competitively sensitive asset is the *curated catalog* itself (which products IFS sells, vendor relationships, the normalization work) — MSRP in isolation is not a trade secret.

## File roles

| File | Role |
|------|------|
| [index.html](index.html) | Main application — CSS + JS + HTML including desktop table, mobile scan screen, and email gate modal |
| [api-data.php](api-data.php) | Server-side search/filter/paginate + scanner match. Sole entry point for catalog data. |
| [api-analyze.php](api-analyze.php) | Origin-validated Claude Vision proxy for invoice/quote extraction; daily spend cap; DoH fallback |
| [api-email.php](api-email.php) | Origin-validated lead capture endpoint — appends to `leads.csv` |
| [_ratelimit.php](_ratelimit.php) | Shared per-IP sliding-window limiter (bucketed per endpoint) |
| [_check_origin.php](_check_origin.php) | Shared strict Origin/Referer host validation |
| `config.local.php` | Gitignored — sets `ANTHROPIC_API_KEY` via `putenv()`. Prefer parent dir over project dir. |
| [IVData.json](IVData.json) | Product catalog source of truth (manufacturer MSRP). **Server-only**, never sent to browser. |
| `.cache/IVData.normalized.php` | Auto-generated serialized normalized catalog (rebuilt when `IVData.json` mtime changes). Safe to delete. |
| `.ratelimit/<hash>` | Auto-generated per-IP request-timestamp files. Safe to delete to reset limits. |
| `.ratelimit/spend-analyze-daily.txt` | Global daily Vision-API spend counter. Safe to delete to reset the cap. |
| `leads.csv` | Captured email/phone leads — created at runtime, blocked by `.htaccess`, do not commit. Prefer parent dir over project dir. |
| [htaccess-rules.txt](htaccess-rules.txt) | Apache security rules for deployment (blocks `IVData.json`, `leads.csv`, `.cache/`, `.ratelimit/`) |
