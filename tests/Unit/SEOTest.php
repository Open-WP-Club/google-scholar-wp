<?php

namespace WPScholar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPScholar\SEO;

class SEOTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('esc_attr')->returnArg();
        Functions\when('sanitize_title')->alias(function ($title) {
            return strtolower(str_replace(' ', '-', $title));
        });
        Functions\when('wp_json_encode')->alias(function ($data, $flags = 0) {
            return json_encode($data, $flags | JSON_UNESCAPED_SLASHES);
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function getSampleData(): array
    {
        return [
            'name' => 'John Researcher',
            'affiliation' => 'Stanford University',
            'avatar' => 'https://example.com/avatar.jpg',
            'interests' => [
                ['text' => 'Machine Learning', 'url' => 'https://scholar.google.com/...'],
                ['text' => 'Deep Learning', 'url' => 'https://scholar.google.com/...'],
            ],
            'citations' => [
                'total' => 350000,
                'h_index' => 150,
                'i10_index' => 400,
                'since_2019' => 200000,
                'h_index_2019' => 120,
                'i10_index_2019' => 350,
            ],
            'publications' => [
                ['title' => 'Test Paper', 'year' => '2020', 'citations' => 100],
            ],
            'coauthors' => [],
        ];
    }

    // --- output_scholar_tags ---

    public function test_output_scholar_tags_outputs_all_meta_tags(): void
    {
        $seo = new SEO();
        $data = $this->getSampleData();

        ob_start();
        $seo->output_scholar_tags($data);
        $output = ob_get_clean();

        $this->assertStringContainsString('citation_author', $output);
        $this->assertStringContainsString('John Researcher', $output);
        $this->assertStringContainsString('citation_author_institution', $output);
        $this->assertStringContainsString('Stanford University', $output);
        $this->assertStringContainsString('citation_keywords', $output);
        $this->assertStringContainsString('Machine Learning', $output);
        $this->assertStringContainsString('citation_total', $output);
        $this->assertStringContainsString('350000', $output);
        $this->assertStringContainsString('citation_h_index', $output);
        $this->assertStringContainsString('150', $output);
        $this->assertStringContainsString('profile:username', $output);
    }

    public function test_output_scholar_tags_no_duplicate_output(): void
    {
        $seo = new SEO();
        $data = $this->getSampleData();

        ob_start();
        $seo->output_scholar_tags($data);
        $first = ob_get_clean();

        ob_start();
        $seo->output_scholar_tags($data);
        $second = ob_get_clean();

        $this->assertNotEmpty($first);
        $this->assertEmpty($second);
    }

    public function test_output_scholar_tags_skips_empty_data(): void
    {
        $seo = new SEO();

        ob_start();
        $seo->output_scholar_tags([]);
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function test_output_scholar_tags_skips_null_data(): void
    {
        $seo = new SEO();

        ob_start();
        $seo->output_scholar_tags(null);
        $output = ob_get_clean();

        $this->assertEmpty($output);
    }

    public function test_output_scholar_tags_handles_missing_optional_fields(): void
    {
        $seo = new SEO();
        $data = [
            'name' => 'Jane Doe',
            'citations' => ['total' => 0],
        ];

        ob_start();
        $seo->output_scholar_tags($data);
        $output = ob_get_clean();

        $this->assertStringContainsString('citation_author', $output);
        $this->assertStringContainsString('Jane Doe', $output);
        // Should not contain institution since no affiliation
        $this->assertStringNotContainsString('citation_author_institution', $output);
        // Should not contain citation_total since total is 0 (empty)
        $this->assertStringNotContainsString('citation_total', $output);
    }

    public function test_output_scholar_tags_handles_legacy_interest_format(): void
    {
        $seo = new SEO();
        $data = [
            'name' => 'Legacy Researcher',
            'affiliation' => 'Old University',
            'interests' => ['Machine Learning', 'AI', 'Robotics'],
            'citations' => ['total' => 100, 'h_index' => 5],
        ];

        ob_start();
        $seo->output_scholar_tags($data);
        $output = ob_get_clean();

        $this->assertStringContainsString('citation_keywords', $output);
        $this->assertStringContainsString('Machine Learning', $output);
    }

    // --- add_profile_seo ---

    public function test_add_profile_seo_skips_empty_data(): void
    {
        $seo = new SEO();

        Functions\expect('add_action')->never();

        $seo->add_profile_seo([], []);
    }

    public function test_add_profile_seo_registers_wp_footer_action(): void
    {
        $seo = new SEO();
        $data = $this->getSampleData();

        Functions\expect('add_action')
            ->once()
            ->with('wp_footer', \Mockery::type('Closure'));

        $seo->add_profile_seo($data, []);
    }

    // --- add_structured_data ---

    public function test_add_structured_data_skips_empty_data(): void
    {
        $seo = new SEO();

        Functions\expect('add_action')->never();

        $seo->add_structured_data([], []);
    }

    public function test_add_structured_data_registers_wp_footer(): void
    {
        $seo = new SEO();
        $data = $this->getSampleData();

        Functions\expect('add_action')
            ->once()
            ->with('wp_footer', \Mockery::type('Closure'));

        $seo->add_structured_data($data, [['title' => 'Paper']]);
    }

    public function test_structured_data_produces_valid_json_ld(): void
    {
        $seo = new SEO();
        $data = $this->getSampleData();
        $pubs = [['title' => 'Test Paper']];

        // Capture the closure registered with add_action
        $closure = null;
        Functions\expect('add_action')
            ->with('wp_footer', \Mockery::type('Closure'))
            ->andReturnUsing(function ($hook, $cb) use (&$closure) {
                $closure = $cb;
            });

        $seo->add_structured_data($data, $pubs);

        // Execute the closure and capture output
        ob_start();
        $closure();
        $output = ob_get_clean();

        // Should contain Person schema
        $this->assertStringContainsString('application/ld+json', $output);
        $this->assertStringContainsString('"@type":"Person"', $output);
        $this->assertStringContainsString('John Researcher', $output);

        // Should contain ItemList schema (publications provided)
        $this->assertStringContainsString('"@type":"ItemList"', $output);
    }

    public function test_structured_data_omits_itemlist_without_publications(): void
    {
        $seo = new SEO();
        $data = $this->getSampleData();

        $closure = null;
        Functions\expect('add_action')
            ->with('wp_footer', \Mockery::type('Closure'))
            ->andReturnUsing(function ($hook, $cb) use (&$closure) {
                $closure = $cb;
            });

        $seo->add_structured_data($data, []);

        ob_start();
        $closure();
        $output = ob_get_clean();

        $this->assertStringContainsString('"@type":"Person"', $output);
        $this->assertStringNotContainsString('"@type":"ItemList"', $output);
    }
}
