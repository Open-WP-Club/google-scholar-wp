<?php

namespace WPScholar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPScholar\Settings;

class SettingsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // process_import() constructs Scheduler(); its constructor guards
        // hook registration with a static flag that must be reset between
        // tests (same pattern as SchedulerTest::setUp()).
        $schedulerReflection = new \ReflectionClass(\WPScholar\Scheduler::class);
        $hooksRegisteredProp = $schedulerReflection->getProperty('hooks_registered');
        $hooksRegisteredProp->setValue(null, false);

        $this->fixturesDir = dirname(__DIR__) . '/Fixtures/';

        // Mock sanitize_text_field to just trim and strip tags
        Functions\when('sanitize_text_field')->alias(function ($str) {
            return trim(strip_tags($str));
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Create a Settings instance without triggering the constructor
     */
    private function createSettingsWithoutConstructor(): Settings
    {
        $reflection = new \ReflectionClass(Settings::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    // --- Profile ID sanitization ---

    public function test_sanitize_profile_id_trims_whitespace(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings(['profile_id' => '  test1234  ']);
        $this->assertSame('test1234', $result['profile_id']);
    }

    public function test_sanitize_profile_id_empty_for_missing(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings([]);
        $this->assertSame('', $result['profile_id']);
    }

    public function test_sanitize_profile_id_strips_tags(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings(['profile_id' => '<script>alert(1)</script>test1234']);
        $this->assertSame('alert(1)test1234', $result['profile_id']);
    }

    // --- Checkbox handling ---

    public function test_checkbox_present_sets_one(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings([
            'show_avatar' => '1',
            'show_info' => 'on',
            'show_publications' => 'yes',
            'show_coauthors' => '1',
        ]);

        $this->assertSame('1', $result['show_avatar']);
        $this->assertSame('1', $result['show_info']);
        $this->assertSame('1', $result['show_publications']);
        $this->assertSame('1', $result['show_coauthors']);
    }

    public function test_checkbox_absent_sets_zero(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings([]);

        $this->assertSame('0', $result['show_avatar']);
        $this->assertSame('0', $result['show_info']);
        $this->assertSame('0', $result['show_publications']);
        $this->assertSame('0', $result['show_coauthors']);
    }

    // --- Update frequency validation ---

    public function test_valid_update_frequencies(): void
    {
        $settings = $this->createSettingsWithoutConstructor();

        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $frequency) {
            $result = $settings->sanitize_settings(['update_frequency' => $frequency]);
            $this->assertSame($frequency, $result['update_frequency'], "Frequency '$frequency' should be accepted");
        }
    }

    public function test_invalid_update_frequency_defaults_to_weekly(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings(['update_frequency' => 'hourly']);
        $this->assertSame('weekly', $result['update_frequency']);
    }

    public function test_missing_update_frequency_defaults_to_weekly(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings([]);
        $this->assertSame('weekly', $result['update_frequency']);
    }

    // --- Max publications validation ---

    public function test_valid_max_publications(): void
    {
        $settings = $this->createSettingsWithoutConstructor();

        foreach ([50, 100, 200, 500] as $max) {
            $result = $settings->sanitize_settings(['max_publications' => $max]);
            $this->assertSame($max, $result['max_publications'], "Max publications $max should be accepted");
        }
    }

    public function test_invalid_max_publications_defaults_to_200(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings(['max_publications' => 75]);
        $this->assertSame(200, $result['max_publications']);
    }

    public function test_missing_max_publications_defaults_to_200(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings([]);
        $this->assertSame(200, $result['max_publications']);
    }

    public function test_string_max_publications_cast_to_int(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings(['max_publications' => '100']);
        $this->assertSame(100, $result['max_publications']);
    }

    // --- Update method validation ---

    public function test_valid_update_methods(): void
    {
        $settings = $this->createSettingsWithoutConstructor();

        foreach (['server', 'browser'] as $method) {
            $result = $settings->sanitize_settings(['update_method' => $method]);
            $this->assertSame($method, $result['update_method'], "Update method '$method' should be accepted");
        }
    }

    public function test_invalid_update_method_defaults_to_server(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings(['update_method' => 'carrier_pigeon']);
        $this->assertSame('server', $result['update_method']);
    }

    public function test_missing_update_method_defaults_to_server(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings([]);
        $this->assertSame('server', $result['update_method']);
    }

    // --- Complete output ---

    public function test_sanitize_returns_all_expected_keys(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->sanitize_settings([
            'profile_id' => 'test1234ABC',
            'show_avatar' => '1',
            'show_info' => '1',
            'show_publications' => '1',
            'show_coauthors' => '1',
            'update_frequency' => 'weekly',
            'max_publications' => 200,
            'update_method' => 'server',
        ]);

        $expected_keys = [
            'profile_id', 'show_avatar', 'show_info',
            'show_publications', 'show_coauthors',
            'update_frequency', 'max_publications',
            'update_method'
        ];

        foreach ($expected_keys as $key) {
            $this->assertArrayHasKey($key, $result, "Result should contain key '$key'");
        }
        $this->assertCount(8, $result);
    }

    // --- Constructor hooks ---

    public function test_constructor_registers_expected_hooks(): void
    {
        Functions\expect('add_action')
            ->times(8);

        Functions\expect('add_filter')
            ->once();

        Functions\when('plugin_basename')->justReturn('google-scholar-wp/wp-google-scholar.php');

        new Settings();
    }

    // ==========================================
    // build_import_data (browser-assisted import)
    // ==========================================

    public function test_build_import_data_empty_content_returns_error(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->build_import_data('   ', [], 'test123ABC');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('empty_content', $result['error']['type']);
    }

    public function test_build_import_data_valid_bookmarklet_json(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $json = json_encode([
            'profile_id' => 'test123ABC',
            'name' => 'Jane Bookmarklet',
            'publications' => [[
                'title' => 'Paper One',
                'google_scholar_url' => 'https://scholar.google.com/x',
                'year' => '2021',
                'citations' => 0,
                'citations_url' => '',
            ]],
        ]);

        $result = $settings->build_import_data($json, [], 'test123ABC');

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('Jane Bookmarklet', $result['data']['name']);
        $this->assertCount(1, $result['data']['publications']);
    }

    public function test_build_import_data_invalid_json_returns_error(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->build_import_data('{not valid json', [], 'test123ABC');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('invalid_json', $result['error']['type']);
    }

    public function test_build_import_data_main_profile_html(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');

        // The main profile avatar is attempted (single request); coauthor
        // avatars are always skipped for browser-mode imports.
        Functions\expect('get_posts')->once()->andReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('download_url')->justReturn(new \WP_Error('test', 'mocked'));
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('test', 'mocked'));
        Functions\when('set_transient')->justReturn(true);

        $result = $settings->build_import_data($html, [], 'test123ABC');

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('John Researcher', $result['data']['name']);
        $this->assertCount(3, $result['data']['publications']);
    }

    public function test_build_import_data_unrecognized_content_returns_error(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $result = $settings->build_import_data('<html><body>Just a random page</body></html>', [], 'test123ABC');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('unrecognized_content', $result['error']['type']);
    }

    public function test_build_import_data_fragment_without_base_profile_returns_error(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-publications.html');

        $result = $settings->build_import_data($html, [], 'test', 'append');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('no_base_profile', $result['error']['type']);
    }

    public function test_build_import_data_fragment_merges_into_existing(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-publications.html');
        $existing = [
            'name' => 'Jane Existing',
            'publications' => [
                ['title' => 'Old Paper', 'google_scholar_url' => 'https://scholar.google.com/old'],
            ],
        ];

        $result = $settings->build_import_data($html, $existing, 'test', 'append');

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('Jane Existing', $result['data']['name']);
        $this->assertCount(5, $result['data']['publications']); // 1 existing + 4 from fragment
    }

    public function test_build_import_data_fragment_dedupes_by_url(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $html = <<<'HTML'
<html><body>
<table><tbody id="gsc_a_b">
<tr class="gsc_a_tr">
  <td class="gsc_a_t">
    <a class="gsc_a_at" href="/citations?view_op=view_citation&citation_for_view=test123:dup1">Duplicate Paper</a>
    <div class="gs_gray">A Author</div>
    <div class="gs_gray">Some Venue, 2020</div>
  </td>
  <td class="gsc_a_c"><a class="gsc_a_ac gs_ibl" href="/scholar?cites=1">10</a></td>
  <td class="gsc_a_y"><span class="gsc_a_h gsc_a_hc gs_ibl">2020</span></td>
</tr>
<tr class="gsc_a_tr">
  <td class="gsc_a_t">
    <a class="gsc_a_at" href="/citations?view_op=view_citation&citation_for_view=test123:new1">New Paper</a>
    <div class="gs_gray">B Author</div>
    <div class="gs_gray">Other Venue, 2021</div>
  </td>
  <td class="gsc_a_c"><a class="gsc_a_ac gs_ibl" href="/scholar?cites=2">5</a></td>
  <td class="gsc_a_y"><span class="gsc_a_h gsc_a_hc gs_ibl">2021</span></td>
</tr>
</tbody></table>
</body></html>
HTML;
        $existing = [
            'name' => 'Jane Existing',
            'publications' => [
                [
                    'title' => 'Duplicate Paper',
                    'google_scholar_url' => 'https://scholar.google.com/citations?view_op=view_citation&citation_for_view=test123:dup1',
                ],
            ],
        ];

        $result = $settings->build_import_data($html, $existing, 'test123', 'append');

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']['publications']); // duplicate skipped, new one added
    }

    public function test_build_import_data_appends_complete_later_page_without_replacing_profile(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-publications.html');
        // Real cstart=N pages include this sidebar too; append mode, not an
        // unreliable gsc_prf heuristic, decides the operation.
        $html = str_replace('<body>', '<body><div id="gsc_prf">Profile sidebar</div>', $html);
        $existing = [
            'name' => 'Jane Existing',
            'affiliation' => 'Existing University',
            'publications' => [
                ['title' => 'Old Paper', 'google_scholar_url' => 'https://scholar.google.com/old'],
            ],
        ];

        $result = $settings->build_import_data($html, $existing, 'test', 'append');

        $this->assertArrayHasKey('data', $result);
        $this->assertSame('Jane Existing', $result['data']['name']);
        $this->assertSame('Existing University', $result['data']['affiliation']);
        $this->assertCount(5, $result['data']['publications']);
    }

    public function test_build_import_data_requires_append_action_for_publications_only_page(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-publications.html');

        $result = $settings->build_import_data($html, [], 'test123ABC');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('profile_page_required', $result['error']['type']);
    }

    public function test_build_import_data_rejects_append_for_a_different_profile(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-publications.html');
        $existing = ['name' => 'Jane Existing', 'publications' => []];

        $result = $settings->build_import_data($html, $existing, 'other-profile', 'append');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('wrong_import_profile', $result['error']['type']);
    }

    public function test_build_import_data_limits_appended_publications_to_configured_maximum(): void
    {
        $settings = $this->createSettingsWithoutConstructor();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-publications.html');
        $existing = [
            'name' => 'Jane Existing',
            'publications' => [['title' => 'Old Paper', 'google_scholar_url' => 'https://scholar.google.com/old']],
        ];

        $result = $settings->build_import_data($html, $existing, 'test', 'append', 3);

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(3, $result['data']['publications']);
    }

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

    public function test_process_import_uses_sync_status_message_when_source_is_sync(): void
    {
        $settings = $this->createSettingsWithoutConstructor();

        // Set up basic environment, but we'll override update_option to capture the call
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_filter')->justReturn(true);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_option')->justReturn(true);
        Functions\when('wp_date')->justReturn('2026-08-04 12:00:00');

        $options = array('update_method' => 'browser', 'profile_id' => 'test123ABC', 'max_publications' => 200);
        Functions\when('get_option')->alias(function ($name, $default = false) use ($options) {
            if ($name === 'scholar_profile_settings') {
                return $options;
            }
            if ($name === 'scholar_profile_data') {
                return array();
            }
            return $default;
        });

        // Capture the status message passed to update_option
        $captured_status_message = null;
        Functions\when('update_option')->alias(function ($option, $value) use (&$captured_status_message) {
            if ($option === 'scholar_profile_data_status' && is_array($value) && isset($value['message'])) {
                $captured_status_message = $value['message'];
            }
            return true;
        });

        Functions\expect('get_posts')->once()->andReturn([]);
        Functions\when('download_url')->justReturn(new \WP_Error('test', 'mocked'));
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('test', 'mocked'));

        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');
        $result = $settings->process_import($html, 'replace', 'sync');

        $this->assertArrayHasKey('data', $result);
        $this->assertNotNull($captured_status_message, 'Status message should have been captured');
        $this->assertStringContainsString('Imported via automated sync', $captured_status_message);
    }

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
}
