# Automated Browser-Assisted Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an admin download one self-contained script from the plugin's Browser mode settings panel, add it to their own computer's cron/launchd, and have their Scholar profile stay in sync automatically — no browser extension, no manual paste, no new parsing code.

**Architecture:** A new REST endpoint (`POST /wp-json/wp-google-scholar/v1/import`) is a thin, WP Application Password-authenticated wrapper around the *existing* browser-paste import logic. The admin-post form handler and the new REST endpoint both delegate to a single new `Settings::process_import()` method (extracted from the current `handle_import_scholar_profile()`), so parsing/validation/persistence logic is never duplicated. A downloadable bash+curl template, with the site URL, a dedicated Application Password, and the profile settings baked in by a new admin-post handler, drives the endpoint from cron.

**Tech Stack:** PHP 7.0+ (existing plugin floor), WordPress REST API, WordPress core Application Passwords (5.6+), PHPUnit 9 + Brain Monkey + Mockery (existing test stack), bash + curl (external script, no runtime deps).

## Global Constraints

- PHP >= 7.0: no typed properties (7.4+), no constructor property promotion (8.0+), no named arguments (8.0+). Match existing codebase style: untyped `private $foo;` properties, typed method params/returns only (already used throughout, e.g. `includes/scraper.php:877`).
- New classes go in `includes/` and are autoloaded as `WPScholar\ClassName` → `includes/` + `strtolower(ClassName)` + `.php` (see `wp-google-scholar.php:29-44`). A class `WPScholar\RestApi` therefore must live at `includes/restapi.php` (no hyphen).
- No new Composer dependencies. No headless browser, no Node/Python — bash + curl only for the external script, per the approved design's non-goals.
- All new PHP is unit-testable via the existing Brain Monkey/Mockery setup in `tests/bootstrap.php` and `tests/Unit/*Test.php`; follow existing test conventions (reflection-constructed instances for handler classes, `Functions\when`/`Functions\expect` for WP core functions, stub classes in `bootstrap.php` for WP core classes not available in the test environment).
- Spec: `docs/superpowers/specs/2026-08-04-automated-browser-sync-design.md`.

---

## Task 1: Extract `Settings::process_import()` from the admin-post handler

The admin-post handler (`handle_import_scholar_profile()`) currently mixes nonce/capability checks, redirect side effects, *and* the actual import processing (browser-mode gate, rate-limit lock, calling `build_import_data()`, persisting, updating status) in one method. The new REST endpoint (Task 2) needs that processing logic without any of the redirect/exit behavior. Extract it into a reusable method first, with tests proving the extraction is behavior-preserving, before anything depends on it.

**Files:**
- Modify: `includes/settings.php` (replace the `handle_import_scholar_profile()` method, currently lines 290-397)
- Test: `tests/Unit/SettingsTest.php`

**Interfaces:**
- Produces: `Settings::process_import(string $content, string $import_mode): array` — returns `['data' => array]` on success or `['error' => array{type: string, message: string}]` on failure. No nonce/capability checks, no redirects, no `exit`. This is the method Task 2's `RestApi::handle_import()` calls.

- [ ] **Step 1: Write the failing tests for `process_import()`**

Add to `tests/Unit/SettingsTest.php`, after the existing `build_import_data` test block (after `test_build_import_data_limits_appended_publications_to_configured_maximum`, before the next section if any, or at end of class):

```php
    // ==========================================
    // process_import (shared by admin-post form handler and REST sync endpoint)
    // ==========================================

    private function stubProcessImportEnvironment(array $options, array $existingData = array(), bool $replaceLocked = false): void
    {
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('get_transient')->justReturn($replaceLocked ? 1 : false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('update_option')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('wp_date')->justReturn('2026-08-04 12:00:00');

        Functions\when('get_option')->alias(function ($name, $default = false) use ($options, $existingData) {
            if ($name === 'scholar_profile_settings') {
                return $options;
            }
            if ($name === 'scholar_profile_data') {
                return $existingData;
            }
            return $default;
        });
    }

    public function test_process_import_rejects_when_not_in_browser_mode(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $this->stubProcessImportEnvironment(array('update_method' => 'server'));

        $result = $settings->process_import('<html>gsc_prf</html>', 'replace');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('browser_mode_required', $result['error']['type']);
    }

    public function test_process_import_rejects_replace_while_locked(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $this->stubProcessImportEnvironment(
            array('update_method' => 'browser', 'profile_id' => 'test123ABC'),
            array(),
            true
        );

        $result = $settings->process_import('<html>gsc_prf</html>', 'replace');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('import_rate_limited', $result['error']['type']);
    }

    public function test_process_import_saves_valid_profile_and_returns_data(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $this->stubProcessImportEnvironment(
            array('update_method' => 'browser', 'profile_id' => 'test123ABC', 'max_publications' => 200)
        );

        Functions\expect('get_posts')->once()->andReturn([]);
        Functions\when('download_url')->justReturn(new \WP_Error('test', 'mocked'));
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('test', 'mocked'));

        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');
        $result = $settings->process_import($html, 'replace');

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('John Researcher', $result['data']['name']);
    }

    public function test_process_import_propagates_error_type_from_build_import_data(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $this->stubProcessImportEnvironment(
            array('update_method' => 'browser', 'profile_id' => 'test123ABC')
        );

        $result = $settings->process_import('<html><body>Just a random page</body></html>', 'replace');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('unrecognized_content', $result['error']['type']);
    }
```

Also add this reset to the top of the existing `setUp()` method (needed because `process_import()` now constructs `new Scheduler()`, which guards its own hook registration with a static flag that must be reset between tests, matching the pattern already used in `tests/Unit/SchedulerTest.php:19-23`):

```php
        // process_import() constructs Scheduler(); its constructor guards
        // hook registration with a static flag that must be reset between
        // tests (same pattern as SchedulerTest::setUp()).
        $schedulerReflection = new \ReflectionClass(\WPScholar\Scheduler::class);
        $hooksRegisteredProp = $schedulerReflection->getProperty('hooks_registered');
        $hooksRegisteredProp->setValue(null, false);
```

Insert it right after `Monkey\setUp();` and before the existing `$this->fixturesDir = ...` line.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter process_import`
Expected: FAIL — `Call to undefined method WPScholar\Settings::process_import()`

- [ ] **Step 3: Replace `handle_import_scholar_profile()` with the extracted version**

In `includes/settings.php`, replace the entire method (currently lines 290-397, from the `/**` docblock starting "Handle browser-assisted import..." through the closing `}` of `handle_import_scholar_profile()`) with:

```php
  /**
   * Handle browser-assisted import: data pasted from the bookmarklet (JSON)
   * or copied directly from a Scholar profile page (HTML), captured in the
   * admin's own browser instead of fetched server-side.
   */
  public function handle_import_scholar_profile()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if (!isset($_POST['scholar_import_nonce']) || !wp_verify_nonce($_POST['scholar_import_nonce'], 'import_scholar_profile')) {
      wp_die(__('Security check failed.'));
    }

    $content = isset($_POST['scholar_import_content']) ? wp_unslash($_POST['scholar_import_content']) : '';
    $import_mode = isset($_POST['scholar_import_mode']) && $_POST['scholar_import_mode'] === 'append'
      ? 'append'
      : 'replace';

    $result = $this->process_import($content, $import_mode);

    if (isset($result['error'])) {
      wp_safe_redirect(add_query_arg(
        array(
          'page' => $this->page_slug,
          'import' => 'failed',
          'error_type' => $result['error']['type'] ?? 'unknown'
        ),
        admin_url('options-general.php')
      ));
      exit;
    }

    wp_safe_redirect(add_query_arg(
      array('page' => $this->page_slug, 'import' => 'success'),
      admin_url('options-general.php')
    ));
    exit;
  }

  /**
   * Core browser-assisted import processing, shared by the admin-post form
   * handler above and the REST API sync endpoint (WPScholar\RestApi). No
   * nonce, capability, or redirect side effects - callers own their own
   * auth and response handling.
   *
   * @param string $content Raw pasted/POSTed content (JSON from the bookmarklet, or HTML)
   * @param string $import_mode 'replace' or 'append'
   * @return array Either ['data' => array] on success or ['error' => array] on failure
   */
  public function process_import(string $content, string $import_mode): array
  {
    $options = get_option($this->option_name, array());
    if (($options['update_method'] ?? 'server') !== 'browser') {
      return array('error' => array(
        'type' => 'browser_mode_required',
        'message' => 'Browser mode is not enabled for this site.'
      ));
    }

    // A full replacement may download the profile avatar. Briefly lock that
    // action against double-clicks/back-button resubmits or overlapping
    // sync runs, while deliberately leaving append imports unrestricted for
    // the expected cstart=N flow.
    if ($import_mode === 'replace') {
      $lock_name = 'scholar_profile_import_replace_lock';
      if (get_transient($lock_name)) {
        return array('error' => array(
          'type' => 'import_rate_limited',
          'message' => 'Please wait a few seconds before replacing profile data again.'
        ));
      }
      set_transient($lock_name, 1, self::IMPORT_REPLACE_COOLDOWN_SECONDS);
    }

    $existing_data = get_option('scholar_profile_data', array());
    $result = $this->build_import_data(
      $content,
      is_array($existing_data) ? $existing_data : array(),
      $options['profile_id'] ?? '',
      $import_mode,
      intval($options['max_publications'] ?? 200)
    );

    if (isset($result['error'])) {
      wp_scholar_log('Browser import failed: ' . ($result['error']['message'] ?? 'Unknown error'), 'error');
      update_option('scholar_profile_last_error_details', $result['error']);
      $scheduler = new Scheduler();
      $scheduler->update_data_status('error', $result['error']['message'] ?? 'Browser import failed.');
      return $result;
    }

    $data = $result['data'];

    if (!Scraper::validate_scraped_data($data)) {
      $error = array(
        'type' => 'validation_failed',
        'message' => 'The imported data did not look complete enough to save. Please make sure you copied the full profile page.'
      );
      update_option('scholar_profile_last_error_details', $error);
      $scheduler = new Scheduler();
      $scheduler->update_data_status('error', $error['message']);
      return array('error' => $error);
    }

    update_option('scholar_profile_data', $data);
    update_option('scholar_profile_last_update', time());
    delete_option('scholar_profile_consecutive_failures');
    delete_option('scholar_profile_last_error_details');

    $scheduler = new Scheduler();
    $scheduler->update_data_status('success', sprintf(
      'Imported via browser at %s - Found %d publications',
      wp_date('Y-m-d H:i:s'),
      count($data['publications'])
    ));

    wp_scholar_log('Browser import successful for profile: ' . ($options['profile_id'] ?? ''));

    return array('data' => $data);
  }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit`
Expected: PASS — all tests green, including the new `process_import` tests and every pre-existing `build_import_data` test (untouched, still passing since `build_import_data()` itself was not modified).

- [ ] **Step 5: Commit**

```bash
git add includes/settings.php tests/Unit/SettingsTest.php
git commit -m "$(cat <<'EOF'
Extract Settings::process_import() from the browser-import handler

Splits the admin-post handler's nonce/redirect plumbing from its actual
import processing, so the upcoming REST sync endpoint can reuse the same
validated, rate-limited, status-tracked import path without duplicating it.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: REST API sync endpoint

**Files:**
- Create: `includes/restapi.php`
- Create: `tests/Unit/RestApiTest.php`
- Modify: `tests/bootstrap.php` (add a minimal `WP_REST_Request` stub)
- Modify: `wp-google-scholar.php` (instantiate `RestApi` in `wp_scholar_init()`)

**Interfaces:**
- Consumes: `Settings::process_import(string $content, string $import_mode): array` (Task 1).
- Produces: `WPScholar\RestApi` with public methods `register_routes(): void`, `check_permission()` (returns `bool|\WP_Error`), `handle_import(\WP_REST_Request $request)` (returns `array|\WP_Error`) — registered at `POST /wp-json/wp-google-scholar/v1/import`.

- [ ] **Step 1: Add the `WP_REST_Request` test stub**

In `tests/bootstrap.php`, add this after the existing `is_wp_error` stub (after the closing `}` that follows the `is_wp_error` function, near the end of the file):

```php
/**
 * Minimal WP_REST_Request stub for tests - a read-only param bag standing
 * in for WordPress core's REST request object.
 */
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private $params;

        public function __construct(array $params = array())
        {
            $this->params = $params;
        }

        public function get_param($key)
        {
            return $this->params[$key] ?? null;
        }
    }
}
```

- [ ] **Step 2: Write the failing tests**

Create `tests/Unit/RestApiTest.php`:

```php
<?php

namespace WPScholar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPScholar\RestApi;
use WPScholar\Settings;

class RestApiTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('add_action')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function createRestApi(): RestApi
    {
        return new RestApi(Mockery::mock(Settings::class));
    }

    public function test_register_routes_registers_import_endpoint(): void
    {
        $api = $this->createRestApi();

        Functions\expect('register_rest_route')
            ->once()
            ->with('wp-google-scholar/v1', '/import', Mockery::on(function ($args) {
                return $args['methods'] === 'POST'
                    && is_callable($args['callback'])
                    && is_callable($args['permission_callback']);
            }));

        $api->register_routes();
    }

    public function test_check_permission_rejects_non_ssl(): void
    {
        $api = $this->createRestApi();
        Functions\when('is_ssl')->justReturn(false);

        $result = $api->check_permission();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('scholar_https_required', $result->get_error_code());
    }

    public function test_check_permission_rejects_without_capability(): void
    {
        $api = $this->createRestApi();
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        $result = $api->check_permission();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('scholar_forbidden', $result->get_error_code());
    }

    public function test_check_permission_allows_authorized_https_request(): void
    {
        $api = $this->createRestApi();
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $this->assertTrue($api->check_permission());
    }

    public function test_handle_import_returns_success_payload(): void
    {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('process_import')
            ->once()
            ->with('<html>...</html>', 'replace')
            ->andReturn(array('data' => array('publications' => array(array('title' => 'A'), array('title' => 'B')))));

        $api = new RestApi($settings);
        $request = new \WP_REST_Request(array('content' => '<html>...</html>', 'import_mode' => 'replace'));

        $result = $api->handle_import($request);

        $this->assertSame(array('success' => true, 'publications' => 2), $result);
    }

    public function test_handle_import_maps_browser_mode_required_to_403(): void
    {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('process_import')
            ->once()
            ->andReturn(array('error' => array('type' => 'browser_mode_required', 'message' => 'Browser mode is not enabled for this site.')));

        $api = new RestApi($settings);
        $request = new \WP_REST_Request(array('content' => 'x', 'import_mode' => 'replace'));

        $result = $api->handle_import($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(403, $result->get_error_data()['status']);
    }

    public function test_handle_import_maps_generic_failure_to_422(): void
    {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('process_import')
            ->once()
            ->andReturn(array('error' => array('type' => 'validation_failed', 'message' => 'Not enough data.')));

        $api = new RestApi($settings);
        $request = new \WP_REST_Request(array('content' => 'x', 'import_mode' => 'replace'));

        $result = $api->handle_import($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame(422, $result->get_error_data()['status']);
    }

    public function test_handle_import_defaults_unrecognized_import_mode_to_replace(): void
    {
        $settings = Mockery::mock(Settings::class);
        $settings->shouldReceive('process_import')
            ->once()
            ->with('x', 'replace')
            ->andReturn(array('data' => array('publications' => array())));

        $api = new RestApi($settings);
        $request = new \WP_REST_Request(array('content' => 'x', 'import_mode' => 'something-else'));

        $api->handle_import($request);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/RestApiTest.php`
Expected: FAIL — `Class "WPScholar\RestApi" not found`

- [ ] **Step 4: Create `includes/restapi.php`**

```php
<?php

namespace WPScholar;

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

/**
 * REST API endpoint for the automated browser-assisted sync flow: a script
 * running on the admin's own machine (via cron/launchd, generated and
 * downloaded from the Browser mode settings panel) POSTs raw Scholar HTML
 * here instead of the admin pasting it into wp-admin by hand. Delegates
 * all parsing/validation/persistence to Settings::process_import() - the
 * same code path the manual browser-paste form uses.
 */
class RestApi
{
  private const NAMESPACE = 'wp-google-scholar/v1';

  /** Error type => HTTP status for process_import() failures not covered by the default. */
  private const ERROR_STATUS_MAP = array(
    'browser_mode_required' => 403,
    'import_rate_limited' => 429,
  );

  private $settings;

  public function __construct(Settings $settings)
  {
    $this->settings = $settings;
    add_action('rest_api_init', array($this, 'register_routes'));
  }

  public function register_routes(): void
  {
    register_rest_route(self::NAMESPACE, '/import', array(
      'methods' => 'POST',
      'callback' => array($this, 'handle_import'),
      'permission_callback' => array($this, 'check_permission'),
      'args' => array(
        'content' => array('required' => true, 'type' => 'string'),
        'import_mode' => array('required' => true, 'type' => 'string', 'enum' => array('replace', 'append')),
      ),
    ));
  }

  /**
   * @return bool|\WP_Error
   */
  public function check_permission()
  {
    if (!is_ssl()) {
      return new \WP_Error('scholar_https_required', 'This endpoint requires HTTPS.', array('status' => 403));
    }

    if (!current_user_can('manage_options')) {
      return new \WP_Error('scholar_forbidden', 'You do not have permission to perform this action.', array('status' => 403));
    }

    return true;
  }

  /**
   * @return array|\WP_Error
   */
  public function handle_import(\WP_REST_Request $request)
  {
    $content = (string) $request->get_param('content');
    $import_mode = $request->get_param('import_mode') === 'append' ? 'append' : 'replace';

    $result = $this->settings->process_import($content, $import_mode);

    if (isset($result['error'])) {
      $type = $result['error']['type'] ?? 'unknown';
      $status = self::ERROR_STATUS_MAP[$type] ?? 422;

      return new \WP_Error(
        'scholar_import_' . $type,
        $result['error']['message'] ?? 'Import failed.',
        array('status' => $status)
      );
    }

    return array(
      'success' => true,
      'publications' => count($result['data']['publications'] ?? array()),
    );
  }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/RestApiTest.php`
Expected: PASS

- [ ] **Step 6: Wire `RestApi` into plugin bootstrap**

In `wp-google-scholar.php`, replace:

```php
  // Initialize classes
  new WPScholar\Settings();
  new WPScholar\Shortcode();
  new WPScholar\Scheduler();
```

with:

```php
  // Initialize classes
  $settings = new WPScholar\Settings();
  new WPScholar\Shortcode();
  new WPScholar\Scheduler();
  new WPScholar\RestApi($settings);
```

- [ ] **Step 7: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS — no regressions in `PluginTest.php` or elsewhere from the bootstrap change.

- [ ] **Step 8: Commit**

```bash
git add includes/restapi.php tests/Unit/RestApiTest.php tests/bootstrap.php wp-google-scholar.php
git commit -m "$(cat <<'EOF'
Add REST API endpoint for automated browser-assisted sync

POST /wp-json/wp-google-scholar/v1/import, authenticated via WordPress
Application Passwords, wraps the existing Settings::process_import() so
an external script (Task 3) can push Scholar HTML without any new
parsing logic or manual paste step.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Downloadable sync script + Application Password lifecycle

**Files:**
- Create: `assets/tools/scholar-sync.sh.tpl`
- Modify: `includes/settings.php` (add `handle_download_sync_script()`, `handle_revoke_sync_credential()`, `revoke_sync_credentials()`, `get_active_sync_credentials()`, a new class constant, and two `add_action` registrations in the constructor)
- Modify: `tests/bootstrap.php` (add a minimal `WP_Application_Passwords` stub)
- Test: `tests/Unit/SettingsTest.php`

**Interfaces:**
- Consumes: none beyond WordPress core (`WP_Application_Passwords`, already a core class since WP 5.6; stubbed for tests below).
- Produces: `Settings::get_active_sync_credentials(): array` (list of `['uuid' => string, 'name' => string, ...]`) — consumed by the view in Task 4. Admin-post actions `download_scholar_sync_script` and `revoke_scholar_sync_credential`.

- [ ] **Step 1: Add the `WP_Application_Passwords` test stub**

In `tests/bootstrap.php`, add this after the `WP_REST_Request` stub added in Task 2:

```php
/**
 * Minimal WP_Application_Passwords stub for tests - an in-memory store
 * standing in for WordPress core's Application Passwords API (5.6+).
 * Tests reset WP_Application_Passwords::$store in setUp().
 */
if (!class_exists('WP_Application_Passwords')) {
    class WP_Application_Passwords
    {
        public static $store = array();
        private static $next_id = 1;

        public static function create_new_application_password($user_id, $args = array())
        {
            $uuid = 'test-uuid-' . self::$next_id++;
            $item = array(
                'uuid' => $uuid,
                'name' => $args['name'] ?? '',
                'created' => time(),
            );
            self::$store[$user_id][$uuid] = $item;
            return array('plaintext-password-' . $uuid, $item);
        }

        public static function get_user_application_passwords($user_id)
        {
            return array_values(self::$store[$user_id] ?? array());
        }

        public static function delete_application_password($user_id, $uuid)
        {
            if (!isset(self::$store[$user_id][$uuid])) {
                return false;
            }
            unset(self::$store[$user_id][$uuid]);
            return true;
        }
    }
}
```

- [ ] **Step 2: Write the failing tests**

Add to `tests/Unit/SettingsTest.php`, after the `process_import` tests added in Task 1:

```php
    // ==========================================
    // Automated sync: download script + Application Password lifecycle
    // ==========================================

    protected function setUpSyncCredentialStore(): void
    {
        \WP_Application_Passwords::$store = array();
    }

    public function test_get_active_sync_credentials_filters_to_sync_prefix(): void
    {
        $this->setUpSyncCredentialStore();
        \WP_Application_Passwords::$store[1] = array(
            'a' => array('uuid' => 'a', 'name' => 'Scholar Auto-Sync (2026-08-01)'),
            'b' => array('uuid' => 'b', 'name' => 'Some Other App'),
        );

        Functions\when('get_current_user_id')->justReturn(1);

        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->get_active_sync_credentials();

        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]['uuid']);
    }

    public function test_revoke_sync_credentials_removes_only_sync_prefixed_entries(): void
    {
        $this->setUpSyncCredentialStore();
        \WP_Application_Passwords::$store[7] = array(
            'a' => array('uuid' => 'a', 'name' => 'Scholar Auto-Sync (2026-08-01)'),
            'b' => array('uuid' => 'b', 'name' => 'Some Other App'),
        );

        $settings = $this->createSettingsWithoutConstructor();
        $reflection = new \ReflectionClass($settings);
        $method = $reflection->getMethod('revoke_sync_credentials');
        $method->setAccessible(true);
        $method->invoke($settings, 7);

        $remaining = \WP_Application_Passwords::get_user_application_passwords(7);
        $this->assertCount(1, $remaining);
        $this->assertSame('b', $remaining[0]['uuid']);
    }
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter "sync_credential"`
Expected: FAIL — `Call to undefined method WPScholar\Settings::get_active_sync_credentials()`

- [ ] **Step 4: Create the bash script template**

Create `assets/tools/scholar-sync.sh.tpl`:

```bash
#!/usr/bin/env bash
#
# Google Scholar Profile Display - automated browser-assisted sync.
#
# Generated by the plugin's "Download Sync Script" button in wp-admin -
# your site URL, WordPress username, and a dedicated Application Password
# are already filled in below. Add this to cron/launchd on your own
# computer to keep your Scholar profile in sync without repeating the
# manual paste every time (Browser mode, plugin settings page).
#
# This file contains a live credential. Treat it like a password: do not
# commit it to version control or share it. Downloading a new copy from
# wp-admin revokes this one and issues a fresh credential.
#
# Usage:
#   ./scholar-sync.sh            run the sync
#   ./scholar-sync.sh --dry-run  fetch Scholar pages and print what would
#                                 be sent, without POSTing anything

set -euo pipefail

SITE_URL="__SITE_URL__"
WP_USER="__WP_USER__"
APP_PASSWORD="__APP_PASSWORD__"
PROFILE_ID="__PROFILE_ID__"
MAX_PUBLICATIONS=__MAX_PUBLICATIONS__
PAGE_SIZE=20

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_FILE="$SCRIPT_DIR/scholar-sync.log"
LOCK_FILE="$SCRIPT_DIR/scholar-sync.lock"
DRY_RUN=0

if [[ "${1:-}" == "--dry-run" ]]; then
  DRY_RUN=1
fi

log() {
  echo "$(date '+%Y-%m-%d %H:%M:%S') $1" | tee -a "$LOG_FILE"
}

fail() {
  log "ERROR: $1"
  exit 1
}

if [[ -f "$LOCK_FILE" ]]; then
  PREVIOUS_PID="$(cat "$LOCK_FILE")"
  if kill -0 "$PREVIOUS_PID" 2>/dev/null; then
    fail "Previous run (PID $PREVIOUS_PID) is still active, skipping."
  fi
fi
echo $$ > "$LOCK_FILE"
trap 'rm -f "$LOCK_FILE"' EXIT

import_page() {
  local html="$1"
  local mode="$2"

  if [[ "$DRY_RUN" -eq 1 ]]; then
    log "[dry-run] would POST $mode (${#html} bytes of HTML) to $SITE_URL/wp-json/wp-google-scholar/v1/import"
    return 0
  fi

  local response http_code body
  response="$(curl -sS -w '\n%{http_code}' \
    -u "${WP_USER}:${APP_PASSWORD}" \
    --data-urlencode "content=${html}" \
    --data-urlencode "import_mode=${mode}" \
    "${SITE_URL}/wp-json/wp-google-scholar/v1/import")"

  http_code="${response##*$'\n'}"
  body="${response%$'\n'*}"

  if (( http_code < 200 || http_code >= 300 )); then
    fail "Import request failed (HTTP $http_code): $body"
  fi
}

fetch_page() {
  local start="$1"
  curl -sS -f \
    "https://scholar.google.com/citations?user=${PROFILE_ID}&hl=en&cstart=${start}&pagesize=${PAGE_SIZE}" \
    || fail "Could not fetch Scholar page at cstart=${start}"
}

log "Starting sync for profile ${PROFILE_ID}"

MAIN_PAGE="$(fetch_page 0)"
import_page "$MAIN_PAGE" "replace"

ROW_COUNT="$(grep -o 'gsc_a_tr' <<<"$MAIN_PAGE" | wc -l | tr -d ' ')"
TOTAL=$ROW_COUNT
START=$PAGE_SIZE

while (( ROW_COUNT >= PAGE_SIZE && TOTAL < MAX_PUBLICATIONS )); do
  PAGE_HTML="$(fetch_page "$START")"
  ROW_COUNT="$(grep -o 'gsc_a_tr' <<<"$PAGE_HTML" | wc -l | tr -d ' ')"

  if (( ROW_COUNT == 0 )); then
    break
  fi

  import_page "$PAGE_HTML" "append"
  TOTAL=$((TOTAL + ROW_COUNT))
  START=$((START + PAGE_SIZE))
done

log "Sync complete - processed approximately ${TOTAL} publications"
```

- [ ] **Step 5: Add the constant, handlers, and hook registrations to `includes/settings.php`**

Add the new constant next to the existing ones (after `private const IMPORT_REPLACE_COOLDOWN_SECONDS = 15;`):

```php
  private const SYNC_CREDENTIAL_PREFIX = 'Scholar Auto-Sync';
```

Add two lines to the constructor, after the existing `add_action('admin_post_import_scholar_profile', ...)` line:

```php
    add_action('admin_post_download_scholar_sync_script', array($this, 'handle_download_sync_script'));
    add_action('admin_post_revoke_scholar_sync_credential', array($this, 'handle_revoke_sync_credential'));
```

Add the following methods (place them after `handle_clear_stale_data()` and before the `handle_import_scholar_profile()`/`process_import()` pair added in Task 1):

```php
  /**
   * Generate and stream a ready-to-run sync script for the automated
   * browser-assisted sync flow: fetches Scholar pages from the admin's own
   * machine (via cron) and POSTs them to RestApi::handle_import(), using a
   * dedicated Application Password issued just for this purpose.
   */
  public function handle_download_sync_script()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if (!isset($_POST['scholar_sync_download_nonce']) || !wp_verify_nonce($_POST['scholar_sync_download_nonce'], 'download_scholar_sync_script')) {
      wp_die(__('Security check failed.'));
    }

    $options = get_option($this->option_name, array());
    if (empty($options['profile_id'])) {
      wp_safe_redirect(add_query_arg(
        array('page' => $this->page_slug, 'sync_download' => 'failed'),
        admin_url('options-general.php')
      ));
      exit;
    }

    $user_id = get_current_user_id();
    $this->revoke_sync_credentials($user_id);

    $password_result = \WP_Application_Passwords::create_new_application_password($user_id, array(
      'name' => self::SYNC_CREDENTIAL_PREFIX . ' (' . wp_date('Y-m-d H:i') . ')'
    ));

    if (is_wp_error($password_result)) {
      wp_die(esc_html($password_result->get_error_message()));
    }

    list($app_password, ) = $password_result;

    $template_path = WP_SCHOLAR_PLUGIN_DIR . 'assets/tools/scholar-sync.sh.tpl';
    $template = file_exists($template_path) ? file_get_contents($template_path) : false;

    if ($template === false) {
      wp_die(__('Sync script template is missing from this plugin install.', 'wp-google-scholar'));
    }

    $current_user = wp_get_current_user();
    $script = str_replace(
      array('__SITE_URL__', '__WP_USER__', '__APP_PASSWORD__', '__PROFILE_ID__', '__MAX_PUBLICATIONS__'),
      array(
        home_url(),
        $current_user->user_login,
        $app_password,
        $options['profile_id'],
        (string) intval($options['max_publications'] ?? 200)
      ),
      $template
    );

    nocache_headers();
    header('Content-Type: text/x-sh; charset=utf-8');
    header('Content-Disposition: attachment; filename="scholar-sync.sh"');
    header('Content-Length: ' . strlen($script));
    echo $script;
    exit;
  }

  /**
   * Revoke a single automated-sync Application Password from the settings
   * page, without sending the admin to Users > Profile.
   */
  public function handle_revoke_sync_credential()
  {
    if (!current_user_can('manage_options')) {
      wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if (!isset($_POST['scholar_sync_revoke_nonce']) || !wp_verify_nonce($_POST['scholar_sync_revoke_nonce'], 'revoke_scholar_sync_credential')) {
      wp_die(__('Security check failed.'));
    }

    $uuid = isset($_POST['uuid']) ? sanitize_text_field($_POST['uuid']) : '';
    if ($uuid !== '') {
      \WP_Application_Passwords::delete_application_password(get_current_user_id(), $uuid);
    }

    wp_safe_redirect(add_query_arg(
      array('page' => $this->page_slug, 'sync_revoke' => 'success'),
      admin_url('options-general.php')
    ));
    exit;
  }

  /**
   * Revoke every existing automated-sync Application Password for a user,
   * so downloading a new script always leaves exactly one active credential.
   */
  private function revoke_sync_credentials(int $user_id): void
  {
    $existing = \WP_Application_Passwords::get_user_application_passwords($user_id);
    foreach ($existing as $item) {
      if (isset($item['name']) && strpos($item['name'], self::SYNC_CREDENTIAL_PREFIX) === 0) {
        \WP_Application_Passwords::delete_application_password($user_id, $item['uuid']);
      }
    }
  }

  /**
   * Active automated-sync Application Passwords for the current admin, for
   * display (with per-credential Revoke buttons) in the Browser mode panel.
   */
  public function get_active_sync_credentials(): array
  {
    $existing = \WP_Application_Passwords::get_user_application_passwords(get_current_user_id());
    return array_values(array_filter($existing, function ($item) {
      return isset($item['name']) && strpos($item['name'], self::SYNC_CREDENTIAL_PREFIX) === 0;
    }));
  }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter "sync_credential"`
Expected: PASS

- [ ] **Step 7: Update the constructor hook-count test**

In `tests/Unit/SettingsTest.php`, `test_constructor_registers_expected_hooks` currently asserts `Functions\expect('add_action')->times(6);`. The constructor now registers 2 more (`download_scholar_sync_script`, `revoke_scholar_sync_credential`). Update:

```php
    public function test_constructor_registers_expected_hooks(): void
    {
        Functions\expect('add_action')
            ->times(8);
```

- [ ] **Step 8: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 9: Make the template executable and commit**

```bash
chmod +x assets/tools/scholar-sync.sh.tpl
git add assets/tools/scholar-sync.sh.tpl includes/settings.php tests/Unit/SettingsTest.php tests/bootstrap.php
git commit -m "$(cat <<'EOF'
Add downloadable sync script generation and credential lifecycle

New admin-post handlers issue a dedicated Application Password, bake it
plus site/profile config into a bash+curl script template, and stream it
as a download. Re-downloading rotates the credential; a companion revoke
handler lets an admin invalidate a script from the settings page.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Settings page UI

**Files:**
- Modify: `includes/settings.php` (pass `$sync_credentials` to the view; add `sync_download`/`sync_revoke` status messages)
- Modify: `views/settings-page.php` (Automated Sync panel)
- Modify: `assets/css/admin-style.css` (small additions for the new panel)

**Interfaces:**
- Consumes: `Settings::get_active_sync_credentials(): array` (Task 3).

- [ ] **Step 1: Pass sync credentials into the view**

In `includes/settings.php`, inside `render_settings_page()`, find:

```php
    $update_method = $options['update_method'] ?? 'server';
    $bookmarklet_href = $update_method === 'browser'
      ? $this->build_bookmarklet_href($options['max_publications'] ?? 200)
      : '';

    include WP_SCHOLAR_PLUGIN_DIR . 'views/settings-page.php';
```

Replace with:

```php
    $update_method = $options['update_method'] ?? 'server';
    $bookmarklet_href = $update_method === 'browser'
      ? $this->build_bookmarklet_href($options['max_publications'] ?? 200)
      : '';
    $sync_credentials = $update_method === 'browser'
      ? $this->get_active_sync_credentials()
      : array();

    include WP_SCHOLAR_PLUGIN_DIR . 'views/settings-page.php';
```

- [ ] **Step 2: Add status messages**

In `includes/settings.php`, inside the message-building block, find the closing of the import status branch:

```php
    // Handle browser-assisted import status messages
    elseif (isset($_GET['import'])) {
      if ($_GET['import'] === 'success') {
        $messages[] = array(
          'type' => 'updated',
          'message' => __('✓ Profile data imported successfully!', 'wp-google-scholar')
        );
      } elseif ($_GET['import'] === 'failed') {
        $messages[] = array(
          'type' => 'error',
          'message' => '⚠ ' . $this->get_import_error_message($_GET),
          'is_html' => true
        );
      }
    }
    // Only check for settings-updated if refresh/import are NOT set
    elseif (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
```

Insert two new `elseif` branches between them:

```php
    // Handle browser-assisted import status messages
    elseif (isset($_GET['import'])) {
      if ($_GET['import'] === 'success') {
        $messages[] = array(
          'type' => 'updated',
          'message' => __('✓ Profile data imported successfully!', 'wp-google-scholar')
        );
      } elseif ($_GET['import'] === 'failed') {
        $messages[] = array(
          'type' => 'error',
          'message' => '⚠ ' . $this->get_import_error_message($_GET),
          'is_html' => true
        );
      }
    }
    // Handle automated sync script download / credential revoke messages
    elseif (isset($_GET['sync_download']) && $_GET['sync_download'] === 'failed') {
      $messages[] = array(
        'type' => 'error',
        'message' => '⚠ ' . __('Enter and save a Profile ID before downloading the sync script.', 'wp-google-scholar')
      );
    } elseif (isset($_GET['sync_revoke']) && $_GET['sync_revoke'] === 'success') {
      $messages[] = array(
        'type' => 'updated',
        'message' => __('✓ Sync credential revoked.', 'wp-google-scholar')
      );
    }
    // Only check for settings-updated if refresh/import are NOT set
    elseif (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
```

- [ ] **Step 3: Add the Automated Sync panel to the view**

In `views/settings-page.php`, find the end of the existing browser-import form:

```php
                    <div class="scholar-form-actions">
                      <button type="submit" name="scholar_import_mode" value="replace" class="button button-primary">
                        <?php _e('Replace profile data', 'wp-google-scholar'); ?>
                      </button>
                      <button type="submit" name="scholar_import_mode" value="append" class="button button-secondary">
                        <?php _e('Add publications from another page', 'wp-google-scholar'); ?>
                      </button>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
```

Insert the new panel right after the `</form>` and before `<?php endif; ?>`:

```php
                    <div class="scholar-form-actions">
                      <button type="submit" name="scholar_import_mode" value="replace" class="button button-primary">
                        <?php _e('Replace profile data', 'wp-google-scholar'); ?>
                      </button>
                      <button type="submit" name="scholar_import_mode" value="append" class="button button-secondary">
                        <?php _e('Add publications from another page', 'wp-google-scholar'); ?>
                      </button>
                    </div>
                  </form>

                  <hr class="scholar-sync-divider">

                  <h3><?php _e('Automated Sync (optional)', 'wp-google-scholar'); ?></h3>
                  <p class="description">
                    <?php _e("Skip the manual paste: download a ready-to-run script, add one line to your own computer's cron/launchd, and this profile stays in sync automatically - fetched from your machine's network, never the server.", 'wp-google-scholar'); ?>
                  </p>

                  <?php if (!empty($sync_credentials)): ?>
                    <ul class="scholar-sync-credential-list">
                      <?php foreach ($sync_credentials as $credential): ?>
                        <li>
                          <?php echo esc_html($credential['name']); ?>
                          <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="scholar-sync-revoke-form">
                            <input type="hidden" name="action" value="revoke_scholar_sync_credential">
                            <input type="hidden" name="uuid" value="<?php echo esc_attr($credential['uuid']); ?>">
                            <?php wp_nonce_field('revoke_scholar_sync_credential', 'scholar_sync_revoke_nonce'); ?>
                            <button type="submit" class="button-link scholar-sync-revoke-btn">
                              <?php _e('Revoke', 'wp-google-scholar'); ?>
                            </button>
                          </form>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>

                  <p class="scholar-sync-warning">
                    ⚠ <?php _e('The downloaded file contains a live credential - treat it like a password. Do not commit it to version control or share it. Downloading again issues a new credential and revokes the old one.', 'wp-google-scholar'); ?>
                  </p>

                  <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="download_scholar_sync_script">
                    <?php wp_nonce_field('download_scholar_sync_script', 'scholar_sync_download_nonce'); ?>
                    <button type="submit" class="button button-secondary">
                      ⬇ <?php _e('Download Sync Script', 'wp-google-scholar'); ?>
                    </button>
                  </form>
                <?php endif; ?>
              </div>
```

- [ ] **Step 4: Add supporting CSS**

In `assets/css/admin-style.css`, add after the existing `.scholar-refresh-section` rules (near line 383-403):

```css
.scholar-sync-divider {
  margin: 20px 0;
  border: none;
  border-top: 1px solid #dcdcde;
}

.scholar-sync-credential-list {
  margin: 10px 0;
  padding: 0;
  list-style: none;
}

.scholar-sync-credential-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 10px;
  background: #f6f7f7;
  border-radius: 4px;
  margin-bottom: 6px;
}

.scholar-sync-revoke-form {
  margin: 0;
}

.scholar-sync-revoke-btn {
  color: #d63638;
  cursor: pointer;
}

.scholar-sync-warning {
  color: #996800;
  background: #fcf9e8;
  border-left: 4px solid #dba617;
  padding: 8px 12px;
  margin: 12px 0;
}
```

- [ ] **Step 5: Run the full PHPUnit suite**

Run: `vendor/bin/phpunit`
Expected: PASS — the view/CSS changes have no PHPUnit coverage themselves, but this confirms `includes/settings.php` still parses and all existing tests still pass after the `render_settings_page()` edit.

- [ ] **Step 6: Commit**

```bash
git add includes/settings.php views/settings-page.php assets/css/admin-style.css
git commit -m "$(cat <<'EOF'
Add Automated Sync panel to the Browser mode settings page

Download-script button, security warning, and per-credential Revoke
list, wired to the handlers added in the previous commit.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Manual verification

Not unit-testable: the bash script's actual network behavior, and the settings page rendering. Verify by hand.

**Files:** none (verification only)

- [ ] **Step 1: Run the full automated suite one more time**

Run: `composer test` (equivalent to `vendor/bin/phpunit`)
Expected: PASS, 0 failures, 0 errors.

- [ ] **Step 2: Static-check the bash template**

Run: `bash -n assets/tools/scholar-sync.sh.tpl`
Expected: no syntax errors reported.

- [ ] **Step 3: Dry-run the template against fixture placeholders**

```bash
cp assets/tools/scholar-sync.sh.tpl /tmp/scholar-sync-test.sh
sed -i '' \
  -e 's/__SITE_URL__/https:\/\/example.test/' \
  -e 's/__WP_USER__/testadmin/' \
  -e 's/__APP_PASSWORD__/xxxx xxxx xxxx xxxx xxxx xxxx/' \
  -e 's/__PROFILE_ID__/abc123/' \
  -e 's/__MAX_PUBLICATIONS__/200/' \
  /tmp/scholar-sync-test.sh
chmod +x /tmp/scholar-sync-test.sh
/tmp/scholar-sync-test.sh --dry-run
```

Expected: the script attempts to `curl` `https://scholar.google.com/citations?user=abc123...` (will succeed or fail depending on network/real profile — the point is confirming the script runs without bash errors up to that network call, and that `--dry-run` avoids any POST).

- [ ] **Step 4: Manual settings-page check (requires a local WP install)**

If a local WordPress dev environment is available: activate the plugin, set Update Method to Browser, save a real Profile ID, click "Download Sync Script," confirm a `scholar-sync.sh` file downloads with the placeholders filled in (no `__PLACEHOLDER__` strings remaining), and that a new Application Password appears both in the plugin's credential list and under `Users → Profile → Application Passwords`. Click "Revoke" and confirm it disappears from both places. If no local WP environment is available, note this step as skipped rather than claiming it passed.

- [ ] **Step 5: Final commit (if Task 5 uncovered fixes)**

Only if steps above surfaced a bug: fix it, re-run the full suite (Step 1), and commit with a message describing what verification caught.
