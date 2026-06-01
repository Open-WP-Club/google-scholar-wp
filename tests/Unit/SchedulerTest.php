<?php

namespace WPScholar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPScholar\Scheduler;

class SchedulerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Reset the static $hooks_registered flag
        $reflection = new \ReflectionClass(Scheduler::class);
        $prop = $reflection->getProperty('hooks_registered');
        $prop->setValue(null, false);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Create scheduler without constructor side effects
     */
    private function createSchedulerWithoutConstructor(): Scheduler
    {
        $reflection = new \ReflectionClass(Scheduler::class);
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * Invoke a private method via reflection
     */
    private function invokePrivateMethod($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($method);
        return $method->invokeArgs($object, $args);
    }

    // --- calculate_retry_delay ---

    public function test_calculate_retry_delay_zero_for_low_failures(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $this->assertSame(0, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [0]));
        $this->assertSame(0, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [1]));
        $this->assertSame(0, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [2]));
    }

    public function test_calculate_retry_delay_3_failures(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $this->assertSame(3600, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [3]));
    }

    public function test_calculate_retry_delay_4_failures(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $this->assertSame(7200, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [4]));
    }

    public function test_calculate_retry_delay_5_failures(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $this->assertSame(14400, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [5]));
    }

    public function test_calculate_retry_delay_6_failures(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $this->assertSame(28800, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [6]));
    }

    public function test_calculate_retry_delay_7_failures(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $this->assertSame(57600, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [7]));
    }

    public function test_calculate_retry_delay_capped_at_max(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        // 8+ failures should be capped at 86400
        $this->assertSame(86400, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [8]));
        $this->assertSame(86400, $this->invokePrivateMethod($scheduler, 'calculate_retry_delay', [15]));
    }

    // --- update_data_status ---

    public function test_update_data_status_stores_correct_structure(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        $capturedValue = null;
        Functions\expect('get_option')
            ->with('scholar_profile_consecutive_failures', 0)
            ->andReturn(3);
        Functions\expect('update_option')
            ->once()
            ->andReturnUsing(function ($key, $value) use (&$capturedValue) {
                $capturedValue = $value;
                return true;
            });

        $scheduler->update_data_status('error', 'Something went wrong');

        $this->assertSame('error', $capturedValue['status']);
        $this->assertSame('Something went wrong', $capturedValue['message']);
        $this->assertArrayHasKey('timestamp', $capturedValue);
        $this->assertSame(3, $capturedValue['consecutive_failures']);
    }

    public function test_update_data_status_accepts_valid_statuses(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        foreach (['success', 'stale', 'error', 'updating'] as $status) {
            Functions\expect('get_option')
                ->once()
                ->with('scholar_profile_consecutive_failures', 0)
                ->andReturn(0);
            Functions\expect('update_option')
                ->once()
                ->andReturnUsing(function ($key, $value) use ($status) {
                    $this->assertSame($status, $value['status']);
                    return true;
                });

            $scheduler->update_data_status($status, "Testing $status");
        }
    }

    // --- get_data_status ---

    public function test_get_data_status_returns_stored(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $stored = [
            'status' => 'success',
            'message' => 'All good',
            'timestamp' => 1700000000,
            'consecutive_failures' => 0,
        ];

        Functions\expect('get_option')
            ->with('scholar_profile_data_status', \Mockery::type('array'))
            ->andReturn($stored);

        $result = $scheduler->get_data_status();
        $this->assertSame('success', $result['status']);
        $this->assertSame('All good', $result['message']);
    }

    public function test_get_data_status_returns_defaults_when_empty(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\expect('get_option')
            ->with('scholar_profile_data_status', \Mockery::type('array'))
            ->andReturnUsing(function ($key, $default) {
                return $default;
            });

        $result = $scheduler->get_data_status();
        $this->assertSame('unknown', $result['status']);
        $this->assertSame(0, $result['timestamp']);
        $this->assertSame(0, $result['consecutive_failures']);
    }

    // --- is_data_stale ---

    public function test_is_data_stale_true_when_status_stale(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\expect('get_option')
            ->with('scholar_profile_data_status', \Mockery::type('array'))
            ->andReturn(['status' => 'stale', 'message' => '', 'timestamp' => 0, 'consecutive_failures' => 0]);
        Functions\expect('get_option')
            ->with('scholar_profile_last_update', 0)
            ->andReturn(0);

        $this->assertTrue($scheduler->is_data_stale());
    }

    public function test_is_data_stale_true_when_status_error(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\expect('get_option')
            ->with('scholar_profile_data_status', \Mockery::type('array'))
            ->andReturn(['status' => 'error', 'message' => '', 'timestamp' => 0, 'consecutive_failures' => 0]);
        Functions\expect('get_option')
            ->with('scholar_profile_last_update', 0)
            ->andReturn(0);

        $this->assertTrue($scheduler->is_data_stale());
    }

    public function test_is_data_stale_true_when_exceeds_tolerance(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $lastUpdate = time() - (WEEK_IN_SECONDS * 3);

        Functions\when('get_option')->alias(function ($key, $default = false) use ($lastUpdate) {
            switch ($key) {
                case 'scholar_profile_data_status':
                    return ['status' => 'success', 'message' => '', 'timestamp' => 0, 'consecutive_failures' => 0];
                case 'scholar_profile_last_update':
                    return $lastUpdate;
                case 'scholar_profile_settings':
                    return ['update_frequency' => 'weekly'];
                default:
                    return $default;
            }
        });

        $this->assertTrue($scheduler->is_data_stale());
    }

    public function test_is_data_stale_false_within_tolerance(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $lastUpdate = time() - DAY_IN_SECONDS;

        Functions\when('get_option')->alias(function ($key, $default = false) use ($lastUpdate) {
            switch ($key) {
                case 'scholar_profile_data_status':
                    return ['status' => 'success', 'message' => '', 'timestamp' => 0, 'consecutive_failures' => 0];
                case 'scholar_profile_last_update':
                    return $lastUpdate;
                case 'scholar_profile_settings':
                    return ['update_frequency' => 'weekly'];
                default:
                    return $default;
            }
        });

        $this->assertFalse($scheduler->is_data_stale());
    }

    public function test_is_data_stale_false_with_no_last_update(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\when('get_option')->alias(function ($key, $default = false) {
            switch ($key) {
                case 'scholar_profile_data_status':
                    return ['status' => 'success', 'message' => '', 'timestamp' => 0, 'consecutive_failures' => 0];
                case 'scholar_profile_last_update':
                    return 0;
                default:
                    return $default;
            }
        });

        $this->assertFalse($scheduler->is_data_stale());
    }

    /**
     * @dataProvider tolerancePerFrequencyProvider
     */
    public function test_correct_tolerance_per_frequency(string $frequency, int $staleAge): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();
        $lastUpdate = time() - $staleAge;

        Functions\when('get_option')->alias(function ($key, $default = false) use ($lastUpdate, $frequency) {
            switch ($key) {
                case 'scholar_profile_data_status':
                    return ['status' => 'success', 'message' => '', 'timestamp' => 0, 'consecutive_failures' => 0];
                case 'scholar_profile_last_update':
                    return $lastUpdate;
                case 'scholar_profile_settings':
                    return ['update_frequency' => $frequency];
                default:
                    return $default;
            }
        });

        $this->assertTrue($scheduler->is_data_stale());
    }

    public static function tolerancePerFrequencyProvider(): array
    {
        return [
            'daily exceeds 2 days' => ['daily', DAY_IN_SECONDS * 3],
            'weekly exceeds 2 weeks' => ['weekly', WEEK_IN_SECONDS * 3],
            'monthly exceeds 2 months' => ['monthly', MONTH_IN_SECONDS * 3],
        ];
    }

    // --- clear_stale_data ---

    public function test_clear_stale_data_deletes_options(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        $deleted = [];
        Functions\expect('delete_option')
            ->times(4)
            ->andReturnUsing(function ($key) use (&$deleted) {
                $deleted[] = $key;
                return true;
            });

        $result = $scheduler->clear_stale_data();
        $this->assertTrue($result);

        $expected = [
            'scholar_profile_data',
            'scholar_profile_last_update',
            'scholar_profile_data_status',
            'scholar_profile_consecutive_failures',
        ];
        $this->assertSame($expected, $deleted);
    }

    // --- activate / deactivate ---

    public function test_activate_schedules_when_not_scheduled(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\expect('get_option')
            ->with('scholar_profile_settings')
            ->andReturn(['update_frequency' => 'weekly']);
        Functions\expect('wp_next_scheduled')
            ->with('scholar_profile_update')
            ->andReturn(false);
        Functions\expect('wp_next_scheduled')
            ->with('scholar_profile_cleanup_errors')
            ->andReturn(false);
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'weekly', 'scholar_profile_update');
        Functions\expect('wp_schedule_event')
            ->once()
            ->with(\Mockery::type('int'), 'weekly', 'scholar_profile_cleanup_errors');

        $scheduler->activate();
    }

    public function test_activate_no_double_schedule(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\expect('wp_next_scheduled')
            ->with('scholar_profile_update')
            ->andReturn(1700000000);
        Functions\expect('wp_schedule_event')
            ->never();

        $scheduler->activate();
    }

    public function test_deactivate_clears_hook(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with('scholar_profile_update');

        $scheduler->deactivate();
    }

    // --- reschedule ---

    public function test_reschedule_clears_then_activates(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\expect('get_option')
            ->with('scholar_profile_settings')
            ->andReturn(['update_frequency' => 'weekly']);
        Functions\expect('wp_clear_scheduled_hook')
            ->once()
            ->with('scholar_profile_update');
        Functions\expect('wp_next_scheduled')
            ->with('scholar_profile_update')
            ->andReturn(false);
        Functions\expect('wp_next_scheduled')
            ->with('scholar_profile_cleanup_errors')
            ->andReturn(false);
        Functions\expect('wp_schedule_event')
            ->twice();

        $scheduler->reschedule();
    }

    // --- add_schedules ---

    public function test_add_schedules_adds_configured_frequency(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\expect('get_option')
            ->with('scholar_profile_settings')
            ->andReturn(['update_frequency' => 'monthly']);
        Functions\when('__')->returnArg();

        $schedules = $scheduler->add_schedules([]);
        $this->assertArrayHasKey('monthly', $schedules);
        $this->assertSame(2592000, $schedules['monthly']['interval']);
    }

    public function test_add_schedules_doesnt_overwrite_existing(): void
    {
        $scheduler = $this->createSchedulerWithoutConstructor();

        Functions\expect('get_option')
            ->with('scholar_profile_settings')
            ->andReturn(['update_frequency' => 'weekly']);
        Functions\when('__')->returnArg();

        $existing = ['weekly' => ['interval' => 999, 'display' => 'Custom']];
        $schedules = $scheduler->add_schedules($existing);

        // Should not overwrite
        $this->assertSame(999, $schedules['weekly']['interval']);
    }
}
