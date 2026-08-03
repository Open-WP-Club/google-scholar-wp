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
            ->times(6);

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
            'name' => 'Jane Bookmarklet',
            'publications' => [['title' => 'Paper One', 'google_scholar_url' => 'https://scholar.google.com/x']],
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

        $result = $settings->build_import_data($html, [], 'test123ABC');

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

        $result = $settings->build_import_data($html, $existing, 'test123ABC');

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

        $result = $settings->build_import_data($html, $existing, 'test123ABC');

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']['publications']); // duplicate skipped, new one added
    }
}
