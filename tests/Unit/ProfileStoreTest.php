<?php

namespace WPScholar\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPScholar\ProfileStore;

class ProfileStoreTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_text_field')->alias(function ($str) {
            return trim((string) $str);
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_set_data_for_one_profile_does_not_touch_another_profiles_option(): void
    {
        // The bug this guards against: all additional profiles used to share
        // one option, so writing profile A's data could clobber profile B's
        // concurrent write. Each profile must now live in its own option.
        $touched_options = [];
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $default;
        });
        Functions\when('update_option')->alias(function ($name) use (&$touched_options) {
            $touched_options[] = $name;
            return true;
        });

        ProfileStore::set_data('profileaaaaa', ['name' => 'A'], '');

        $this->assertSame(
            ['scholar_profile_profile_' . md5('profileaaaaa')],
            $touched_options
        );
        $this->assertNotContains('scholar_profile_profile_' . md5('profilebbbbb'), $touched_options);
    }

    public function test_get_ids_combines_default_and_registered_additional_ids(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('scholar_profile_profiles_index', [])
            ->andReturn(['profilebbbbb']);

        $ids = ProfileStore::get_ids('profileaaaaa');

        $this->assertSame(['profileaaaaa', 'profilebbbbb'], $ids);
    }

    public function test_delete_all_for_additional_profile_only_deletes_its_own_option(): void
    {
        Functions\expect('delete_option')
            ->once()
            ->with('scholar_profile_profile_' . md5('profilebbbbb'));

        ProfileStore::delete_all('profilebbbbb', 'profileaaaaa');
    }

    public function test_delete_all_for_default_profile_deletes_legacy_options(): void
    {
        $deleted = [];
        Functions\expect('delete_option')
            ->times(4)
            ->andReturnUsing(function ($key) use (&$deleted) {
                $deleted[] = $key;
                return true;
            });

        ProfileStore::delete_all('profileaaaaa', 'profileaaaaa');

        $this->assertSame([
            'scholar_profile_data',
            'scholar_profile_last_update',
            'scholar_profile_data_status',
            'scholar_profile_consecutive_failures',
        ], $deleted);
    }
}
