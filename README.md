# CodePros SVG Secure Support

Highly secure SVG upload support for WordPress. Validates, sanitizes, and optionally rasterizes SVG files through a multi-layer security pipeline.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Composer

## Installation

1. Copy the plugin directory to `wp-content/plugins/svg-secure-support/`.
2. Run `composer install` inside the plugin directory.
3. Activate the plugin from **Plugins → Installed Plugins**.

## Security Pipeline

SVG files pass through three layers on upload:

1. **Validation** — Extension check (blocks double-extension filenames like `payload.php.svg`), MIME sniffing, file size, XML node count, and dimension limits.
2. **Sanitization** — DOM sanitization via [`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitizer) with a strict tag and attribute whitelist, followed by a string-level regex scan for `javascript:`, `<script`, event handlers, and CSS `expression()`.
3. **Headers** — `Content-Security-Policy`, `X-Content-Type-Options: nosniff`, and `X-Frame-Options: SAMEORIGIN` on SVG attachment pages.

An optional fourth layer, **Rasterization**, converts sanitized SVGs to PNG or WebP so no active SVG content ever reaches the browser.

## Settings

**Settings → SVG Secure Support**

| Option | Default | Description |
|---|---|---|
| Upload capability | `manage_options` | Minimum WP capability required to upload SVGs |
| Allowed roles | `administrator` | Roles permitted to upload |
| Max file size | 1024 KB | |
| Max XML nodes | 5000 | |
| Max dimension | 10000 px | |
| Strip `<style>` tags | Yes | |
| Strip XML comments | Yes | |
| Logging | Enabled | Writes to WP debug log and/or DB table |
| Log retention | 30 days | |
| CSP headers | Enabled | Sent on SVG attachment pages |
| ClamAV scan | Disabled | Requires `clamscan` binary on the server |
| Rasterization | Disabled | `disabled` / `always` / `store_both` |
| Rasterization format | PNG | `png` or `webp` |

## Security Log

**Settings → SVG Security Logs** — paginated log viewer filterable by severity and event type. Events include `upload_blocked`, `upload_sanitized`, `upload_allowed`, `tag_removed`, `attribute_removed`, and `suspicious_payload`.

The log table (`{prefix}svgss_security_log`) is created on activation and dropped on uninstall along with all plugin options.

## Rasterization

When enabled, the plugin converts the sanitized SVG to a raster image (PNG or WebP) before it is stored. The browser never receives an SVG file.

- **Imagick** (preferred) — requires the `imagick` PHP extension with librsvg support.
- **GD fallback** — delegates to `rsvg-convert` or `inkscape` via `exec()` if available.

If no rasterization engine is detected, the plugin logs a warning and falls back to serving the sanitized SVG.

## Development

```bash
composer install
composer dump-autoload --optimize
```

See `PLAN.md` for the phased implementation plan and `CLAUDE.md` for codebase guidance.

## License

GPL v2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
