<?php

namespace WPScholar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPScholar\Shortcode;

/**
 * Testable subclass to expose protected methods
 */
class TestableShortcode extends Shortcode
{
    public function __construct()
    {
        // Skip parent constructor to avoid add_shortcode call
    }

    public function public_sort_publications($publications, $sort_by, $sort_order = 'desc')
    {
        return $this->sort_publications($publications, $sort_by, $sort_order);
    }

    public function public_validate_display_data($data)
    {
        return $this->validate_display_data($data);
    }
}

class ShortcodeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private TestableShortcode $shortcode;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->shortcode = new TestableShortcode();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Sample publications for sorting tests
     */
    private function getSamplePublications(): array
    {
        return [
            [
                'title' => 'Attention Is All You Need',
                'year' => '2017',
                'citations' => 95000,
            ],
            [
                'title' => 'BERT: Pre-training of Deep Bidirectional Transformers',
                'year' => '2019',
                'citations' => 72000,
            ],
            [
                'title' => 'Deep Residual Learning for Image Recognition',
                'year' => '2016',
                'citations' => 180000,
            ],
        ];
    }

    // --- Sort by year ---

    public function test_sort_by_year_desc(): void
    {
        $pubs = $this->getSamplePublications();
        $sorted = $this->shortcode->public_sort_publications($pubs, 'year', 'desc');

        $this->assertSame('2019', $sorted[0]['year']);
        $this->assertSame('2017', $sorted[1]['year']);
        $this->assertSame('2016', $sorted[2]['year']);
    }

    public function test_sort_by_year_asc(): void
    {
        $pubs = $this->getSamplePublications();
        $sorted = $this->shortcode->public_sort_publications($pubs, 'year', 'asc');

        $this->assertSame('2016', $sorted[0]['year']);
        $this->assertSame('2017', $sorted[1]['year']);
        $this->assertSame('2019', $sorted[2]['year']);
    }

    public function test_sort_by_year_secondary_sort_by_citations_desc(): void
    {
        $pubs = [
            ['title' => 'Paper A', 'year' => '2020', 'citations' => 100],
            ['title' => 'Paper B', 'year' => '2020', 'citations' => 500],
            ['title' => 'Paper C', 'year' => '2020', 'citations' => 300],
        ];
        $sorted = $this->shortcode->public_sort_publications($pubs, 'year', 'desc');

        // Same year, secondary sort by citations descending
        $this->assertSame(500, $sorted[0]['citations']);
        $this->assertSame(300, $sorted[1]['citations']);
        $this->assertSame(100, $sorted[2]['citations']);
    }

    // --- Sort by citations ---

    public function test_sort_by_citations_desc(): void
    {
        $pubs = $this->getSamplePublications();
        $sorted = $this->shortcode->public_sort_publications($pubs, 'citations', 'desc');

        $this->assertSame(180000, $sorted[0]['citations']);
        $this->assertSame(95000, $sorted[1]['citations']);
        $this->assertSame(72000, $sorted[2]['citations']);
    }

    public function test_sort_by_citations_asc(): void
    {
        $pubs = $this->getSamplePublications();
        $sorted = $this->shortcode->public_sort_publications($pubs, 'citations', 'asc');

        $this->assertSame(72000, $sorted[0]['citations']);
        $this->assertSame(95000, $sorted[1]['citations']);
        $this->assertSame(180000, $sorted[2]['citations']);
    }

    public function test_sort_by_citations_secondary_sort_by_year_desc(): void
    {
        $pubs = [
            ['title' => 'Paper A', 'year' => '2018', 'citations' => 500],
            ['title' => 'Paper B', 'year' => '2021', 'citations' => 500],
            ['title' => 'Paper C', 'year' => '2015', 'citations' => 500],
        ];
        $sorted = $this->shortcode->public_sort_publications($pubs, 'citations', 'desc');

        // Same citations, secondary sort by year descending
        $this->assertSame('2021', $sorted[0]['year']);
        $this->assertSame('2018', $sorted[1]['year']);
        $this->assertSame('2015', $sorted[2]['year']);
    }

    // --- Sort by title ---

    public function test_sort_by_title_asc(): void
    {
        $pubs = $this->getSamplePublications();
        $sorted = $this->shortcode->public_sort_publications($pubs, 'title', 'asc');

        $this->assertSame('Attention Is All You Need', $sorted[0]['title']);
        $this->assertSame('BERT: Pre-training of Deep Bidirectional Transformers', $sorted[1]['title']);
        $this->assertSame('Deep Residual Learning for Image Recognition', $sorted[2]['title']);
    }

    public function test_sort_by_title_desc(): void
    {
        $pubs = $this->getSamplePublications();
        $sorted = $this->shortcode->public_sort_publications($pubs, 'title', 'desc');

        $this->assertSame('Deep Residual Learning for Image Recognition', $sorted[0]['title']);
        $this->assertSame('BERT: Pre-training of Deep Bidirectional Transformers', $sorted[1]['title']);
        $this->assertSame('Attention Is All You Need', $sorted[2]['title']);
    }

    public function test_sort_by_title_case_insensitive(): void
    {
        $pubs = [
            ['title' => 'zebra paper', 'year' => '2020', 'citations' => 10],
            ['title' => 'Alpha Paper', 'year' => '2020', 'citations' => 20],
            ['title' => 'beta paper', 'year' => '2020', 'citations' => 30],
        ];
        $sorted = $this->shortcode->public_sort_publications($pubs, 'title', 'asc');

        $this->assertSame('Alpha Paper', $sorted[0]['title']);
        $this->assertSame('beta paper', $sorted[1]['title']);
        $this->assertSame('zebra paper', $sorted[2]['title']);
    }

    // --- Sort edge cases ---

    public function test_sort_empty_array_unchanged(): void
    {
        $result = $this->shortcode->public_sort_publications([], 'year', 'desc');
        $this->assertSame([], $result);
    }

    public function test_sort_invalid_field_returns_input(): void
    {
        $pubs = $this->getSamplePublications();
        $result = $this->shortcode->public_sort_publications($pubs, 'invalid_field', 'desc');
        $this->assertCount(3, $result);
    }

    public function test_sort_invalid_order_defaults_to_desc(): void
    {
        $pubs = $this->getSamplePublications();
        $sorted = $this->shortcode->public_sort_publications($pubs, 'year', 'invalid');

        // Should default to desc
        $this->assertSame('2019', $sorted[0]['year']);
        $this->assertSame('2017', $sorted[1]['year']);
        $this->assertSame('2016', $sorted[2]['year']);
    }

    public function test_sort_missing_fields_dont_crash(): void
    {
        $pubs = [
            ['title' => 'Paper A'],
            ['title' => 'Paper B', 'year' => '2020', 'citations' => 100],
        ];
        $sorted = $this->shortcode->public_sort_publications($pubs, 'year', 'desc');
        $this->assertCount(2, $sorted);
    }

    // --- validate_display_data ---

    public function test_validate_valid_data(): void
    {
        $data = [
            'name' => 'John Researcher',
            'publications' => [['title' => 'A Paper']],
            'citations' => ['total' => 100, 'h_index' => 5],
        ];
        $this->assertTrue($this->shortcode->public_validate_display_data($data));
    }

    public function test_validate_non_array_returns_false(): void
    {
        $this->assertFalse($this->shortcode->public_validate_display_data('not an array'));
    }

    public function test_validate_missing_name_returns_false(): void
    {
        $data = [
            'publications' => [],
            'citations' => ['total' => 0],
        ];
        $this->assertFalse($this->shortcode->public_validate_display_data($data));
    }

    public function test_validate_empty_name_returns_false(): void
    {
        $data = [
            'name' => '',
            'publications' => [],
            'citations' => ['total' => 0],
        ];
        $this->assertFalse($this->shortcode->public_validate_display_data($data));
    }

    public function test_validate_missing_publications_returns_false(): void
    {
        $data = [
            'name' => 'John',
            'citations' => ['total' => 0],
        ];
        $this->assertFalse($this->shortcode->public_validate_display_data($data));
    }

    public function test_validate_publications_not_array_returns_false(): void
    {
        $data = [
            'name' => 'John',
            'publications' => 'not an array',
            'citations' => ['total' => 0],
        ];
        $this->assertFalse($this->shortcode->public_validate_display_data($data));
    }

    public function test_validate_missing_citations_returns_false(): void
    {
        $data = [
            'name' => 'John',
            'publications' => [],
        ];
        $this->assertFalse($this->shortcode->public_validate_display_data($data));
    }

    public function test_validate_citations_not_array_returns_false(): void
    {
        $data = [
            'name' => 'John',
            'publications' => [],
            'citations' => 'not an array',
        ];
        $this->assertFalse($this->shortcode->public_validate_display_data($data));
    }

    public function test_validate_empty_publications_is_valid(): void
    {
        $data = [
            'name' => 'John',
            'publications' => [],
            'citations' => ['total' => 0],
        ];
        $this->assertTrue($this->shortcode->public_validate_display_data($data));
    }
}
