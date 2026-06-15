# Session Context — codepros-svg-secure-support

Date: 2026-06-15

---

## 1. Role-Based SVG Upload Capability

### What Changed

Replaced the single free-text capability field (`cpsvgss_upload_capability`) with a multi-role checkbox field (`cpsvgss_allowed_roles`).

### Files Modified

**`src/Admin/Admin.php`**
- `register_settings()` — builds WordPress role list dynamically via `wp_roles()->roles`, registers `cpsvgss_allowed_roles` as a `multicheck` field defaulting to `['administrator']`. Sanitize callback rejects any slug not present in WP's actual role list.
- `field()` helper — maps `multicheck` type → `'array'` when calling `register_setting` so WordPress serializes the value correctly.
- `render_field()` — new `multicheck` case renders one checkbox per role using `name="cpsvgss_allowed_roles[]"`.

**`src/Hooks.php`**
- Removed `upload_capability(): string` helper.
- Added `user_can_upload_svg(): bool` — reads saved role array, intersects with `wp_get_current_user()->roles`, returns `true` on match. Falls back to administrator-only if stored value is empty or invalid.
- `allow_svg_mime()` and `check_upload_capability()` both call `$this->user_can_upload_svg()`.

### Why It's More Secure

- No free-text input means no misconfiguration risk (e.g. typing `upload_files` and accidentally allowing all subscribers).
- Sanitize callback strips any role slug not in WP's role registry.
- Role intersection check is server-side on `wp_get_current_user()->roles` — cannot be spoofed by the client.
- Safe fallback to `['administrator']` if saved value is empty or corrupted.

---

## 2. Plugin Security Assessment

### Defense Layers (All Implemented)

| Layer | Class | What It Does |
|---|---|---|
| Access control | `Hooks` | Role-based gate; non-allowed roles never reach the upload pipeline |
| Validation | `Validator` | 5 checks: extension, MIME (finfo), file size, XML node count (DoS), dimensions (DoS) |
| Sanitization | `Sanitizer` + `AllowedTags` + `AllowedAttributes` | `enshrined/svg-sanitize` with strict whitelist, remote ref blocking, comment stripping, regex backstop |
| HTTP headers | `Headers` | `X-Content-Type-Options: nosniff` (all pages), CSP + `X-Frame-Options` (SVG attachment pages) |
| Logging | `Logger` | Structured events (severity, user, IP, filename) to WP debug log and/or DB |

### Covered Attack Vectors

- XSS via `<script>`, event handlers (`onclick`, `onload`, etc.), `javascript:` URIs
- XXE — `LIBXML_NONET` + loads from string, not file URL
- MIME spoofing — real `finfo` check, not filename-only
- Double-extension bypass — `payload.php.svg` blocked
- Remote resource loading — `removeRemoteReferences(true)` in enshrined
- DoS via node-flood or dimension-bomb — node count + dimension limits
- Content injection via XML comments — stripped by default

### Known Gaps

| Gap | Risk Level | Recommended Fix |
|---|---|---|
| `style` attribute allowed in `AllowedAttributes` | Medium | Remove `style` from `AllowedAttributes::getAttributes()`, or add `url\s*\(.*javascript/i` to `scan_for_payloads()` |
| `vbscript:` not in regex scan | Low (old IE) | Add `/vbscript\s*:/i` to `Sanitizer::scan_for_payloads()` |
| `data:text/html` in href not scanned | Low | Add `/data\s*:\s*text\/html/i` to `Sanitizer::scan_for_payloads()` |
| Default CSP uses `style-src 'unsafe-inline'` | Low | Change `DEFAULT_CSP` in `Headers.php` and `Admin.php` to `style-src 'none'` |
| No upload rate limiting | Low (needs valid role) | Handle at server/WAF level — outside WP plugin scope |
| IP logging trusts `X-Forwarded-For` | Informational | Low risk — IP is for logging only, not security decisions |

### Verdict

Production-ready for real-world use. Covers the top SVG attack vectors. The highest-priority remaining hardening is removing `style` from `AllowedAttributes` and tightening the default CSP to `style-src 'none'`.
