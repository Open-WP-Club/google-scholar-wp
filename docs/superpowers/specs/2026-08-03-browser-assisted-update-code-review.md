# Code Review — Browser-Assisted Update Mode

Review of the diff implementing `docs/superpowers/specs/2026-08-03-browser-assisted-update-design.md`
(`git diff origin/main...HEAD` + uncommitted follow-up). 8 finder angles, 13
candidates, all verified individually. 10 findings kept, ranked most severe
first. Nothing has been fixed yet — this is the raw report for review.

## 1. [Critical] Pasted pagination pages replace instead of append

**File:** `includes/settings.php:385`

`build_import_data()` checks for `'gsc_prf'` before `'gsc_a_tr'`, but every
Scholar citations page — including `&cstart=20`, `&cstart=40` pagination
pages — renders the full profile template, `gsc_prf` sidebar included. The
append/fragment branch (`gsc_a_tr`-only) is effectively unreachable for any
real Scholar page.

**Failure scenario:** Admin pastes the main profile page (imports 20
publications), then follows the UI's own instructions
(`views/settings-page.php`) to paste the `&cstart=20` page to get more
publications. Since that page also contains `gsc_prf`, `build_import_data()`
routes it back into `import_main_profile_html()`, which overwrites
`$data['publications']` with just that page's ~20 rows — silently discarding
the first 20 already imported.

## 2. [High] Manual-refresh handler bypasses browser-mode gating

**File:** `includes/settings.php:132`

`handle_manual_refresh()` (`admin_post_refresh_scholar_profile`) is never
gated by the `update_method` setting. Switching to "browser" mode only hides
its button in the UI and stops the cron job — the handler itself still runs
a full server-side `wp_remote_get` scrape if invoked.

**Failure scenario:** Admin switches to Browser mode on a host whose IP gets
blocked by Google Scholar on any scrape attempt. A stale/cached browser tab
still showing the old "Manual Refresh" form (with its still-valid ~24h
nonce) submits to `admin-post.php?action=refresh_scholar_profile` — the
handler has no `update_method` check, so it scrapes server-side anyway,
defeating the entire purpose of the mode.

## 3. [Medium-High] Stale error shown instead of the real validation failure

**File:** `includes/settings.php:544`

`get_import_error_message()` reads `scholar_profile_last_error_details`
before checking the current `error_type`, but the `validate_scraped_data()`
failure branch (`includes/settings.php:321-327`) never updates or clears
that option.

**Failure scenario:** A prior server-side scrape failed with a 403
`blocked_access` error (stored in `scholar_profile_last_error_details`).
Admin switches to Browser mode and pastes content that parses but yields 0
publications while existing data has some, so `validate_scraped_data()`
fails — the settings page shows the old "Server Access Blocked (HTTP 403)…"
message instead of "The imported data did not look complete enough to
save."

## 4. [Medium] Bookmarklet publications skip per-item validation

**File:** `includes/scraper.php:939`

`import_from_bookmarklet_json()` only checks that `publications` is an
array, never that individual entries have the keys downstream code needs —
unlike the HTML-parsing path, which always produces every key.
`includes/shortcode.php` accesses `google_scholar_url`, `title`, `year`,
`citations`, and `profile_url` without `isset()`/`??` guards.

**Failure scenario:** An admin runs a stale cached bookmarklet from before a
plugin update (or hand-edits pasted JSON) that omits e.g.
`google_scholar_url` on a publication. It passes `validate_scraped_data()`
(which only checks non-emptiness) and gets saved; `shortcode.php` then calls
`esc_url($pub['google_scholar_url'])` unconditionally, producing a PHP 8
warning and a broken empty `href` on the front end.

## 5. [Low-Medium] New profiles with zero publications rejected on import

**File:** `includes/scraper.php:1211`

`validate_scraped_data()`'s required-field loop treats an empty
`publications` array as a missing field (PHP's `empty([])` is `true`)
before reaching its own later zero-publications-for-new-profiles allowance.
This is pre-existing logic (unchanged by this PR, already documented by an
existing test), but it's now reachable through the new browser-import flow
whose target audience — first-time setups on blocked hosts — is especially
likely to hit it.

**Failure scenario:** A researcher with a brand-new, empty Scholar profile
pastes valid profile HTML via Browser mode. Profile info parses fine but
`publications` is `[]`; `validate_scraped_data()` short-circuits at the
required-fields check and the import is rejected with a generic
"validation_failed" message instead of being accepted as a legitimate
empty-profile import.

## 6. [Low-Medium] Failed import doesn't update the data status

**File:** `includes/settings.php:321`

`handle_import_scholar_profile()`'s `validate_scraped_data()` failure branch
never calls `Scheduler::update_data_status()`, unlike
`handle_manual_refresh()`'s equivalent failure branch.

**Failure scenario:** A browser import fails validation after a previously
successful import. The stored status stays `'success'` from the prior
attempt, so the "Data Status Warning" banner never reflects the failed
attempt, and the admin has no in-UI signal that their last import didn't
actually take effect.

## 7. [Low-Medium] Import handler has no rate-limit unlike manual refresh

**File:** `includes/settings.php:284`

`handle_import_scholar_profile()` has no cooldown/rate-limit check, unlike
`handle_manual_refresh()`'s 5-minute `REFRESH_COOLDOWN_SECONDS` guard — only
capability and nonce checks gate it.

**Failure scenario:** A double-click or browser back-button resubmit of the
import form fires repeated back-to-back avatar-download attempts
(`wp_remote_get`/`download_url` to googleusercontent.com) and DOM parses
with no throttle, unlike every other mutating handler in this file.

## 8. [Low] Pasted HTML is DOM-parsed twice

**File:** `includes/settings.php:385`

For the full-profile-page branch, `build_import_data()` calls
`import_main_profile_html()` (builds a `DOMDocument`/`DOMXPath` over
`$content`) and then also `import_publications_fragment_html()` (builds a
second, independent `DOMDocument`/`DOMXPath` over the identical `$content`)
— a duplicate full parse of the same HTML string that the pre-existing
`scrape()` path doesn't have.

**Failure scenario:** An admin pastes a large "select-all" copy of their
full Scholar profile page HTML; the string is parsed twice via libxml on
every Import submission instead of once, wasting CPU proportional to page
size for no functional benefit.

## 9. [Low] Bookmarklet link built even when unused in server mode

**File:** `includes/settings.php:536`

`render_settings_page()` unconditionally computes `build_bookmarklet_href()`
(`file_exists` + `file_get_contents` + two `preg_replace` passes +
`rawurlencode` over the ~180-line JS asset) even though `$bookmarklet_href`
is only ever echoed inside the `update_method === 'browser'` branch of the
view.

**Failure scenario:** Every admin settings-page load in the default
`'server'` mode (the common case) does a disk read and two regex passes
over the bookmarklet JS file whose result is immediately discarded.

## 10. [Low] Update-method default fallback repeated three times

**File:** `views/settings-page.php:142`

The expression `$options['update_method'] ?? 'server'` is written out three
separate times (lines 142, 148, 220) instead of computed once into a local
variable near the other `$options`-derived locals at the top of the file.

**Failure scenario:** If the default value or fallback logic ever needs to
change, one of the three copies is easy to miss — e.g. a future edit that
changes the panel-switching check at line 220 but not the two `checked()`
calls at 142/148 would show a radio button selection that doesn't match
which panel is actually rendered.
