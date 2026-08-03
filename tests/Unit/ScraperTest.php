<?php

namespace WPScholar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPScholar\Scraper;

class ScraperTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->fixturesDir = dirname(__DIR__) . '/Fixtures/';
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Invoke a private/protected method via reflection
     */
    private function invokeMethod($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionClass($object);
        $method = $reflection->getMethod($method);
        return $method->invokeArgs($object, $args);
    }

    // ==========================================
    // set_config / get_config
    // ==========================================

    public function test_default_config(): void
    {
        $scraper = new Scraper();
        $config = $scraper->get_config();

        $this->assertSame(200, $config['max_publications']);
        $this->assertSame(20, $config['page_size']);
        $this->assertSame(1, $config['request_delay']);
    }

    public function test_set_config_valid_values(): void
    {
        $scraper = new Scraper();
        $scraper->set_config([
            'max_publications' => 100,
            'page_size' => 50,
            'request_delay' => 2,
        ]);

        $config = $scraper->get_config();
        $this->assertSame(100, $config['max_publications']);
        $this->assertSame(50, $config['page_size']);
        $this->assertEquals(2, $config['request_delay']);
    }

    public function test_set_config_clamps_min(): void
    {
        $scraper = new Scraper();
        $scraper->set_config([
            'max_publications' => 1,
            'page_size' => 1,
            'request_delay' => 0.1,
        ]);

        $config = $scraper->get_config();
        $this->assertSame(20, $config['max_publications']);
        $this->assertSame(10, $config['page_size']);
        $this->assertEquals(0.5, $config['request_delay']);
    }

    public function test_set_config_clamps_max(): void
    {
        $scraper = new Scraper();
        $scraper->set_config([
            'max_publications' => 10000,
            'page_size' => 1000,
            'request_delay' => 100,
        ]);

        $config = $scraper->get_config();
        $this->assertSame(500, $config['max_publications']);
        $this->assertSame(100, $config['page_size']);
        $this->assertEquals(5, $config['request_delay']);
    }

    public function test_set_config_ignores_unknown_keys(): void
    {
        $scraper = new Scraper();
        $scraper->set_config(['unknown_key' => 'value']);
        $config = $scraper->get_config();

        // Should remain at defaults
        $this->assertSame(200, $config['max_publications']);
    }

    // ==========================================
    // validate_scraped_data
    // ==========================================

    public function test_validate_non_array(): void
    {
        $this->assertFalse(Scraper::validate_scraped_data('not an array'));
    }

    public function test_validate_missing_name(): void
    {
        $this->assertFalse(Scraper::validate_scraped_data([
            'publications' => [['title' => 'Paper']],
        ]));
    }

    public function test_validate_empty_name(): void
    {
        $this->assertFalse(Scraper::validate_scraped_data([
            'name' => '',
            'publications' => [['title' => 'Paper']],
        ]));
    }

    public function test_validate_missing_publications(): void
    {
        $this->assertFalse(Scraper::validate_scraped_data([
            'name' => 'John',
        ]));
    }

    public function test_validate_publications_not_array(): void
    {
        $this->assertFalse(Scraper::validate_scraped_data([
            'name' => 'John',
            'publications' => 'not an array',
        ]));
    }

    public function test_validate_zero_pubs_with_existing_data(): void
    {
        Functions\expect('get_option')
            ->with('scholar_profile_data')
            ->andReturn([
                'name' => 'John',
                'publications' => [['title' => 'Existing Paper']],
            ]);

        $this->assertFalse(Scraper::validate_scraped_data([
            'name' => 'John',
            'publications' => [],
        ]));
    }

    public function test_validate_zero_pubs_without_existing_data(): void
    {
        // Empty array is considered "empty" by PHP's empty() so validation
        // fails at the required fields check before reaching the zero-pubs logic.
        $this->assertFalse(Scraper::validate_scraped_data([
            'name' => 'John',
            'publications' => [],
        ]));
    }

    public function test_validate_valid_data(): void
    {
        $this->assertTrue(Scraper::validate_scraped_data([
            'name' => 'John',
            'publications' => [['title' => 'Paper']],
        ]));
    }

    // ==========================================
    // Error details
    // ==========================================

    public function test_error_details_initially_null(): void
    {
        $scraper = new Scraper();
        $this->assertNull($scraper->get_last_error_details());
    }

    public function test_clear_error_details(): void
    {
        $scraper = new Scraper();
        // Trigger an error by scraping empty profile ID
        $scraper->scrape('');
        $this->assertNotNull($scraper->get_last_error_details());

        $scraper->clear_error_details();
        $this->assertNull($scraper->get_last_error_details());
    }

    public function test_scrape_empty_sets_invalid_input(): void
    {
        $scraper = new Scraper();
        $result = $scraper->scrape('');

        $this->assertFalse($result);
        $error = $scraper->get_last_error_details();
        $this->assertSame('invalid_input', $error['type']);
    }

    // ==========================================
    // handle_http_error (private)
    // ==========================================

    /**
     * @dataProvider httpErrorProvider
     */
    public function test_handle_http_error(int $statusCode, string $expectedType): void
    {
        $scraper = new Scraper();
        $this->invokeMethod($scraper, 'handle_http_error', [$statusCode, 'https://example.com', 'test123']);

        $error = $scraper->get_last_error_details();
        $this->assertSame($expectedType, $error['type']);
        $this->assertSame($statusCode, $error['status_code']);
    }

    public static function httpErrorProvider(): array
    {
        return [
            '403 -> blocked_access' => [403, 'blocked_access'],
            '404 -> profile_not_found' => [404, 'profile_not_found'],
            '429 -> rate_limited' => [429, 'rate_limited'],
            '503 -> service_unavailable' => [503, 'service_unavailable'],
            '502 -> gateway_error' => [502, 'gateway_error'],
            '504 -> gateway_error' => [504, 'gateway_error'],
            '500 -> http_error' => [500, 'http_error'],
        ];
    }

    // ==========================================
    // is_valid_scholar_profile (private)
    // ==========================================

    public function test_is_valid_scholar_profile_with_valid_html(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');

        $result = $this->invokeMethod($scraper, 'is_valid_scholar_profile', [$html]);
        $this->assertTrue($result);
    }

    public function test_is_valid_scholar_profile_missing_gsc_prf(): void
    {
        $scraper = new Scraper();
        $html = '<html><body><div>No profile here</div></body></html>';

        $result = $this->invokeMethod($scraper, 'is_valid_scholar_profile', [$html]);
        $this->assertFalse($result);
    }

    // ==========================================
    // detect_scholar_errors (private)
    // ==========================================

    public function test_detect_profile_unavailable(): void
    {
        $scraper = new Scraper();
        $html = '<html><body>This profile is not available for viewing.</body></html>';

        $result = $this->invokeMethod($scraper, 'detect_scholar_errors', [$html]);
        $this->assertTrue($result);
        $this->assertSame('profile_unavailable', $scraper->get_last_error_details()['type']);
    }

    public function test_detect_profile_private(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-error-private.html');

        $result = $this->invokeMethod($scraper, 'detect_scholar_errors', [$html]);
        $this->assertTrue($result);
        $this->assertSame('profile_private', $scraper->get_last_error_details()['type']);
    }

    public function test_detect_profile_not_found(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-error-not-found.html');

        $result = $this->invokeMethod($scraper, 'detect_scholar_errors', [$html]);
        $this->assertTrue($result);
        $this->assertSame('profile_not_found', $scraper->get_last_error_details()['type']);
    }

    public function test_detect_citations_unavailable(): void
    {
        $scraper = new Scraper();
        $html = '<html><body>Citations to this profile are not available at this time.</body></html>';

        $result = $this->invokeMethod($scraper, 'detect_scholar_errors', [$html]);
        $this->assertTrue($result);
        $this->assertSame('citations_unavailable', $scraper->get_last_error_details()['type']);
    }

    public function test_detect_login_redirect(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-login-redirect.html');

        $result = $this->invokeMethod($scraper, 'detect_scholar_errors', [$html]);
        $this->assertTrue($result);
        $this->assertSame('login_required', $scraper->get_last_error_details()['type']);
    }

    public function test_detect_clean_html_returns_false(): void
    {
        $scraper = new Scraper();
        $html = '<html><body><div>Normal page content</div></body></html>';

        $result = $this->invokeMethod($scraper, 'detect_scholar_errors', [$html]);
        $this->assertFalse($result);
    }

    // ==========================================
    // parse_main_profile_html (private)
    // ==========================================

    /**
     * Set up common mocks for parse_main_profile_html tests (avatar download)
     */
    private function mockAvatarDownloadDependencies(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('download_url')->justReturn(new \WP_Error('test', 'mocked'));
        Functions\when('wp_remote_get')->justReturn(new \WP_Error('test', 'mocked'));
        Functions\when('set_transient')->justReturn(true);
    }

    public function test_parse_main_profile_extracts_name_and_affiliation(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');
        $this->mockAvatarDownloadDependencies();

        $result = $this->invokeMethod($scraper, 'parse_main_profile_html', [$html, 'test123']);

        $this->assertIsArray($result);
        $this->assertSame('John Researcher', $result['name']);
        $this->assertSame('Stanford University', $result['affiliation']);
    }

    public function test_parse_main_profile_extracts_citation_metrics(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');
        $this->mockAvatarDownloadDependencies();

        $result = $this->invokeMethod($scraper, 'parse_main_profile_html', [$html, 'test123']);

        $this->assertSame(350000, $result['citations']['total']);
        $this->assertSame(200000, $result['citations']['since_2019']);
        $this->assertSame(150, $result['citations']['h_index']);
        $this->assertSame(120, $result['citations']['h_index_2019']);
        $this->assertSame(400, $result['citations']['i10_index']);
        $this->assertSame(350, $result['citations']['i10_index_2019']);
    }

    public function test_parse_main_profile_extracts_interests(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');
        $this->mockAvatarDownloadDependencies();

        $result = $this->invokeMethod($scraper, 'parse_main_profile_html', [$html, 'test123']);

        $this->assertCount(3, $result['interests']);
        $this->assertSame('Machine Learning', $result['interests'][0]['text']);
        $this->assertSame('Deep Learning', $result['interests'][1]['text']);
        $this->assertSame('Natural Language Processing', $result['interests'][2]['text']);
    }

    public function test_parse_main_profile_extracts_coauthors(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');
        $this->mockAvatarDownloadDependencies();

        $result = $this->invokeMethod($scraper, 'parse_main_profile_html', [$html, 'test123']);

        $this->assertCount(2, $result['coauthors']);
        $this->assertSame('Jane Colleague', $result['coauthors'][0]['name']);
        $this->assertSame('MIT', $result['coauthors'][0]['title']);
        $this->assertSame('Bob Collaborator', $result['coauthors'][1]['name']);
        $this->assertSame('Google Research', $result['coauthors'][1]['title']);
    }

    public function test_parse_main_profile_returns_false_without_name(): void
    {
        $scraper = new Scraper();
        // HTML with gsc_prf but no name in gsc_prf_in
        $html = '<html><body><div id="gsc_prf"><div id="gsc_prf_in"></div></div></body></html>';

        $result = $this->invokeMethod($scraper, 'parse_main_profile_html', [$html, 'test123']);
        $this->assertFalse($result);
    }

    // ==========================================
    // extract_publications_from_html (private)
    // ==========================================

    public function test_extract_publications_from_main_fixture(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');

        $pubs = $this->invokeMethod($scraper, 'extract_publications_from_html', [$html]);

        $this->assertCount(3, $pubs);

        // First publication
        $this->assertSame('Attention Is All You Need', $pubs[0]['title']);
        $this->assertSame('2017', $pubs[0]['year']);
        $this->assertSame(95000, $pubs[0]['citations']);
        $this->assertStringContainsString('A Vaswani', $pubs[0]['authors']);

        // Second publication
        $this->assertStringContainsString('BERT', $pubs[1]['title']);
        $this->assertSame('2019', $pubs[1]['year']);
        $this->assertSame(72000, $pubs[1]['citations']);

        // Third publication
        $this->assertSame('Deep Residual Learning for Image Recognition', $pubs[2]['title']);
        $this->assertSame('2016', $pubs[2]['year']);
        $this->assertSame(180000, $pubs[2]['citations']);
    }

    public function test_extract_publications_from_publications_fixture(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-publications.html');

        $pubs = $this->invokeMethod($scraper, 'extract_publications_from_html', [$html]);

        $this->assertCount(4, $pubs);
        $this->assertSame(0, $pubs[3]['citations']); // Last one has 0 citations
    }

    public function test_extract_publications_empty_html(): void
    {
        $scraper = new Scraper();
        $html = '<html><body></body></html>';

        $pubs = $this->invokeMethod($scraper, 'extract_publications_from_html', [$html]);
        $this->assertEmpty($pubs);
    }

    // ==========================================
    // scrape() integration (mocked HTTP)
    // ==========================================

    public function test_scrape_wp_error_sets_network_error(): void
    {
        $scraper = new Scraper();

        Functions\when('wp_remote_get')
            ->justReturn(new \WP_Error('http_request_failed', 'Connection timed out'));

        $result = $scraper->scrape('test123ABC');

        $this->assertFalse($result);
        $error = $scraper->get_last_error_details();
        $this->assertSame('network_error', $error['type']);
    }

    public function test_scrape_http_403_sets_blocked_access(): void
    {
        $scraper = new Scraper();

        Functions\when('wp_remote_get')
            ->justReturn(['response' => ['code' => 403], 'body' => 'Forbidden']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(403);

        $result = $scraper->scrape('test123ABC');

        $this->assertFalse($result);
        $error = $scraper->get_last_error_details();
        $this->assertSame('blocked_access', $error['type']);
    }

    public function test_scrape_empty_body_sets_empty_response(): void
    {
        $scraper = new Scraper();

        Functions\when('wp_remote_get')
            ->justReturn(['response' => ['code' => 200], 'body' => '']);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('');

        $result = $scraper->scrape('test123ABC');

        $this->assertFalse($result);
        $error = $scraper->get_last_error_details();
        $this->assertSame('empty_response', $error['type']);
    }

    // ==========================================
    // cleanup_old_images
    // ==========================================

    public function test_cleanup_no_attachments(): void
    {
        $scraper = new Scraper();

        Functions\expect('get_posts')
            ->once()
            ->andReturn([]);

        $result = $scraper->cleanup_old_images('test123ABC');
        $this->assertSame(0, $result);
    }

    public function test_cleanup_deletes_matching(): void
    {
        $scraper = new Scraper();

        $attachment1 = (object) ['ID' => 10];
        $attachment2 = (object) ['ID' => 20];

        Functions\expect('get_posts')
            ->once()
            ->andReturn([$attachment1, $attachment2]);
        Functions\expect('wp_delete_attachment')
            ->with(10, true)
            ->andReturn(true);
        Functions\expect('wp_delete_attachment')
            ->with(20, true)
            ->andReturn(true);

        $result = $scraper->cleanup_old_images('test123ABC');
        $this->assertSame(2, $result);
    }

    // ==========================================
    // import_main_profile_html
    // ==========================================

    public function test_import_main_profile_html_skips_avatar_download_when_requested(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');

        // If avatar download is truly skipped, none of these should be touched.
        Functions\expect('get_posts')->never();
        Functions\expect('download_url')->never();
        Functions\expect('wp_remote_get')->never();
        Functions\expect('media_handle_sideload')->never();

        $result = $scraper->import_main_profile_html($html, 'test123ABC', true);

        $this->assertIsArray($result);
        $this->assertSame('John Researcher', $result['name']);
        $this->assertSame('Stanford University', $result['affiliation']);
        $this->assertSame('', $result['avatar']);
    }

    public function test_import_main_profile_html_downloads_avatar_by_default(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');
        $this->mockAvatarDownloadDependencies();

        $result = $scraper->import_main_profile_html($html, 'test123ABC');

        $this->assertIsArray($result);
        $this->assertSame('John Researcher', $result['name']);
    }

    public function test_import_main_profile_html_skips_coauthor_avatar_downloads(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-main.html');

        Functions\expect('get_posts')->never();
        Functions\expect('download_url')->never();
        Functions\expect('wp_remote_get')->never();

        $result = $scraper->import_main_profile_html($html, 'test123ABC', true);

        $this->assertCount(2, $result['coauthors']);
        $this->assertSame('', $result['coauthors'][0]['avatar']);
        $this->assertSame('', $result['coauthors'][1]['avatar']);
    }

    public function test_import_main_profile_html_returns_false_for_invalid_content(): void
    {
        $scraper = new Scraper();
        $html = '<html><body><div>Not a scholar profile</div></body></html>';

        $result = $scraper->import_main_profile_html($html, 'test123ABC', true);
        $this->assertFalse($result);
    }

    public function test_import_main_profile_html_sets_error_when_name_missing(): void
    {
        $scraper = new Scraper();
        // Has 'gsc_prf' so it passes the validity gate, but no actual name node.
        $html = '<html><body><div id="gsc_prf"><div id="gsc_prf_in"></div></div></body></html>';

        $result = $scraper->import_main_profile_html($html, 'test123ABC', true);

        $this->assertFalse($result);
        $this->assertNotNull($scraper->get_last_error_details());
    }

    public function test_import_main_profile_html_returns_false_for_empty_input(): void
    {
        $scraper = new Scraper();
        $result = $scraper->import_main_profile_html('', 'test123ABC', true);

        $this->assertFalse($result);
        $this->assertSame('empty_input', $scraper->get_last_error_details()['type']);
    }

    // ==========================================
    // import_publications_fragment_html
    // ==========================================

    public function test_import_publications_fragment_html_extracts_rows(): void
    {
        $scraper = new Scraper();
        $html = file_get_contents($this->fixturesDir . 'scholar-profile-publications.html');

        $pubs = $scraper->import_publications_fragment_html($html);

        $this->assertCount(4, $pubs);
    }

    public function test_import_publications_fragment_html_empty_input(): void
    {
        $scraper = new Scraper();
        $this->assertSame([], $scraper->import_publications_fragment_html(''));
    }

    // ==========================================
    // import_from_bookmarklet_json
    // ==========================================

    public function test_import_from_bookmarklet_json_valid(): void
    {
        $scraper = new Scraper();
        $json = json_encode([
            'name' => 'Jane Bookmarklet',
            'affiliation' => 'Example University',
            'interests' => [['text' => 'Robotics', 'url' => 'https://scholar.google.com/x']],
            'citations' => [
                'total' => 500,
                'h_index' => 10,
                'i10_index' => 8,
                'since_2019' => 300,
                'h_index_2019' => 9,
                'i10_index_2019' => 7,
            ],
            'coauthors' => [['name' => 'Bob', 'profile_url' => 'https://scholar.google.com/y', 'title' => 'MIT', 'avatar' => '']],
            'publications' => [
                ['title' => 'Paper One', 'year' => '2021', 'citations' => 12],
            ],
        ]);

        $result = $scraper->import_from_bookmarklet_json($json);

        $this->assertIsArray($result);
        $this->assertSame('Jane Bookmarklet', $result['name']);
        $this->assertSame('Example University', $result['affiliation']);
        $this->assertSame('', $result['avatar']);
        $this->assertSame(500, $result['citations']['total']);
        $this->assertSame(10, $result['citations']['h_index']);
        $this->assertCount(1, $result['publications']);
        $this->assertSame('Paper One', $result['publications'][0]['title']);
        $this->assertCount(1, $result['coauthors']);
    }

    public function test_import_from_bookmarklet_json_invalid_json_returns_false(): void
    {
        $scraper = new Scraper();
        $result = $scraper->import_from_bookmarklet_json('{not valid json');

        $this->assertFalse($result);
        $this->assertSame('invalid_json', $scraper->get_last_error_details()['type']);
    }

    public function test_import_from_bookmarklet_json_missing_name_returns_false(): void
    {
        $scraper = new Scraper();
        $json = json_encode(['publications' => [['title' => 'Paper']]]);

        $result = $scraper->import_from_bookmarklet_json($json);

        $this->assertFalse($result);
        $this->assertSame('invalid_bookmarklet_data', $scraper->get_last_error_details()['type']);
    }

    public function test_import_from_bookmarklet_json_missing_publications_returns_false(): void
    {
        $scraper = new Scraper();
        $json = json_encode(['name' => 'Jane']);

        $result = $scraper->import_from_bookmarklet_json($json);

        $this->assertFalse($result);
        $this->assertSame('invalid_bookmarklet_data', $scraper->get_last_error_details()['type']);
    }

    public function test_import_from_bookmarklet_json_defaults_missing_optional_fields(): void
    {
        $scraper = new Scraper();
        $json = json_encode(['name' => 'Jane', 'publications' => []]);

        $result = $scraper->import_from_bookmarklet_json($json);

        $this->assertIsArray($result);
        $this->assertSame('', $result['affiliation']);
        $this->assertSame([], $result['interests']);
        $this->assertSame([], $result['coauthors']);
        $this->assertSame(0, $result['citations']['total']);
    }
}
