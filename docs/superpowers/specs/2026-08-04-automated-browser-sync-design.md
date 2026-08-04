# Automated Browser-Assisted Sync — Design

Follow-up to `2026-08-03-browser-assisted-update-design.md` (Browser mode:
manual copy-paste + bookmarklet, admin pastes into an Import textarea by
hand). This adds a fully unattended path on top of the same Browser mode,
for admins who don't want to repeat the manual paste every time.

## Problem

Browser mode (bookmarklet or manual paste) already works around hosts whose
outbound IP is blocked by Google Scholar, but it requires the admin to
manually open the profile, run the bookmarklet, switch tabs, and paste —
every single time they want fresh data. There is no way to keep data current
without that manual ritual, short of a real browser extension (explicitly
out of scope, see prior design's non-goals) or routing scraping through the
already-blocked server IP.

## Goal

Let an admin generate one downloadable script from the plugin's settings
page, add a single line to their own computer's `cron`/`launchd`, and have
their Scholar profile stay in sync automatically — using their own
(non-blocked) network, no browser extension, no manual paste after the
one-time setup.

## Non-goals

- Running the sync process on the WordPress host itself (defeats the
  purpose — that IP is the one getting blocked).
- Any new HTML-parsing logic. The sync script fetches raw HTML only; all
  parsing is the existing `Scraper`/`Settings::build_import_data()` code
  used by the manual browser-paste path.
- A hosted/cloud scheduler (e.g. GitHub Actions). Those run from datacenter
  IP ranges, which face the same blocking risk as the WP host itself.
- Headless-browser automation (Playwright/Puppeteer). Google Scholar's
  profile pages are plain server-rendered HTML; a real browser adds a heavy
  dependency (bundled Chromium) for no parsing benefit over `curl`.

## Architecture

### 1. New REST endpoint

`includes/rest-api.php` (new `WPScholar\RestApi` class), registered via
`rest_api_init`:

`POST /wp-json/wp-google-scholar/v1/import`

- `permission_callback`: `current_user_can('manage_options')`. WordPress
  authenticates the request via HTTP Basic Auth (username + Application
  Password) before the permission callback runs — this is core WP REST API
  behavior, no custom auth code needed.
- Rejects with `403` if not served over HTTPS (`is_ssl()`), and if
  `update_method !== 'browser'` (mirrors the existing check in
  `handle_manual_refresh()` / `handle_import_scholar_profile()` — a stale
  script must not silently scrape once an admin switches back to Server
  mode).
- Accepts `content` (raw HTML) and `import_mode` (`replace` | `append`),
  identical contract to the existing `scholar_import_content` /
  `scholar_import_mode` POST fields.
- Delegates directly to the existing `Settings::build_import_data()` —
  same validation, append/dedupe, and error-shape logic as the manual
  browser-paste flow. No parsing logic is duplicated.
- Returns `WP_REST_Response`/`WP_Error` JSON instead of the admin-post
  handler's redirect-based flow. On success, calls
  `Scheduler::update_data_status('success', 'Imported via automated sync at %s ...')`
  — a distinct message from the manual-paste success message, so the
  Data Status panel shows *how* the last update happened.

### 2. Downloadable sync script

`assets/tools/scholar-sync.sh.tpl` — a bash template (curl-only, no
runtime dependencies beyond `curl` and `bash`) with placeholders:
`__SITE_URL__`, `__WP_USER__`, `__APP_PASSWORD__`, `__PROFILE_ID__`,
`__MAX_PUBLICATIONS__`.

Behavior, mirroring `assets/js/scholar-bookmarklet.js`'s
`collectAllPublications()`:

1. `curl` the main profile page (`citations?user=ID&hl=en`), POST it to the
   REST endpoint with `import_mode=replace`.
2. `curl` `&cstart=20`, `&cstart=40`, ... POSTing each with
   `import_mode=append`, stopping when a page returns fewer than 20 rows or
   `__MAX_PUBLICATIONS__` is reached (same loop shape as the bookmarklet's
   `next()`).
3. On any curl failure or non-2xx/error-shaped JSON response: log to
   `scholar-sync.log` next to the script and exit non-zero without
   continuing — existing data is left untouched (validation already lives
   server-side in `build_import_data()` / `validate_scraped_data()`).
4. A PID lockfile next to the script prevents overlapping runs if a
   previous invocation is still in flight.
5. Supports `--dry-run` (fetch and print, no POST) for the admin to verify
   locally before adding it to cron.

### 3. Generating and downloading the script

New admin-post handler `Settings::handle_download_sync_script()`
(`admin_post_download_scholar_sync_script`), triggered by a "Download Sync
Script" button in the Browser mode settings panel:

1. Verifies nonce + `current_user_can('manage_options')`.
2. Revokes any previously generated sync Application Password for the
   current user (tracked by a fixed label prefix, e.g.
   `Scholar Auto-Sync ...`), via `WP_Application_Passwords::delete_application_password()`.
3. Creates a new Application Password via
   `WP_Application_Passwords::create_new_application_password()`, labeled
   `Scholar Auto-Sync (<date>)`.
4. Reads `scholar-sync.sh.tpl`, replaces the placeholders with
   `home_url()`, the current user's `user_login`, the new Application
   Password, and the configured `profile_id` / `max_publications`.
5. Streams the result as a file download
   (`Content-Disposition: attachment; filename="scholar-sync.sh"`,
   `Content-Type: text/x-sh`) — nothing is written to disk server-side.

The settings panel also lists currently active sync credentials (by label,
reusing `WP_Application_Passwords::get_user_application_passwords()`) with
a per-credential "Revoke" button, so an admin can invalidate a downloaded
script without hunting through `Users → Profile`.

**Security note (accepted tradeoff):** the downloaded file contains a live
credential in plain text, scoped to the downloading admin's own
capabilities (`manage_options` — as broad as the existing manual-refresh
and import handlers already require). The settings panel shows an explicit
warning next to the download button ("contains a credential — keep it
private, do not commit it to version control"). Re-clicking "Download"
rotates the credential (old one revoked, new one issued), so a forgotten
or leaked script can be neutralized by generating a fresh download.

### 4. Error handling

| Situation | Behavior |
|---|---|
| Application Password revoked/invalid | REST endpoint returns `401`; script logs and exits, no data touched |
| `update_method` switched back to `server` | REST endpoint returns `403 browser_mode_required`; script logs and exits |
| Scholar rate-limits/blocks the fetch (429/403) | Script stops the run, logs, leaves stored data untouched; next scheduled run retries from scratch |
| `replace` succeeds, a later `append` fails | Already-appended pages remain stored (each page is its own committed update via the existing append/dedupe path); next run's `replace` step re-synchronizes from page one |
| `validate_scraped_data()` fails (e.g. Scholar served a captcha page) | REST endpoint returns a structured JSON error; `update_data_status('error', ...)` fires exactly as it does for manual paste; script logs the message locally |
| Overlapping cron runs | 15s replace cooldown (existing `IMPORT_REPLACE_COOLDOWN_SECONDS`) plus a script-side PID lockfile |

## Testing

- `tests/Unit/RestApiTest.php` (PHPUnit + Brain Monkey, following existing
  patterns in `tests/Unit/`): permission gating (capability, browser mode,
  HTTPS), successful `replace`/`append` delegating to a mocked
  `build_import_data()`, correct HTTP status/JSON shape per error type.
- `tests/Unit/SettingsTest.php` additions for
  `handle_download_sync_script()`: Application Password created and prior
  one revoked, template placeholders substituted correctly, response
  headers correct for a file download.
- The bash script itself is not unit tested (no PHPUnit equivalent); its
  `--dry-run` flag is the admin-facing verification tool. The HTML parsing
  it depends on is already covered by existing `ScraperTest`/`SettingsTest`
  coverage for the manual browser-paste path.
- Manual check before merge: generate the script from a local dev site's
  settings panel, run it once by hand, confirm publications/profile update
  and the Data Status panel reads "Imported via automated sync".
