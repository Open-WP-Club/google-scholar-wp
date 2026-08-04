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
