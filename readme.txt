=== CodePros SVG Secure Support ===
Contributors: codeprosai
Tags: svg, security, upload, sanitize, xss-protection
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Highly secure SVG upload support for WordPress. Validates, sanitizes, and protects SVG files through a multi-layer security pipeline.

== Description ==

WordPress does not support SVG uploads natively — and naive SVG plugins are a well-known attack surface. SVG files are XML documents that can carry XSS payloads, XXE attacks, external resource injection, and embedded HTML. **CodePros SVG Secure Support** adds safe, production-ready SVG uploads through a layered defense pipeline.

= Security Pipeline =

Every uploaded SVG passes through five sequential checks before it is accepted:

1. **Extension check** — Blocks double-extension filenames (e.g. `payload.php.svg`) and enforces `.svg` only.
2. **MIME check** — Verifies actual file bytes return `image/svg+xml` via `finfo`; confirms `<svg` or `<?xml` is present in the header bytes.
3. **Size check** — Rejects files exceeding the configured maximum (default 1 MB).
4. **Node-count check** — Parses the XML and counts DOM nodes; rejects files above the threshold (default 5,000 nodes) to prevent node-flood DoS attacks.
5. **Dimension check** — Reads the root `<svg>` width/height/viewBox; rejects unreasonably large declared dimensions (default 10,000 px).

After validation, the file is sanitized:

* DOM sanitization via the battle-tested **enshrined/svg-sanitize** library with custom tag and attribute whitelists.
* Remote reference stripping — all external URLs are removed.
* Final string-level regex scan for `javascript:`, `<script`, inline event handlers (`on*=`), and CSS `expression()` — any match causes the upload to be rejected entirely.

= Security Headers =

When SVG attachment pages are served, the plugin adds:

* `Content-Security-Policy` (configurable, secure default provided)
* `X-Content-Type-Options: nosniff`
* `X-Frame-Options: SAMEORIGIN`

= Server-Level Hardening (Optional but Recommended) =

The plugin's PHP layer covers every SVG upload that passes through WordPress. But if someone accesses an uploaded file directly — e.g. by visiting `https://example.com/wp-content/uploads/2024/01/logo.svg` — WordPress is bypassed entirely, so the PHP security headers are never sent.

The plugin ships two ready-to-use server configuration snippets to close that gap:

* **`uploads-htaccess.txt`** — for Apache / LiteSpeed servers
* **`uploads-nginx.conf`** — for Nginx servers

Each snippet does three things:

1. **Blocks server-side script execution** in `wp-content/uploads/` — if an attacker somehow uploads a `.php` file and tries to access it directly, the server returns 403 instead of executing it.
2. **Enforces the correct SVG MIME type** (`image/svg+xml`) — some server setups serve SVGs as `text/plain`, which prevents browsers from honouring Content Security Policy rules scoped to that MIME type.
3. **Adds security headers on direct SVG requests** — the same `X-Content-Type-Options`, `X-Frame-Options`, and `Content-Security-Policy` headers that the PHP layer adds on WordPress attachment pages, so direct file links are equally protected.

Applying these snippets is the difference between WordPress-mediated access being protected and *all* access (direct URL, CDN pull, hotlink) being protected.

= Admin UI =

A tabbed settings page under **Settings → SVG Secure Support** provides:

* **Settings tab** — Configure upload capability, file size/node/dimension limits, sanitization options, CSP header value, and logging preferences.
* **Security Logs tab** — Paginated, filterable log viewer showing every security event (blocked upload, removed tag/attribute, suspicious payload). Includes a log purge action.

= Key Features =

* Capability-gated uploads — restrict SVG uploads to any WordPress capability (default: `manage_options`)
* Automatic upload-time sanitization — clean SVG replaces the original tmp file before WordPress moves it
* Security event logging to the WordPress debug log and a dedicated database table
* Configurable log retention with one-click purge
* Bundled `.htaccess` and Nginx config snippets for the uploads directory

== Installation ==

= Minimum Requirements =

* WordPress 6.0 or higher
* PHP 7.4 or higher
* Composer (to install dependencies before activation)

= Installation Steps =

1. Upload the `svg-secure-support` folder to `/wp-content/plugins/`.
2. In the plugin directory, run:
   `composer install`
3. Activate the plugin through the **Plugins** screen in WordPress.
4. Go to **Settings → SVG Secure Support** to configure upload capability, limits, and logging.

**Important:** The plugin will not activate correctly without the Composer dependencies. An admin notice will be displayed if `vendor/autoload.php` is missing.

= Uploading via the WordPress Admin =

After activation, simply upload `.svg` files through the standard WordPress Media Library. Users without the required capability will receive a clear error message.

== Frequently Asked Questions ==

= Who can upload SVG files after activation? =

By default, only users with the `manage_options` capability (Administrators). You can change this to any WordPress capability — for example `upload_files` to allow Editors and Authors — under **Settings → SVG Secure Support → Upload Capability**.

= Does this plugin make SVG uploads completely safe? =

The plugin implements every layer recommended by security researchers: validation → DOM sanitization with a strict tag/attribute whitelist → string-level payload scan → Content Security Policy headers. No sanitization approach can offer an absolute guarantee, but this multi-layer pipeline eliminates all known SVG attack vectors.

= What happens to a malicious SVG? =

It depends on where the threat is detected:

* **Validation failures** (wrong extension, wrong MIME, too large, too many nodes) — upload is blocked entirely with an error message shown to the user.
* **Sanitizable content** (disallowed tags or attributes) — the content is stripped and the cleaned SVG is accepted.
* **Unsanitizable payloads** (e.g. `javascript:` survives DOM traversal) — upload is blocked entirely.

All outcomes are recorded in the security log.

= Will this slow down my site? =

The validation and sanitization pipeline runs only during file uploads, not on page loads. There is no frontend performance impact. The security headers are lightweight HTTP headers added on SVG attachment pages only (except `X-Content-Type-Options: nosniff`, which is sent on all pages).

= Do I need to configure Apache or Nginx separately? =

It is strongly recommended. Without the server-level snippets, only requests routed through WordPress are protected. A direct URL to an uploaded SVG bypasses all PHP-layer security headers.

**Apache — applying `uploads-htaccess.txt`**

1. Open (or create) `wp-content/uploads/.htaccess` on your server.
2. Copy the entire contents of `uploads-htaccess.txt` (found in the plugin directory) and append them to that file.
3. Save. Apache picks up `.htaccess` changes immediately — no restart needed.
4. Note: WordPress may overwrite `uploads/.htaccess` when you save Permalink settings. Re-apply the snippet after that happens, or add the directives to your main Apache `VirtualHost` block so they cannot be overwritten.

Requires `mod_headers` to be enabled on your Apache installation (most managed hosts have it). The snippet also uses `mod_mime`, which is enabled by default.

**Nginx — applying `uploads-nginx.conf`**

1. Open your site's Nginx configuration file. On a typical Linux server this is `/etc/nginx/sites-available/<your-site>.conf`. In **Local by Flywheel** the per-site config is at `~/Local Sites/<site-name>/conf/nginx/site.conf.hbs`.
2. Copy the two `location` blocks from `uploads-nginx.conf` and paste them inside the `server {}` block, **before** the generic `location /` block.
3. Reload Nginx: `sudo nginx -s reload` (or restart the site from the Local app).

**What changes when you apply these files**

| Scenario | Without snippets | With snippets |
|---|---|---|
| Direct URL to uploaded `.svg` | No CSP, no `X-Frame-Options` | Full security headers applied |
| Direct URL to uploaded `.php` disguised as SVG | PHP executes (server-dependent) | 403 Forbidden |
| WordPress attachment page for an SVG | Protected by plugin PHP headers | Protected by both PHP and server headers |
| SVG served via CDN pull / hotlink | No headers | Server headers applied before CDN caches the response |

= What is logged? =

The following event types are recorded:

* `upload_allowed` — SVG passed all checks
* `upload_sanitized` — SVG was cleaned before being saved
* `upload_blocked` — SVG was rejected
* `tag_removed` — A disallowed tag was stripped
* `attribute_removed` — A disallowed attribute was stripped
* `suspicious_payload` — A `javascript:` or similar payload was detected

= How do I view and manage logs? =

Go to **Settings → SVG Secure Support** and click the **Security Logs** tab. You can filter by severity (Info / Warning / Critical) and event type, and purge entries older than the configured retention period.

= Does the plugin work with Multisite? =

The plugin has not been tested on WordPress Multisite. Network-wide activation is not currently supported.

== Screenshots ==

1. **Settings tab** — Upload restrictions, sanitization options, CSP header, and logging configuration.
2. **Security Logs tab** — Paginated log viewer with severity and event-type filters.

== Changelog ==

= 1.0.0 =
* Initial release.
* Upload capability gate with configurable capability requirement.
* Five-check SVG validation pipeline (extension, MIME, size, node count, dimensions).
* DOM sanitization via enshrined/svg-sanitize with custom tag/attribute whitelists.
* String-level XSS payload scan as a final defense layer.
* Security event logging to WP debug log and database table.
* CSP, X-Content-Type-Options, and X-Frame-Options headers on SVG attachment pages.
* Tabbed admin UI with settings and security log viewer.
* Bundled Apache and Nginx upload-directory hardening snippets.

== Upgrade Notice ==

= 1.0.0 =
Initial release. No upgrade steps required.
