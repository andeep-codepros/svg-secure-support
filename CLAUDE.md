# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Setup

Install Composer dependencies before activating the plugin:

```bash
composer install
```

The plugin will display an admin notice and bail early if `vendor/autoload.php` is missing.

## Development Commands

```bash
# Install / update dependencies
composer install
composer update

# Regenerate the optimized autoloader after adding new classes
composer dump-autoload --optimize
```

There is no build step, test runner, or linter configured in this project. WordPress's own test infrastructure (phpunit via wp-env) can be layered on if needed, but none is wired up here.

## Architecture

**WordPress plugin** with PHP 7.4+ compatibility. PSR-4 autoloaded under the `CodePros\SVGSecureSupport\` namespace (`src/` maps to that namespace root). All classes use a private-constructor singleton (`get_instance()`).

### Bootstrap flow (`svg-secure-support.php`)

1. Defines constants (`CODEPROS_SVGSS_*`) and loads `vendor/autoload.php`.
2. `register_activation_hook` → `Database::install()` creates the log table via `dbDelta()`.
3. `plugins_loaded` action → instantiates and calls `init()` on `Hooks`, `Headers`, and `Admin\Admin`.

### Class responsibilities

| Class | File | Role |
|---|---|---|
| `Hooks` | `src/Hooks.php` | All WP action/filter wiring. No business logic — delegates to Validator, Sanitizer, Logger, Rasterizer. |
| `Headers` | `src/Headers.php` | CSP + `X-Content-Type-Options` / `X-Frame-Options` headers (Phase 4). |
| `Database` | `src/Database.php` | Log table creation via `dbDelta()` on activation (Phase 3). |
| `Admin\Admin` | `src/Admin/Admin.php` | Settings page + log viewer under Settings → SVG Secure Support (Phase 6). |
| `Validator` | `src/Validator.php` | 5-check upload pipeline: extension → MIME → size → node count → dimensions. *(not yet implemented)* |
| `Sanitizer` | `src/Sanitizer.php` | Thin wrapper around `enshrined/svg-sanitize` + final string-level safety scan. *(not yet implemented)* |
| `AllowedTags` | `src/AllowedTags.php` | Custom `TagInterface` for the enshrined sanitizer whitelist. *(not yet implemented)* |
| `AllowedAttributes` | `src/AllowedAttributes.php` | Custom `AttributeInterface` for the enshrined sanitizer whitelist. *(not yet implemented)* |
| `Logger` | `src/Logger.php` | Security event logging to WP debug log and DB. *(not yet implemented)* |
| `Rasterizer` | `src/Rasterizer.php` | Optional SVG → PNG/WebP conversion via Imagick or GD+rsvg (Phase 5). *(not yet implemented)* |

### Upload pipeline (Hooks)

`wp_handle_upload_prefilter` runs in two passes:
- **Priority 1** (`check_upload_capability`) — capability gate, short-circuits for unauthorized users.
- **Priority 10** (`handle_upload_prefilter`) — Validator → Sanitizer → Logger → optionally Rasterizer. Sets `$file['error']` on rejection so WP surfaces the message to the user.

The `wp_check_filetype_and_ext` filter (`fix_svg_mime_check`) is required because `getimagesize()` returns false for SVG, which causes WP to reject the file after sanitization.

### Security model

Validation (`Validator`) blocks before sanitization. Sanitization (`Sanitizer`) delegates DOM traversal entirely to `enshrined/svg-sanitize` with custom tag/attribute whitelists, then applies a string-level regex safety scan as a final backstop. Headers (`Headers`) add CSP on SVG attachment pages as a last-resort layer.

### Phased implementation status

The plugin is being built in 6 phases (see `PLAN.md`). Currently **Phase 1** is implemented (MIME allow-listing, capability gate, SVG media library preview). Phases 2–6 (Validator, Sanitizer, Logger, Headers, Rasterizer, Admin UI) are stub classes awaiting implementation.

## Conventions

- **Option prefix:** `svgss_`
- **Constant prefix:** `CODEPROS_SVGSS_`
- **Table name:** `{prefix}svgss_security_log`
- **Text domain:** `codepros-svg-secure-support`
- Settings page slug: `codepros-svg-secure-support`
- `vendor/` is generated — do not commit it.
