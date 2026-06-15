# Developer Guidelines — CodePros SVG Secure Support

## Table of Contents

1. [Prerequisites & Setup](#1-prerequisites--setup)
2. [Directory Structure](#2-directory-structure)
3. [Architecture Overview](#3-architecture-overview)
4. [Bootstrap & Constants](#4-bootstrap--constants)
5. [Class Reference](#5-class-reference)
6. [Upload Pipeline Walkthrough](#6-upload-pipeline-walkthrough)
7. [Settings Reference](#7-settings-reference)
8. [Database Schema](#8-database-schema)
9. [Security Log Format](#9-security-log-format)
10. [Extending the Plugin](#10-extending-the-plugin)
11. [Naming Conventions](#11-naming-conventions)
12. [Known Issues & Gotchas](#12-known-issues--gotchas)
13. [Security Design Principles](#13-security-design-principles)
14. [Manual Test Cases](#14-manual-test-cases)

---

## 1. Prerequisites & Setup

**Requirements**

| Requirement | Minimum |
|---|---|
| PHP | 7.4 |
| WordPress | 6.0 |
| Composer | Any current version |
| libxml | 2.9+ (XXE disabled by default) |

**Install**

```bash
cd wp-content/plugins/codepros-svg-secure-support
composer install
```

The plugin halts with an admin notice and `return`s from the bootstrap file if `vendor/autoload.php` is missing. Nothing else runs.

**Update / regenerate autoloader**

```bash
composer update
composer dump-autoload --optimize
```

There is no build step, test runner, or linter configured. The `vendor/` directory is generated — do not commit it.

---

## 2. Directory Structure

```
codepros-svg-secure-support/
├── codepros-svg-secure-support.php   Bootstrap: constants, autoloader, hooks
├── uninstall.php                     Cleanup on plugin deletion
├── composer.json                     PSR-4 autoload + enshrined/svg-sanitize dep
├── uploads-htaccess.txt              Apache snippet for wp-content/uploads/
├── uploads-nginx.conf                Nginx snippet for wp-content/uploads/
├── src/
│   ├── Validator.php                 5-check upload validation pipeline
│   ├── Sanitizer.php                 DOM sanitizer wrapper + string scan
│   ├── AllowedTags.php               Tag whitelist for enshrined/svg-sanitize
│   ├── AllowedAttributes.php         Attribute whitelist for enshrined/svg-sanitize
│   ├── Logger.php                    Security event logging (debug log + DB)
│   ├── Hooks.php                     All WP filter/action wiring
│   ├── Headers.php                   CSP + security response headers
│   ├── Database.php                  Log table creation + queries
│   └── Admin/
│       ├── Admin.php                 Settings page, log purge action
│       └── templates/
│           ├── page-settings.php     Settings tab HTML
│           └── page-logs.php         Log viewer tab HTML
└── vendor/                           Composer-generated (not committed)
```

---

## 3. Architecture Overview

### Singleton pattern

Every class uses a private constructor and a static `get_instance()` factory:

```php
class Foo {
    private static ?self $instance = null;
    private function __construct() {}

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
```

Never instantiate plugin classes with `new` — always call `ClassName::get_instance()`.

### Namespace

All classes live under `CodePros\SVGSecureSupport\`. The `Admin\Admin` class sits in the sub-namespace `CodePros\SVGSecureSupport\Admin\`.

PSR-4 mapping in `composer.json`:

```json
"autoload": {
    "psr-4": {
        "CodePros\\SVGSecureSupport\\": "src/"
    }
}
```

### Bootstrap flow (`codepros-svg-secure-support.php`)

```
plugin file loaded
  → define CPSVGSS_* constants
  → check vendor/autoload.php exists → admin notice + return if missing
  → require_once vendor/autoload.php
  → register_activation_hook → Database::install()
  → register_deactivation_hook → Hooks::deactivate()
  → plugins_loaded action:
      Hooks::get_instance()->init()
      Headers::get_instance()->init()
      Admin\Admin::get_instance()->init()
```

### Responsibility split

| Class | Responsibility |
|---|---|
| `Hooks` | Wires all WP actions/filters. No business logic. Delegates entirely. |
| `Validator` | 5-check upload gate. Returns structured result, never dies/echoes. |
| `Sanitizer` | Wraps enshrined/svg-sanitize + string-level payload scan. |
| `AllowedTags` | `TagInterface` implementation — the tag whitelist. |
| `AllowedAttributes` | `AttributeInterface` implementation — the attribute whitelist. |
| `Logger` | Writes security events to WP debug log and/or DB. |
| `Headers` | Sends HTTP security headers. |
| `Database` | Creates/upgrades the log table; insert/query/purge helpers. |
| `Admin\Admin` | Settings page, field registration, log purge action. |

---

## 4. Bootstrap & Constants

Defined in `codepros-svg-secure-support.php`:

| Constant | Value | Purpose |
|---|---|---|
| `CPSVGSS_VERSION` | `'1.0.0'` | Plugin version string |
| `CPSVGSS_PLUGIN_DIR` | `plugin_dir_path(__FILE__)` | Absolute path to plugin root (trailing slash) |
| `CPSVGSS_PLUGIN_URL` | `plugin_dir_url(__FILE__)` | URL to plugin root (trailing slash) |
| `CPSVGSS_PLUGIN_FILE` | `__FILE__` | Absolute path to bootstrap file |
| `CPSVGSS_MAX_FILE_SIZE` | `1048576` | Default max upload size in bytes (1 MB) |
| `CPSVGSS_MAX_XML_NODES` | `5000` | Default max DOM node count |
| `CPSVGSS_MAX_DIMENSION` | `10000` | Default max SVG dimension in pixels |

The constants are fallbacks. All three limits are overridable through the admin settings page, which stores the live values as `cpsvgss_max_file_size_kb`, `cpsvgss_max_xml_nodes`, and `cpsvgss_max_dimension_px` respectively.

---

## 5. Class Reference

### `Hooks` — `src/Hooks.php`

**Registered hooks (set up in `init()`)**

| Hook | Priority | Method | What it does |
|---|---|---|---|
| `upload_mimes` | 10 | `allow_svg_mime` | Adds `svg => image/svg+xml` only for users whose role is in `cpsvgss_allowed_roles` |
| `wp_check_filetype_and_ext` | 10 | `fix_svg_mime_check` | Forces `ext = svg`, `type = image/svg+xml` for `.svg` files (WP's `getimagesize()` returns false for SVG) |
| `wp_handle_upload_prefilter` | 1 | `check_upload_capability` | Role gate — blocks SVG upload before anything else runs |
| `wp_handle_upload_prefilter` | 10 | `handle_upload_prefilter` | Validates → sanitizes the tmp file in place |
| `wp_prepare_attachment_for_js` | 10 | `prepare_svg_for_js` | Injects SVG URL + dimensions into the media library modal JS object |
| `wp_generate_attachment_metadata` | 10 | `generate_svg_metadata` | Populates width/height/file in attachment metadata |
| `cpsvgss_purge_logs_cron` | — | `run_log_purge` | WP-Cron handler for daily log retention purge |

**Deactivation** (`Hooks::deactivate()`)

Clears the `cpsvgss_purge_logs_cron` scheduled event. Called by `register_deactivation_hook`.

**Role check** (`user_can_upload_svg()` — private)

```php
$allowed = (array) get_option( 'cpsvgss_allowed_roles', [ 'administrator' ] );
// Falls back to ['administrator'] if the saved array is empty.
return (bool) array_intersect( $allowed, (array) $user->roles );
```

SVGz (`.svgz`) is intentionally excluded from `allow_svg_mime`. The sanitization pipeline works on plain XML bytes and cannot safely inspect gzip-compressed content.

---

### `Validator` — `src/Validator.php`

**Entry point**

```php
Validator::get_instance()->validate(
    string $tmp_path,   // absolute path to uploaded temp file
    string $filename,   // original client filename
    int    $filesize    // reported size (validator re-stats the file itself)
): array{valid: bool, error: string, checks: array<string, bool>}
```

Returns on the first failing check (fail-fast). The `checks` array records which checks ran and their result so the Logger can report them.

**The 5 checks (in order)**

| Check | Key in `checks` | What fails it |
|---|---|---|
| Extension | `extension` | Not `.svg`; or stem matches `/\.(php[0-9]?|phtml|phar|asp|aspx|jsp|js|html?|xml|sh|py|cgi|pl)/i` (double-extension detection) |
| MIME | `mime` | `finfo_file()` does not return `image/svg+xml` AND first 512 bytes lack `<svg` or `<?xml` |
| Size | `size` | `filesize($tmp)` > `cpsvgss_max_file_size_kb * 1024` |
| Parse | `parse` | `DOMDocument::loadXML()` fails (malformed XML) |
| Node count | `node_count` | `DOMXPath('descendant-or-self::node()')` count > `cpsvgss_max_xml_nodes` |
| Dimensions | `dimensions` | Resolved width or height > `cpsvgss_max_dimension_px` |

Note: the MIME check has a permissive fallback — some environments report `text/xml` or `text/html` for valid SVGs. If `finfo` returns an unexpected MIME but the first 512 bytes contain `<svg` or `<?xml`, the file is accepted.

Dimensions fall back to the `viewBox` attribute if explicit `width`/`height` are absent. Percentage dimensions (`width="100%"`) resolve to 0 and pass unconditionally (relative values cannot be bounded).

**XML loading** (`safe_load_xml()` — private)

Uses `LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING` flags to prevent network calls and suppress parse warnings. `libxml_use_internal_errors(true)` is scoped and restored after the call. External entity loading is disabled by default in libxml ≥ 2.9.

---

### `Sanitizer` — `src/Sanitizer.php`

**Entry point**

```php
Sanitizer::get_instance()->sanitize_file( string $file_path ): array{
    success:             bool,
    xml_issues:          array,   // from EnshrinedSanitizer::getXmlIssues()
    suspicious_payloads: array,   // labels of patterns found in final scan
    error:               string,
}
```

Overwrites `$file_path` in place with clean SVG on success.

**Pipeline inside `sanitize_file()`**

1. Read raw file bytes.
2. Instantiate `enshrined\svgSanitize\Sanitizer`, call `removeRemoteReferences(true)`, inject `AllowedTags` and `AllowedAttributes`.
3. Call `$lib->sanitize($raw)` — DOM traversal, tag/attribute whitelist enforcement, remote reference stripping.
4. If `cpsvgss_strip_xml_comments` is enabled (default on), run `preg_replace('/<!--.*?-->/s', '', $clean)`.
5. Run `scan_for_payloads()` on the resulting string.
6. If any payload patterns found → return failure (do not write).
7. `file_put_contents($file_path, $clean)` — replaces the tmp file.

**`scan_for_payloads()` patterns**

| Pattern | Label |
|---|---|
| `/javascript\s*:/i` | `javascript: URI` |
| `/<script[\s>\/]/i` | `script element` |
| `/on\w+\s*=/i` | `event handler attribute` |
| `/expression\s*\(/i` | `CSS expression()` |

Any match causes the file to be rejected entirely (not just stripped).

**Note:** The `cpsvgss_strip_style_tags` option is registered in settings but is not yet wired into `Sanitizer`. `<style>` tag removal currently relies on it not being in `AllowedTags::getTags()`, which means style blocks are already stripped via the DOM whitelist.

---

### `AllowedTags` — `src/AllowedTags.php`

Implements `enshrined\svgSanitize\data\TagInterface`.

**Allowed tags**

```
svg, g, path, circle, ellipse, rect, line, polyline, polygon,
text, tspan, textPath, defs, clipPath, linearGradient, radialGradient,
stop, use, symbol, title, desc
```

**Intentionally excluded:** `script`, `iframe`, `object`, `embed`, `foreignObject`, `style`, `link`, `meta`, `base`, `image`, `a`, `form`.

To add a tag: edit the array in `getTags()`. To add an entire group of tags for a specific use case, create a second `TagInterface` class and swap it in `Sanitizer.php`.

---

### `AllowedAttributes` — `src/AllowedAttributes.php`

Implements `enshrined\svgSanitize\data\AttributeInterface`.

**Grouped by purpose**

| Group | Attributes |
|---|---|
| Presentation | `fill`, `stroke`, `stroke-width`, `stroke-linecap`, `stroke-linejoin`, `stroke-dasharray`, `stroke-dashoffset`, `stroke-miterlimit`, `fill-opacity`, `stroke-opacity`, `opacity` |
| Dimensions / viewport | `width`, `height`, `viewBox`, `preserveAspectRatio` |
| Geometry | `d`, `x`, `y`, `x1`, `y1`, `x2`, `y2`, `cx`, `cy`, `r`, `rx`, `ry`, `points` |
| Transform / identity | `transform`, `id`, `class`, `style` |
| Gradient / pattern | `offset`, `stop-color`, `stop-opacity`, `gradientUnits`, `gradientTransform`, `spreadMethod`, `patternUnits`, `patternTransform`, `fx`, `fy` |
| References | `href`, `xlink:href` — external values blocked by `removeRemoteReferences(true)` |
| Clipping / masking | `clip-path`, `mask`, `filter` |
| Text | `font-size`, `font-family`, `font-weight`, `text-anchor`, `letter-spacing`, `word-spacing` |
| Markers | `marker-start`, `marker-mid`, `marker-end` |

---

### `Logger` — `src/Logger.php`

**Severity levels** (ordered, used to filter by `cpsvgss_log_level`)

| Level | Numeric | When used |
|---|---|---|
| `info` | 0 | Allowed uploads, individual XML issues resolved |
| `warning` | 1 | Blocked uploads, sanitization failures |
| `critical` | 2 | Suspicious payloads that survived DOM traversal |

**Core method**

```php
Logger::get_instance()->log(
    string $event_type,  // 'upload_allowed', 'upload_blocked', etc.
    string $severity,    // 'info', 'warning', 'critical'
    string $filename,
    string $details
): void
```

Checks `cpsvgss_logging_enabled` and `cpsvgss_log_level` before doing any work.

**Convenience methods**

| Method | When called |
|---|---|
| `log_capability_blocked(string $filename)` | User lacks required role |
| `log_validation_failure(string $filename, string $error, array $checks)` | Any of the 5 validator checks fails |
| `log_sanitization_report(string $filename, array $result)` | After every sanitization attempt |

**IP resolution order** (first valid IP wins)

`HTTP_CF_CONNECTING_IP` → `HTTP_X_FORWARDED_FOR` (first entry) → `HTTP_X_REAL_IP` → `REMOTE_ADDR`

---

### `Headers` — `src/Headers.php`

**`send_nosniff_header()`** — hooked to `send_headers`, fires on every request:
```
X-Content-Type-Options: nosniff
```

**`maybe_send_svg_headers()`** — hooked to `template_redirect`, fires only on SVG attachment pages when `cpsvgss_csp_enabled` is on:
```
Content-Security-Policy: <value of cpsvgss_csp_header>
X-Frame-Options: SAMEORIGIN
```

Default CSP value:
```
default-src 'self'; script-src 'none'; object-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:;
```

An SVG attachment page is detected with:
```php
is_attachment() && get_post_mime_type( $post->ID ) === 'image/svg+xml'
```

---

### `Database` — `src/Database.php`

**`Database::install()`** — called by activation hook (static method)

Creates `{prefix}cpsvgss_security_log` via `dbDelta()` and schedules the daily `cpsvgss_purge_logs_cron` WP-Cron event if not already scheduled.

**`insert_log(array $data): bool`**

Inserts one row. `$data` keys: `event_type`, `severity`, `filename`, `details`, `user_id`, `user_login`, `ip_address`. `created_at` is set internally using `current_time('mysql', true)` (UTC).

**`get_logs(array $filters, int $per_page, int $page): array`**

Returns `['rows' => [...], 'total' => int]`. Accepts optional `$filters['severity']` and `$filters['event_type']` for filtering. Used by the log viewer template.

**`purge_old_logs(int $days): int`**

Deletes rows older than `$days` days (UTC). Returns the count of deleted rows.

---

### `Admin\Admin` — `src/Admin/Admin.php`

**Settings page slug:** `codepros-svg-secure-support`  
**Option group:** `cpsvgss_settings`  
**Access:** `manage_options` capability required to view or save settings.

The page is registered under **Settings → SVG Secure Support** via `add_options_page()`. A tab parameter (`?tab=settings` / `?tab=logs`) switches between the two templates.

**`field()` helper** — private method that calls both `register_setting()` and `add_settings_field()` in one shot. Accepts `type` values: `text`, `number`, `checkbox`, `select`, `textarea`, `multicheck`.

**Log purge action:** `admin_post_svgss_purge_logs` — protected by `check_admin_referer('cpsvgss_purge_logs')` and `manage_options` capability check.

---

## 6. Upload Pipeline Walkthrough

```
User uploads file
  │
  ├─ upload_mimes filter (priority 10)
  │    allow_svg_mime() → adds svg MIME only if user's role is allowed
  │    (unauthorized users: SVG option never appears in the uploader)
  │
  ├─ wp_handle_upload_prefilter (priority 1)
  │    check_upload_capability()
  │    → not SVG? return unchanged (fast path)
  │    → role not allowed? set $file['error'], log upload_blocked, return
  │
  ├─ wp_handle_upload_prefilter (priority 10)
  │    handle_upload_prefilter()
  │    → not SVG? return unchanged
  │    → $file['error'] already set? return unchanged
  │    → Validator::validate($tmp, $name, $size)
  │         fail? set $file['error'], log_validation_failure(), return
  │    → Sanitizer::sanitize_file($tmp)   ← overwrites tmp in place
  │         → log_sanitization_report()
  │         fail? set $file['error'], return
  │    → return $file  (clean SVG proceeds)
  │
  ├─ wp_check_filetype_and_ext (priority 10)
  │    fix_svg_mime_check() → forces ext=svg, type=image/svg+xml
  │    (WP's getimagesize() returns false for SVG, which would reject the file)
  │
  ├─ WordPress moves the sanitized tmp file to uploads/
  │
  ├─ wp_generate_attachment_metadata (priority 10)
  │    generate_svg_metadata() → reads width/height from SVG root element
  │
  └─ wp_prepare_attachment_for_js (priority 10)
       prepare_svg_for_js() → injects SVG URL + dimensions into media modal
```

---

## 7. Settings Reference

All options use the `cpsvgss_` prefix. Defaults apply when an option has never been saved.

### Upload Restrictions

| Option | Type | Default | Range / Notes |
|---|---|---|---|
| `cpsvgss_allowed_roles` | `array` | `['administrator']` | Array of WP role slugs. Falls back to `['administrator']` if saved as empty. |
| `cpsvgss_max_file_size_kb` | `int` | `1024` | 1–10240 KB. Validator reads this × 1024 for bytes. |
| `cpsvgss_max_xml_nodes` | `int` | `5000` | 100–50000 nodes. |
| `cpsvgss_max_dimension_px` | `int` | `10000` | 100–100000 px. |

### Sanitization

| Option | Type | Default | Notes |
|---|---|---|---|
| `cpsvgss_strip_style_tags` | `int` (0/1) | `1` | Registered in settings; `<style>` tags are already excluded via `AllowedTags`. Wiring to `Sanitizer` is pending. |
| `cpsvgss_strip_xml_comments` | `int` (0/1) | `1` | Wired to `Sanitizer::sanitize_file()`. Strips `<!-- ... -->` after DOM sanitization. |

### Security Headers

| Option | Type | Default | Notes |
|---|---|---|---|
| `cpsvgss_csp_enabled` | `int` (0/1) | `1` | When off, neither CSP nor `X-Frame-Options` are sent on SVG attachment pages. `X-Content-Type-Options: nosniff` is always sent. |
| `cpsvgss_csp_header` | `string` | See default CSP above | Sanitized via `sanitize_textarea_field()`. Reverts to the default if saved empty. |

### Security Logging

| Option | Type | Default | Notes |
|---|---|---|---|
| `cpsvgss_logging_enabled` | `int` (0/1) | `1` | Master switch. |
| `cpsvgss_log_to_wp_debug` | `int` (0/1) | `1` | Writes to `wp-content/debug.log` via `error_log()`. |
| `cpsvgss_log_to_database` | `int` (0/1) | `1` | Inserts into `{prefix}cpsvgss_security_log`. |
| `cpsvgss_log_level` | `string` | `'warning'` | `'info'` / `'warning'` / `'critical'`. Events below the threshold are silently dropped. |
| `cpsvgss_log_retention_days` | `int` | `30` | 1–365 days. Used by the daily cron and the manual purge button. |

---

## 8. Database Schema

Table: `{wpdb->prefix}cpsvgss_security_log`

```sql
id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
event_type  VARCHAR(40)     NOT NULL,
severity    VARCHAR(10)     NOT NULL DEFAULT 'info',
filename    VARCHAR(255)    NOT NULL DEFAULT '',
details     TEXT            NOT NULL,
user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
user_login  VARCHAR(60)     NOT NULL DEFAULT '',
ip_address  VARCHAR(45)     NOT NULL DEFAULT '',
created_at  DATETIME        NOT NULL,
PRIMARY KEY (id),
KEY severity  (severity),
KEY event_type (event_type),
KEY created_at (created_at)
```

`created_at` is stored in UTC. The table is created (or upgraded) via `dbDelta()` on plugin activation, so adding columns to the schema and re-activating is safe.

**Event types used**

| `event_type` | `severity` | Triggered by |
|---|---|---|
| `upload_allowed` | `info` | SVG passed all checks |
| `upload_sanitized` | `info` | Per XML issue resolved during sanitization |
| `upload_blocked` | `warning` | Role gate, validation failure, or sanitization failure |
| `suspicious_payload` | `critical` | Payload pattern survived DOM traversal |

---

## 9. Security Log Format

WP debug log entries follow this format:

```
[SVG Secure Support][SEVERITY][event_type] File: filename — details. User: login (ID:n) IP: x.x.x.x
```

Example:
```
[SVG Secure Support][CRITICAL][suspicious_payload] File: evil.svg — Suspicious payloads survived sanitization: javascript: URI. User: editor (ID:5) IP: 203.0.113.42
```

```
[SVG Secure Support][WARNING][upload_blocked] File: payload.php.svg — Filename contains a disallowed extension pattern (failed checks: extension). User: author (ID:12) IP: 198.51.100.7
```

---

## 10. Extending the Plugin

### Adding allowed SVG tags

Edit `AllowedTags::getTags()` in `src/AllowedTags.php`:

```php
public static function getTags(): array {
    return [
        // existing tags ...
        'marker',   // add here
    ];
}
```

Think carefully before adding `image`, `a`, `foreignObject`, or `use` with external hrefs — these are the most common SVG attack vectors.

### Adding allowed SVG attributes

Edit `AllowedAttributes::getAttributes()` in `src/AllowedAttributes.php`. `enshrined/svg-sanitize` with `removeRemoteReferences(true)` will still block external URLs in `href` / `xlink:href` values even if those attributes are listed.

### Adding a new payload scan pattern

Add to the `$patterns` array in `Sanitizer::scan_for_payloads()`:

```php
'/your-pattern/i' => 'human-readable label',
```

### Adding a new event type to the logger

Call `Logger::get_instance()->log()` directly with your event type string:

```php
Logger::get_instance()->log( 'my_event', 'warning', $filename, 'details here' );
```

New event types appear automatically in the log viewer. If you want them to appear in the filter dropdown, add them to the filter options in `src/Admin/templates/page-logs.php`.

### Hooking into the upload pipeline

The plugin sets `$file['error']` to block an upload, following WordPress conventions. You can add your own prefilter at any priority:

```php
add_filter( 'wp_handle_upload_prefilter', function( array $file ): array {
    // run your check only on SVGs, after the plugin's pipeline (priority > 10)
    if ( 'svg' !== strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ) {
        return $file;
    }
    if ( ! empty( $file['error'] ) ) {
        return $file; // already blocked
    }
    // ... your logic
    return $file;
}, 20 );
```

### Customising the CSP header

Change the default in `Headers::DEFAULT_CSP` (affects sites where the option has never been saved), or update the stored option value via the admin settings page. The value is passed directly to the `Content-Security-Policy` header — validate any change with [CSP Evaluator](https://csp-evaluator.withgoogle.com/) before deploying.

---

## 11. Naming Conventions

| Thing | Pattern | Example |
|---|---|---|
| PHP namespace | `CodePros\SVGSecureSupport\` | `CodePros\SVGSecureSupport\Validator` |
| DB option names | `cpsvgss_*` | `cpsvgss_allowed_roles` |
| Plugin constants | `CPSVGSS_*` | `CPSVGSS_MAX_FILE_SIZE` |
| DB table | `{prefix}cpsvgss_security_log` | `wp_cpsvgss_security_log` |
| WP-Cron hook | `cpsvgss_*` | `cpsvgss_purge_logs_cron` |
| Admin action | `svgss_*` | `svgss_purge_logs` |
| Settings page slug | — | `codepros-svg-secure-support` |
| Settings option group | — | `cpsvgss_settings` |
| Text domain | — | `codepros-svg-secure-support` |

---

## 12. Known Issues & Gotchas

**Table name mismatch in `purge_old_logs()`**

`Database::install()` creates `{prefix}cpsvgss_security_log` but `Database::purge_old_logs()` queries `{prefix}svgss_security_log`. The purge action and the daily cron will silently delete zero rows until this is corrected. Fix: change the table name literal in `purge_old_logs()` to match `install()`.

**`cpsvgss_strip_style_tags` option is not wired**

The option is registered and displayed in the admin UI but `Sanitizer` does not read it. `<style>` blocks are already excluded by `AllowedTags` (they are not in the whitelist), so style tags are always stripped regardless of this setting.

**MIME fallback is permissive**

If `finfo_file()` returns a non-SVG MIME type, the validator falls back to a byte-pattern check (`<svg` or `<?xml` in the first 512 bytes). This accepts some files that `finfo` misidentifies (common on shared hosting), but it also means the MIME check is a weaker gate than it appears on servers where `finfo` is unreliable.

**Percentage dimensions are unchecked**

SVG elements with `width="100%"` or `height="100%"` resolve to 0 in `Validator` and always pass the dimension check. This is intentional (relative values cannot be bounded without a rendering context) but it means a declared-large SVG could slip through if percentages are used.

**Direct file access bypasses PHP headers**

`Headers` only fires on WordPress-rendered pages. A direct request to `https://example.com/wp-content/uploads/file.svg` bypasses all PHP security headers. Apply `uploads-htaccess.txt` (Apache) or `uploads-nginx.conf` (Nginx) to close this gap.

**WordPress Multisite**

The plugin is not tested on Multisite. `Database::install()` is only called on single-site activation. Network-wide activation is not supported.

---

## 13. Security Design Principles

The plugin applies defense in depth: each layer assumes the previous one may have missed something.

```
Upload → Role gate → Validator (5 checks) → Sanitizer (DOM whitelist + string scan) → Headers (CSP)
```

1. **Block before processing.** The role gate runs at priority 1, before any parsing, to avoid processing untrusted input for unauthorized users.

2. **Validate before sanitizing.** Validation rejects structurally invalid or oversized files without invoking the DOM parser on potentially hostile XML.

3. **Delegate DOM traversal.** The plugin does not implement its own DOM sanitizer. `enshrined/svg-sanitize` is battle-tested for this purpose. The plugin adds a tag/attribute whitelist on top and applies a final string scan as a backstop.

4. **Never trust the sanitizer alone.** The string-level scan after DOM sanitization catches edge cases where obfuscated payloads survive whitelist enforcement (e.g., comment-wrapped content, encoding tricks).

5. **Headers as last resort.** CSP and `X-Frame-Options` on SVG attachment pages contain any residual active content even if it somehow reached the uploads directory.

6. **Log everything.** Security events are written to both the WP debug log and the database. The database log is queryable and purgeable from the admin UI.

---

## 14. Manual Test Cases

Upload each test SVG through the WordPress Media Library and verify the expected outcome.

| # | Test | Payload | Expected outcome |
|---|---|---|---|
| T1 | Script tag | `<svg><script>alert(1)</script></svg>` | Tag stripped, upload allowed |
| T2 | Event handler | `<svg onload="alert(1)"><rect/></svg>` | `onload` stripped, upload allowed |
| T3 | XXE | `<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]>&x;` | Upload blocked at parse stage |
| T4 | External image | `<svg><image xlink:href="http://evil.com/x.png"/></svg>` | `<image>` tag removed (not whitelisted) |
| T5 | foreignObject | `<svg><foreignObject><iframe src="evil.com"/></foreignObject></svg>` | Element and children removed |
| T6 | CSS javascript | `<rect style="fill:url(javascript:alert(1))"/>` | Style attribute value stripped or upload blocked |
| T7 | CSS expression | `<rect style="width:expression(alert(1))"/>` | Upload blocked (string scan catches `expression(`) |
| T8 | Double extension | Filename `payload.php.svg` | Upload blocked at extension check |
| T9 | Oversized file | File > configured max KB | Upload blocked at size check |
| T10 | Node flood | SVG with > 5000 elements | Upload blocked at node count check |
| T11 | Local `<use>` | `<use href="#circle"/>` | Accepted unchanged |
| T12 | data: href | `<a href="data:text/html,<script>...">` | `<a>` tag removed (not whitelisted) |
| T13 | Unauthorised role | Log in as Subscriber, upload SVG | Upload blocked with permission error |
| T14 | Clean SVG | Standard icon SVG | Accepted, logged as `upload_allowed` |

After T6/T7, verify CSP headers are present on the attachment page:
```bash
curl -sI "https://example.com/?attachment_id=<id>" | grep -i 'content-security'
```
