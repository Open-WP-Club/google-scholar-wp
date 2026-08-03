# Browser-Assisted Update Mode — Design

## Problem

The plugin currently fetches Google Scholar data exclusively via server-side
`wp_remote_get()` calls (`includes/scraper.php`). Many shared-hosting
providers get their outbound IPs rate-limited or blocked by Google Scholar
(HTTP 403/429), which the plugin already detects and reports in detail
(`Scraper::handle_http_error()`), but cannot work around — there is no
outbound request path available on that hosting.

Site admins on such hosts have no way to populate profile data at all today.

## Goal

Let an admin choose, per site, between:

- **Server mode** (current behavior): automatic cron-based scraping from the
  server's IP.
- **Browser mode** (new): the admin's own browser fetches the Scholar pages
  (using their own IP, not the server's) and feeds the extracted data back
  into WordPress manually. Not automatic, but works on hosts where outbound
  scraping is blocked.

Both browser-mode delivery paths (bookmarklet and manual copy-paste) must
work without a browser extension, without a new REST endpoint, and without
API keys — avoiding CORS entirely by never having the browser talk directly
to the WordPress site from the scholar.google.com origin.

## Non-goals (v1)

- Co-author avatar downloads in browser mode (always skipped — decorative,
  and multiply per co-author, so not worth the extra requests). The main
  profile avatar *is* fetched: its URL travels with the imported data (already
  present in pasted HTML; the bookmarklet adds it as a plain string field,
  never fetching image bytes itself) and WordPress downloads it server-side
  with the existing `download_to_media_library()` — a single lightweight
  request to a different host than Scholar's HTML pages, much less likely to
  be blocked than the bulk scraping this mode exists to avoid.
- Any new authentication mechanism (API keys, REST routes, CORS headers).
- Automating the browser-mode flow itself — it remains a manual, admin-
  triggered action every time.

## Architecture

### 1. Update method setting

A new `update_method` key (`server` | `browser`, default `server`) is added
to the `scholar_profile_settings` option, sanitized in
`Settings::sanitize_settings()` alongside the existing fields.

- `Scheduler::activate()` and `Scheduler::reschedule()` check this setting:
  in `browser` mode, the `scholar_profile_update` cron event is **not**
  scheduled (no wasted attempts against a host that will just get blocked).
  Switching back to `server` mode re-schedules it as today.
- `views/settings-page.php`: when `update_method === 'browser'`, the existing
  "Manual Refresh" section (the button that triggers a server-side
  `wp_remote_get` scrape) is hidden and replaced by the new Import panel
  (see below). In `server` mode, the page looks exactly as it does today.

### 2. Import panel (browser mode only)

Rendered in `views/settings-page.php`, contains:

- Links that open `https://scholar.google.com/citations?user={id}&hl=en` and
  the subsequent `&cstart=N` pages in new tabs, for the manual copy-paste
  path.
- A generated bookmarklet link (drag-to-bookmarks-bar), built inline with
  the current `profile_id` and `max_publications` baked in — mirrors the
  existing copy-shortcode button pattern already on this page.
- One `<textarea>` + "Import" submit button, POSTing to a new
  `admin_post_import_scholar_profile` handler in `Settings`, guarded by
  `current_user_can('manage_options')` and a nonce — same pattern as
  `handle_manual_refresh()` / `handle_clear_stale_data()`.

### 3. Bookmarklet behavior

A small JS snippet (authored as a plain `.js` file in `assets/js/`, inlined
into the `javascript:` href at render time):

- Only meaningful when run on the admin's own public Scholar profile page.
- If the current page has `#gsc_prf_in` (the main profile page), extracts
  name, affiliation, interests, and the citations table — no avatar.
- Auto-paginates additional publications via same-origin
  `fetch('/citations?user=...&cstart=N&pagesize=20')` calls (same origin as
  the page it's running on, so no CORS) until `max_publications` is reached
  or a page returns fewer than `pagesize` rows.
- Combines everything into one JSON object matching the existing scraped-data
  shape (minus `avatar` fields).
- Copies the JSON to the clipboard via `navigator.clipboard.writeText()` and
  shows a small floating on-page notice (not `alert()`, which would block
  the page). The admin switches to their wp-admin tab and pastes into the
  Import textarea.

### 4. Backend parsing

No new PHP class. `Scraper` gains three public methods that wrap its
existing private parsing logic instead of duplicating it:

- `import_main_profile_html(string $html, string $profile_id, bool $skip_avatar_download = false)`
  — wraps `parse_main_profile_html()`; when `$skip_avatar_download` is true,
  `extract_profile_info()` skips the `download_to_media_library()` call.
- `import_publications_fragment_html(string $html): array`
  — thin wrapper over `extract_publications_from_html()`, for pagination-only
  pages/fragments that lack a `gsc_prf` profile container.
- `import_from_bookmarklet_json(string $json)` — validates the bookmarklet's
  JSON shape and maps it onto the same data array `scrape()` returns.

`Settings::handle_import_scholar_profile()` (new admin-post handler):

1. Verifies nonce + capability.
2. Detects the pasted content's format:
   - Valid JSON matching the bookmarklet schema → `import_from_bookmarklet_json()`.
   - HTML containing `gsc_prf` → `import_main_profile_html()` (replaces
     profile info + first page of publications).
   - HTML containing `gsc_a_tr` rows but no `gsc_prf` → treated as a
     pagination fragment, publications are **appended** to the existing
     stored list, deduplicated by `google_scholar_url`.
   - Anything else → validation error, existing data is left untouched
     (same fail-safe principle as the current scraper error handling).
3. Runs the result through the existing `Scraper::validate_scraped_data()`.
4. Stores via `update_option('scholar_profile_data', ...)` and marks status
   via `Scheduler::update_data_status('success', 'Imported via browser on {date}')`
   so the admin can tell how the last update happened.

### 5. Error handling

Invalid or unrecognized pasted content produces a clear admin notice
("This doesn't look like a Google Scholar profile page") without touching
existing stored data — consistent with how failed server-side scrapes
already preserve prior data (`Scheduler::handle_scraping_failure()`).

## Testing

PHPUnit + Brain Monkey, following the existing patterns in
`tests/Unit/ScraperTest.php` and `tests/Unit/SchedulerTest.php`:

- `Settings::sanitize_settings()` accepts/normalizes the new `update_method`
  field.
- `Scraper::import_main_profile_html()` / `import_publications_fragment_html()`
  against fixture HTML (reuse existing scraper test fixtures where possible).
- Append + dedupe logic when importing a second pagination fragment that
  overlaps with already-stored publications.
- `Scraper::import_from_bookmarklet_json()` rejects malformed/missing-field
  JSON.
- `Scheduler::activate()` does not schedule the cron event when
  `update_method === 'browser'`, and does when `'server'`.
