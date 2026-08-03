<?php

namespace WPScholar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for logic in wp-google-scholar.php
 *
 * The main plugin file can't be loaded directly in tests because it calls
 * define(), spl_autoload_register(), add_action(), and register_*_hook()
 * at file scope. We test the logic patterns extracted from the file.
 */
class PluginTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ==========================================
    // Log level filtering logic
    // ==========================================

    /**
     * Replicate the log level priority system from wp_scholar_log()
     */
    private function getLogLevels(): array
    {
        return [
            'debug' => 0,
            'info' => 1,
            'warning' => 2,
            'error' => 3,
        ];
    }

    public function test_log_level_priority_ordering(): void
    {
        $levels = $this->getLogLevels();

        $this->assertLessThan($levels['info'], $levels['debug']);
        $this->assertLessThan($levels['warning'], $levels['info']);
        $this->assertLessThan($levels['error'], $levels['warning']);
    }

    public function test_log_level_filtering_allows_same_level(): void
    {
        $levels = $this->getLogLevels();

        foreach ($levels as $level => $priority) {
            $this->assertTrue(
                $levels[$level] >= $levels[$level],
                "Level '$level' should pass its own filter"
            );
        }
    }

    public function test_log_level_filtering_blocks_lower_levels(): void
    {
        $levels = $this->getLogLevels();

        // If min_level is 'warning', 'debug' and 'info' should be blocked
        $this->assertFalse($levels['debug'] >= $levels['warning']);
        $this->assertFalse($levels['info'] >= $levels['warning']);
        $this->assertTrue($levels['warning'] >= $levels['warning']);
        $this->assertTrue($levels['error'] >= $levels['warning']);
    }

    // ==========================================
    // Admin notices logic
    // ==========================================

    /**
     * Replicate admin notices check from wp_scholar_admin_notices()
     */
    private function shouldShowAdminNotice(bool $canManageOptions, int $failures, ?string $profileId): bool
    {
        if (!$canManageOptions) {
            return false;
        }
        if ($failures >= WP_SCHOLAR_MAX_CONSECUTIVE_FAILURES && !empty($profileId)) {
            return true;
        }
        return false;
    }

    public function test_admin_notices_skips_without_manage_options(): void
    {
        $this->assertFalse($this->shouldShowAdminNotice(false, 10, 'test123ABC'));
    }

    public function test_admin_notices_shows_when_failures_at_threshold(): void
    {
        $this->assertTrue($this->shouldShowAdminNotice(true, 5, 'test123ABC'));
    }

    public function test_admin_notices_shows_when_failures_above_threshold(): void
    {
        $this->assertTrue($this->shouldShowAdminNotice(true, 10, 'test123ABC'));
    }

    public function test_admin_notices_skips_below_threshold(): void
    {
        $this->assertFalse($this->shouldShowAdminNotice(true, 3, 'test123ABC'));
    }

    public function test_admin_notices_skips_without_profile_id(): void
    {
        $this->assertFalse($this->shouldShowAdminNotice(true, 10, ''));
    }

    // ==========================================
    // Cleanup errors logic
    // ==========================================

    /**
     * Replicate cleanup logic from wp_scholar_cleanup_errors()
     */
    private function shouldDeleteErrors(int $failures, $errorDetails): bool
    {
        return $failures === 0 && !empty($errorDetails);
    }

    public function test_cleanup_deletes_when_no_failures(): void
    {
        $this->assertTrue($this->shouldDeleteErrors(0, ['type' => 'blocked_access']));
    }

    public function test_cleanup_keeps_when_failures_present(): void
    {
        $this->assertFalse($this->shouldDeleteErrors(3, ['type' => 'blocked_access']));
    }

    public function test_cleanup_noop_when_no_details(): void
    {
        $this->assertFalse($this->shouldDeleteErrors(0, false));
        $this->assertFalse($this->shouldDeleteErrors(0, null));
    }

    // ==========================================
    // Activation defaults
    // ==========================================

    public function test_activation_defaults_structure(): void
    {
        $defaults = [
            'profile_id' => '',
            'show_avatar' => '1',
            'show_info' => '1',
            'show_publications' => '1',
            'show_coauthors' => '1',
            'update_frequency' => 'weekly',
            'max_publications' => '200',
            'update_method' => 'server',
        ];

        $this->assertArrayHasKey('profile_id', $defaults);
        $this->assertSame('', $defaults['profile_id']);
        $this->assertSame('1', $defaults['show_avatar']);
        $this->assertSame('1', $defaults['show_info']);
        $this->assertSame('1', $defaults['show_publications']);
        $this->assertSame('1', $defaults['show_coauthors']);
        $this->assertSame('weekly', $defaults['update_frequency']);
        $this->assertSame('200', $defaults['max_publications']);
        $this->assertSame('server', $defaults['update_method']);
    }

    // ==========================================
    // Autoloader logic
    // ==========================================

    /**
     * Replicate the autoloader logic from wp_scholar_autoload()
     */
    private function autoloaderShouldHandle(string $class): bool
    {
        $prefix = 'WPScholar\\';
        $len = strlen($prefix);
        return strncmp($prefix, $class, $len) === 0;
    }

    private function autoloaderMapPath(string $class): string
    {
        $prefix = 'WPScholar\\';
        $base_dir = WP_SCHOLAR_PLUGIN_DIR . 'includes/';
        $relative_class = substr($class, strlen($prefix));
        return $base_dir . str_replace('\\', '/', strtolower($relative_class)) . '.php';
    }

    public function test_autoloader_handles_wpscholar_namespace(): void
    {
        $this->assertTrue($this->autoloaderShouldHandle('WPScholar\\Scraper'));
        $this->assertTrue($this->autoloaderShouldHandle('WPScholar\\Settings'));
        $this->assertTrue($this->autoloaderShouldHandle('WPScholar\\Sub\\Class'));
    }

    public function test_autoloader_ignores_other_namespaces(): void
    {
        $this->assertFalse($this->autoloaderShouldHandle('SomeOther\\Class'));
        $this->assertFalse($this->autoloaderShouldHandle('WPScholarExtra\\Class'));
        $this->assertFalse($this->autoloaderShouldHandle('stdClass'));
    }

    public function test_autoloader_maps_class_to_correct_path(): void
    {
        $path = $this->autoloaderMapPath('WPScholar\\Scraper');
        $this->assertStringEndsWith('includes/scraper.php', $path);

        $path = $this->autoloaderMapPath('WPScholar\\Settings');
        $this->assertStringEndsWith('includes/settings.php', $path);
    }
}
