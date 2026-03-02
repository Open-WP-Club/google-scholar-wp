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

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

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
        ]);

        $expected_keys = [
            'profile_id', 'show_avatar', 'show_info',
            'show_publications', 'show_coauthors',
            'update_frequency', 'max_publications'
        ];

        foreach ($expected_keys as $key) {
            $this->assertArrayHasKey($key, $result, "Result should contain key '$key'");
        }
        $this->assertCount(7, $result);
    }

    // --- Constructor hooks ---

    public function test_constructor_registers_expected_hooks(): void
    {
        Functions\expect('add_action')
            ->times(5);

        Functions\expect('add_filter')
            ->once();

        Functions\when('plugin_basename')->justReturn('google-scholar-wp/wp-google-scholar.php');

        new Settings();
    }
}
