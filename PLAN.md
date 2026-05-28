# Plan: CodePros SVG Secure Support — WordPress Plugin

## Context

Build a Highly secure SVG upload plugin for WordPress from scratch.
SVG files are XML that can carry XSS payloads, XXE attacks, external resource injection, and embedded HTML. WordPress does not support SVG uploads natively, and naive SVG plugins are a known attack surface. This plugin implements every layer from the security guidelines: validation pipeline → DOM sanitization → whitelist enforcement → logging → CSP headers.

---

## File Structure

```
codepros-svg-secure-support/
├── svg-secure-support.php                  ← Bootstrap, constants, activation hook, autoloader
├── uninstall.php                           ← Cleanup options + DB table on uninstall
├── composer.json                           ← PSR-4 autoload config (no runtime deps)
├── uploads-htaccess.txt                    ← .htaccess snippet for wp-content/uploads/
├── src/
│   ├── Validator.php                       ← Extension, MIME, size, node count, dimension checks
│   ├── Sanitizer.php                       ← Wrapper around enshrined/svg-sanitize + final scan
│   ├── AllowedTags.php                     ← Custom TagInterface for enshrined sanitizer
│   ├── AllowedAttributes.php               ← Custom AttributeInterface for enshrined sanitizer
│   ├── Rasterizer.php                      ← Convert sanitized SVG → PNG/WebP (maximum security)
│   ├── Logger.php                          ← Security event logging (WP debug log + DB)
│   ├── Hooks.php                           ← WordPress action/filter wiring
│   ├── Headers.php                         ← CSP + security headers
│   ├── Database.php                        ← Log table schema + queries
│   └── Admin/
│       ├── Admin.php                       ← Settings page + log viewer
│       └── templates/
│           ├── page-settings.php           ← Settings page HTML
│           └── page-logs.php               ← Log viewer HTML
└── vendor/                                 ← Composer autoloader (generated, not committed)
```

**`composer.json`:**
```json
{
    "name": "svg-secure-support/svg-secure-support",
    "description": "Highly secure SVG upload plugin for WordPress",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=7.4",
        "enshrined/svg-sanitize": "^0.22"
    },
    "autoload": {
        "psr-4": {
            "CodePros\\SVGSecureSupport\\": "src/"
        }
    },
    "config": {
        "optimize-autoloader": true
    }
}
```

All classes live under the `CodePros\SVGSecureSupport\` namespace. Example:
- `CodePros\SVGSecureSupport\Validator`
- `CodePros\SVGSecureSupport\Sanitizer`
- `CodePros\SVGSecureSupport\Logger`
- `CodePros\SVGSecureSupport\Hooks`
- `CodePros\SVGSecureSupport\Headers`
- `CodePros\SVGSecureSupport\Database`
- `CodePros\SVGSecureSupport\Admin\Admin`

Bootstrap loads the autoloader: `require_once SVGSS_PLUGIN_DIR . 'vendor/autoload.php';`

**Conventions:** Composer autoloading with PSR-4. Namespace `CodePros\SVGSecureSupport\`. Option prefix `svgss_`, constant prefix `SVGSS_`. Singleton pattern with `get_instance()`. Bootstrap loads `vendor/autoload.php`. PHP 7.4+ compatible.

---

## Constants (defined in `svg-secure-support.php`)

```
CODEPROS_SVGSS_VERSION         = '1.0.0'
CODEPROS_SVGSS_PLUGIN_DIR      = plugin_dir_path(__FILE__)
CODEPROS_SVGSS_PLUGIN_URL      = plugin_dir_url(__FILE__)
CODEPROS_SVGSS_PLUGIN_FILE     = __FILE__
CODEPROS_SVGSS_MAX_FILE_SIZE   = 1048576   (1 MB)
CODEPROS_SVGSS_MAX_XML_NODES   = 5000
CODEPROS_SVGSS_MAX_DIMENSION   = 10000
```

---

## Class Details

### `CodePros\SVGSecureSupport\Validator` — `src/Validator.php`

Runs a 5-check pipeline. Returns `['valid' => bool, 'error' => string, 'checks' => array]`. Never dies/echoes.

1. **Extension check** — `pathinfo(PATHINFO_EXTENSION) === 'svg'`. Also inspect the stem for dangerous extensions via regex: `preg_match('/\.(php[0-9]?|phtml|phar|asp|aspx|jsp|js|html?|xml|sh|py|cgi|pl)/i', $stem)` → reject double-extension filenames like `payload.php.svg`.

2. **MIME check** — `finfo_open(FILEINFO_MIME_TYPE)` on the actual file bytes must return `image/svg+xml`. Secondary check: read first 512 bytes, confirm `<svg` or `<?xml` is present.

3. **Size check** — file bytes `<= SVGSS_MAX_FILE_SIZE`.

4. **Node count** — safe-parse the XML (see below), count via `DOMXPath('descendant-or-self::node()')`, reject if `> SVGSS_MAX_XML_NODES`.

5. **Dimension check** — read root `<svg>` width/height/viewBox attributes, parse numeric value (strip `px`/`pt`/`%`), reject if any absolute value `> SVGSS_MAX_DIMENSION`.

---

### `CodePros\SVGSecureSupport\Sanitizer` — `src/Sanitizer.php`

Thin wrapper around the battle-tested **`enshrined/svg-sanitize`** library (`^0.22`). Does not reimplement DOM traversal — delegates entirely to the library, then adds a final string-level safety net scan.

**Entry point:** `sanitize_file(string $file_path): array` — reads file, sanitizes, overwrites with clean content.

**Integration pattern:**
```php
use enshrined\svgSanitize\Sanitizer as EnshrinedSanitizer;

$enshrinedSanitizer = new EnshrinedSanitizer();
$enshrinedSanitizer->removeRemoteReferences(true);   // blocks external URLs
$enshrinedSanitizer->setAllowedTags(new AllowedTags());    // our custom TagInterface
$enshrinedSanitizer->setAllowedAttrs(new AllowedAttributes()); // our custom AttributeInterface

$cleanSvg = $enshrinedSanitizer->sanitize($rawSvgContent);
$xmlIssues = $enshrinedSanitizer->getXmlIssues(); // passed to Logger
```

The library handles XXE protection internally (calls `libxml_disable_entity_loader` for libxml < 2.9, disabled by default in libxml ≥ 2.9).

**Two companion classes in `src/`:**

`src/AllowedTags.php` — implements `enshrined\svgSanitize\data\TagInterface`:
```php
class AllowedTags implements TagInterface {
    public static function getTags(): array {
        return [
            'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line',
            'polyline', 'polygon', 'text', 'tspan', 'textPath',
            'defs', 'clipPath', 'linearGradient', 'radialGradient',
            'stop', 'use', 'symbol', 'title', 'desc',
        ];
        // Intentionally excludes: script, iframe, object, embed,
        // foreignObject, style, link, meta, base, image, a, form
    }
}
```

`src/AllowedAttributes.php` — implements `enshrined\svgSanitize\data\AttributeInterface`:
```php
class AllowedAttributes implements AttributeInterface {
    public static function getAttributes(): array {
        return [
            'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
            'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit',
            'fill-opacity', 'stroke-opacity',
            'width', 'height', 'viewBox', 'preserveAspectRatio',
            'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
            'points', 'transform', 'opacity', 'id', 'class', 'style',
            'offset', 'stop-color', 'stop-opacity',
            'gradientUnits', 'gradientTransform', 'spreadMethod',
            'patternUnits', 'patternTransform', 'fx', 'fy',
            'href', 'xlink:href',  // enshrined's removeRemoteReferences(true) handles blocking external values
            'clip-path', 'mask', 'filter',
            'font-size', 'font-family', 'font-weight', 'text-anchor',
            'letter-spacing', 'word-spacing',
            'marker-start', 'marker-mid', 'marker-end',
        ];
    }
}
```

**Final string scan** (safety net after library sanitization): `preg_match` for `javascript:` (case-insensitive), `<script`, `on\w+\s*=`, `expression\s*\(`. If any match → reject file entirely (log as `critical`). This catches edge cases that survive DOM manipulation.

**`sanitize_file()` return shape:**
```php
[
    'success'             => bool,
    'xml_issues'          => [],   // from $sanitizer->getXmlIssues()
    'suspicious_payloads' => [],   // from final string scan
]
```

---

### `CodePros\SVGSecureSupport\Hooks` — `src/Hooks.php`

Wiring only — no business logic. Calls Validator/Sanitizer/Logger in sequence.

| Hook | Priority | Method | Purpose |
|---|---|---|---|
| `upload_mimes` | 10 | `allow_svg_mime` | Add `image/svg+xml` if user has capability |
| `wp_check_filetype_and_ext` | 10 | `fix_svg_mime_check` | Fix WP's broken SVG MIME detection (getimagesize returns false for SVG) |
| `wp_handle_upload_prefilter` | 1 | `check_upload_capability` | Capability gate (runs first) |
| `wp_handle_upload_prefilter` | 10 | `handle_upload_prefilter` | Validate + sanitize pipeline |
| `wp_handle_upload` | 10 | `handle_upload_postfilter` | Optional ClamAV post-scan |
| `wp_prepare_attachment_for_js` | 10 | `prepare_svg_for_js` | SVG preview in media library |
| `wp_generate_attachment_metadata` | 10 | `generate_svg_metadata` | Read SVG dimensions for metadata |
| `send_headers` | 10 | `send_security_headers` | Global `X-Content-Type-Options: nosniff` |
| `template_redirect` | 10 | `maybe_send_svg_headers` | CSP on SVG attachment pages |

**Upload pipeline in `handle_upload_prefilter`:**
1. If not `.svg` extension → return unchanged (fast path for non-SVG)
2. `Validator::get_instance()->validate($tmp, $name, $size)` → on fail: log + set `$file['error']` + return
3. `Sanitizer::get_instance()->sanitize_file($tmp)` → on fail: log + set `$file['error']` + return
4. Log sanitization report
5. Return `$file` (WP proceeds with the sanitized tmp file)

**`fix_svg_mime_check` fix:**
```php
if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
    $data['ext']  = 'svg';
    $data['type'] = 'image/svg+xml';
}
return $data;
```

---

### `CodePros\SVGSecureSupport\Logger` — `src/Logger.php`

Writes to WP debug log (`error_log`) and custom DB table. Severity levels: `info`, `warning`, `critical`. Event types: `upload_blocked`, `upload_sanitized`, `upload_allowed`, `tag_removed`, `attribute_removed`, `suspicious_payload`.

Log line format:
```
[SVG Secure Support][CRITICAL][suspicious_payload] File: malicious.svg — javascript: in href. User: admin (ID:1) IP: 1.2.3.4
```

Respects `svgss_logging_enabled`, `svgss_log_to_wp_debug`, `svgss_log_to_database`, `svgss_log_level` options.

---

### `CodePros\SVGSecureSupport\Database` — `src/Database.php`

Called by `register_activation_hook`. Uses `dbDelta()`.

**Table schema `{prefix}svgss_security_log`:**
```sql
id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
event_type  VARCHAR(40)     NOT NULL,
severity    VARCHAR(10)     NOT NULL DEFAULT 'info',
filename    VARCHAR(255)    NOT NULL DEFAULT '',
details     TEXT            NOT NULL,
user_id     BIGINT UNSIGNED NULL,
user_login  VARCHAR(60)     NOT NULL DEFAULT '',
ip_address  VARCHAR(45)     NOT NULL DEFAULT '',
created_at  DATETIME        NOT NULL,
PRIMARY KEY (id), KEY severity, KEY event_type, KEY created_at
```

---

### `CodePros\SVGSecureSupport\Headers` — `src/Headers.php`

Sends on all pages: `X-Content-Type-Options: nosniff`. Sends on SVG attachment pages: `Content-Security-Policy`, `X-Frame-Options: SAMEORIGIN`.

Default CSP: `default-src 'self'; script-src 'none'; object-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:;`

---

### `CodePros\SVGSecureSupport\Rasterizer` — `src/Rasterizer.php`

Maximum-security option. After sanitization, converts the SVG to PNG (or WebP) and stores the raster image in its place. The original SVG file is either discarded or stored in a private location. The browser only ever receives a static raster image — all active SVG behavior is eliminated.

**Dependency detection at runtime (no Composer dependency added):**
```php
// Priority order: Imagick → GD
public function is_available(): bool {
    return extension_loaded('imagick') || (extension_loaded('gd') && function_exists('imagecreatefromstring'));
}
```

**Conversion method:**
```php
public function rasterize(string $svg_path, string $output_format = 'png'): array
// Returns: ['success' => bool, 'output_path' => string, 'mime' => string, 'error' => string]
```

**Imagick path (preferred — supports SVG natively via librsvg):**
```php
$imagick = new \Imagick();
$imagick->setBackgroundColor(new \ImagickPixel('transparent'));
$imagick->readImage($svg_path);
$imagick->setImageFormat($output_format === 'webp' ? 'webp' : 'png');
$imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
$imagick->writeImage($output_path);
```

**GD fallback path:**
- GD cannot natively parse SVG. If only GD is available, the Rasterizer performs an `exec()` call to `rsvg-convert` or `inkscape --export-png` (if available on the server) as a system command.
- If neither is available → `is_available()` returns false and rasterization is skipped (plugin logs a warning, falls back to sanitized SVG).

**Output naming:** Replace `.svg` extension with `.png` (or `.webp`). The attachment MIME type is updated in WP postmeta to `image/png` (or `image/webp`).

**Settings option:** `svgss_rasterize_mode` with values:
- `disabled` — skip rasterization, serve sanitized SVG (default)
- `always` — always convert to PNG/WebP, discard SVG
- `store_both` — store sanitized SVG privately, serve raster publicly

**Where it runs:** Called from `Hooks::handle_upload_prefilter()` after sanitization, before the file is moved to uploads. If rasterization succeeds, `$file['name']` is updated to the `.png` filename and `$file['type']` to `image/png`.

Settings sub-page under **Settings → SVG Secure Support**. Log viewer at **Settings → SVG Security Logs**. Uses WordPress Settings API (`register_setting`, `add_settings_section`, `add_settings_field`).

**Settings options:**

| Option | Default | Description |
|---|---|---|
| `svgss_upload_capability` | `manage_options` | Minimum WP capability to upload SVGs |
| `svgss_allowed_roles` | `['administrator']` | Roles permitted to upload |
| `svgss_max_file_size_kb` | `1024` | Max file size (KB) |
| `svgss_max_xml_nodes` | `5000` | Max DOM nodes |
| `svgss_max_dimension_px` | `10000` | Max px dimension |
| `svgss_strip_style_tags` | `1` | Strip `<style>` entirely |
| `svgss_strip_xml_comments` | `1` | Strip XML comments |
| `svgss_logging_enabled` | `1` | Enable logging |
| `svgss_log_to_wp_debug` | `1` | Write to WP debug log |
| `svgss_log_to_database` | `1` | Write to DB table |
| `svgss_log_retention_days` | `30` | Auto-purge after N days |
| `svgss_log_level` | `warning` | Min severity to log |
| `svgss_csp_enabled` | `1` | Send CSP headers |
| `svgss_csp_header` | (secure default) | Full CSP value |
| `svgss_clamav_enabled` | `0` | Enable ClamAV scan |
| `svgss_clamav_path` | `/usr/bin/clamscan` | Path to clamscan binary |
| `svgss_rasterize_mode` | `disabled` | Rasterization mode: `disabled`, `always`, `store_both` |
| `svgss_rasterize_format` | `png` | Output format: `png` or `webp` |

---

## Phased Implementation (Feature-by-Feature)

Each phase is independently shippable and testable before the next begins.

---

### Phase 1 — Upload Restriction
**Files:** `svg-secure-support.php`, `uninstall.php`, `composer.json`, `src/Hooks.php` (partial)
**Goal:** Bootstrap plugin. Gate SVG uploads to authorized roles only. Allow the MIME type through WP. Block everyone else.
**Delivers:**
- Plugin activates with proper header
- Constants defined
- `upload_mimes` → adds `image/svg+xml` for authorized users only
- `wp_check_filetype_and_ext` → fixes WP's broken SVG MIME detection
- `wp_handle_upload_prefilter` (priority 1) → `check_upload_capability()` rejects unauthorized users
- Option `svgss_upload_capability` (hardcoded default `manage_options` for now)
**Verify:** Log in as Subscriber → upload `.svg` → blocked. Log in as Admin → upload `.svg` → passes (raw, unsanitized for now).

---

### Phase 2 — Sanitization Engine
**Files:** `src/Validator.php`, `src/Sanitizer.php`, `src/Hooks.php` (full pipeline)
**Goal:** All uploaded SVGs are validated and sanitized before hitting the uploads directory.
**Delivers:**
- `SVG_Secure_Validator` — 5-check pipeline (extension, MIME, size, node count, dimensions)
- `SVG_Secure_Sanitizer` — DOM whitelist sanitization (5 phases: remove blocked tags → remove non-whitelisted tags → sanitize attributes → strip comments → string safety scan)
- `handle_upload_prefilter` (priority 10) wires validator + sanitizer into the upload pipeline
- Tmp file overwritten with clean SVG before WP moves it
- Upload errors returned to WP for display on rejected files
**Verify:** Run all 14 test cases (T1–T14 from verification table). Confirm clean SVGs pass, malicious SVGs are blocked or stripped.

---

### Phase 3 — Security Logging
**Files:** `src/Database.php`, `src/Logger.php`
**Goal:** Every security event (blocked upload, removed tag, suspicious payload) is recorded.
**Delivers:**
- `SVG_Secure_Database` — creates `{prefix}svgss_security_log` table on activation via `dbDelta()`
- `SVG_Secure_Logger` — writes to WP debug log and DB table
- Sanitizer and hooks call logger after every validation/sanitization event
- `log_sanitization_report()` iterates the sanitizer's report array and logs each removal
- `purge_old_logs()` method (called manually or on settings save for now)
**Verify:** Upload a malicious SVG → check `{prefix}svgss_security_log` table has rows. Check WP debug log has `[SVG Secure Support]` entries. Upload clean SVG → check `upload_allowed` `info` entry logged.

---

### Phase 4 — CSP Headers
**Files:** `src/Headers.php`, `uploads-htaccess.txt`
**Goal:** SVG files are served with security headers that neutralize any residual active content.
**Delivers:**
- `SVG_Secure_Headers` — `X-Content-Type-Options: nosniff` on all pages
- CSP headers on SVG attachment pages (`default-src 'self'; script-src 'none'; object-src 'none';`)
- `X-Frame-Options: SAMEORIGIN` on SVG attachment pages
- `uploads-htaccess.txt` snippet for Apache (disables PHP execution, sets SVG MIME type, adds headers via `mod_headers`)
**Verify:** Upload a clean SVG. Visit its attachment page URL. Run `curl -I https://site/?attachment_id=X` — confirm `Content-Security-Policy`, `X-Content-Type-Options`, `X-Frame-Options` headers present.

---

### Phase 5 — Rasterization (Maximum Security)
**Files:** `src/Rasterizer.php`, `src/Hooks.php` (rasterization hook)
**Goal:** Optionally convert every uploaded SVG to a raster image (PNG or WebP) so that absolutely no active SVG behavior ever reaches the browser.
**Delivers:**
- `Rasterizer::is_available()` detects Imagick or GD+rsvg at runtime
- `Rasterizer::rasterize(string $svg_path, string $format): array`
- Imagick path (preferred): converts via `Imagick::readImage()` + `setImageFormat()` + `writeImage()`
- GD fallback: delegates to `rsvg-convert` or `inkscape` via `exec()` if available
- `$file['name']`, `$file['type']` updated in the WP upload pipeline if rasterization succeeds
- `svgss_rasterize_mode` setting: `disabled` | `always` | `store_both`
- Warning logged if rasterization requested but no engine available
**Verify:** Enable rasterization in settings. Upload a clean SVG. Confirm only a `.png` file appears in the media library. Confirm no `.svg` in uploads directory (in `always` mode). Disable → confirm SVG uploads work as before.

---

### Phase 6 — Admin Settings UI
**Files:** `src/Admin/Admin.php`, `src/Admin/templates/page-settings.php`, `src/Admin/templates/page-logs.php`

**Goal:** Admin can configure all security options and review the security log without touching code.
**Delivers:**
- Settings page at **Settings → SVG Secure Support** (all `svgss_*` options from the table above)
- Log viewer at **Settings → SVG Security Logs** — paginated, filterable by severity and event type
- Log purge action button (purge entries older than `svgss_log_retention_days`)
- Plugin action link → Settings in the plugins list
- All hardcoded defaults replaced by live option reads
- Input sanitization for all `register_setting()` callbacks
**Verify:** Change upload capability to `upload_files`, save. Confirm an Editor can now upload SVGs. Change max file size to 10 KB, save. Confirm a 50 KB SVG is rejected. View log page — confirm all previous test events appear.

---

## Verification / Test Cases

Each test SVG should be uploaded through WP media uploader and result verified:

| Test | Payload | Expected |
|---|---|---|
| T1 Script tag | `<script>alert(1)</script>` | Tag removed, upload proceeds |
| T2 Event handler | `<svg onload="alert(1)">` | `onload` stripped, upload proceeds |
| T3 XXE | `<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>` | Upload blocked at parse stage |
| T4 External image | `<image xlink:href="http://evil.com/x.png"/>` | Tag removed (`image` not whitelisted) |
| T5 foreignObject | `<foreignObject><iframe src="evil.com"/></foreignObject>` | Element + children removed |
| T6 CSS javascript | `style="fill:url(javascript:alert(1))"` | Style value stripped |
| T7 CSS expression | `style="width:expression(alert(1))"` | Style value stripped |
| T8 Double extension | Filename `payload.php.svg` | Upload blocked at extension check |
| T9 Oversized | File > 1 MB | Upload blocked at size check |
| T10 Node flood | > 5000 elements | Upload blocked at node count check |
| T11 Local use | `<use href="#circle"/>` | Accepted, unchanged |
| T12 data: href | `href="data:text/html,<script>..."` | Attribute removed |
| T13 Non-admin upload | Subscriber role user | Upload blocked with permission error |
| T14 Legitimate SVG | Clean icon SVG | Accepted, no modifications |

After T6/T7 verify via `curl -I https://site/wp-content/uploads/x.svg` that CSP response headers are present.
