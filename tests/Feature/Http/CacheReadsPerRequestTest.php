<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Diary;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Services\SnsSettingService;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Under the database store, where a fetch is a query and can be counted: the suite's own array store
 * would pass whatever the services did (docs/internals/runtime.md, "Cache reads").
 */
class CacheReadsPerRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'database']);
    }

    #[DataProvider('pages')]
    public function test_a_page_fetches_each_cache_key_once(string $mode, bool $signedIn, string $uri): void
    {
        config(['openpne.surface_mode' => $mode]);

        $member = Member::factory()->create();
        Diary::factory()->count(3)->create(['member_id' => $member->getKey()]);
        TimelinePost::factory()->count(3)->create(['member_id' => $member->getKey()]);
        Group::factory()->count(2)->create();

        if ($signedIn) {
            $this->actingAs($member);
        }

        // Once unmeasured, so every key is warm: a cold key is fetched twice by design.
        $this->get($uri)->assertOk();

        $fetched = $this->fetchesDuring(fn () => $this->get($uri)->assertOk());

        // A budget met by reading nothing is not a budget.
        $this->assertArrayHasKey($this->stored('sns_settings'), $fetched);
        $this->assertSame([], array_filter($fetched, fn (int $times): bool => $times > 1), 'a cache key was fetched more than once');
    }

    /** @return array<string, array{string, bool, string}> */
    public static function pages(): array
    {
        return [
            'modern front page' => ['modern_only', true, '/'],
            'modern dashboard' => ['modern_only', true, '/dashboard'],
            'modern settings' => ['modern_only', true, '/member/config'],
            'classic home' => ['classic_default', true, '/'],
            'classic diary list' => ['classic_default', true, '/diary/list'],
            'login screen' => ['modern_only', false, '/login'],
        ];
    }

    public function test_a_warm_key_is_fetched_once_however_often_it_is_read(): void
    {
        $settings = app(SnsSettingService::class);
        $settings->get(SnsSettingKey::SnsName);
        $this->endOfRequest();

        $fetched = $this->fetchesDuring(function () use ($settings): void {
            foreach (range(1, 100) as $ignored) {
                $settings->get(SnsSettingKey::SnsName);
            }
        });

        $this->assertSame([$this->stored('sns_settings') => 1], $fetched);
    }

    /** The miss is memoized and the write that follows forgets it, so the next read fetches again. */
    public function test_a_cold_key_is_fetched_twice_and_no_more(): void
    {
        $settings = app(SnsSettingService::class);

        $fetched = $this->fetchesDuring(function () use ($settings): void {
            foreach (range(1, 100) as $ignored) {
                $settings->get(SnsSettingKey::SnsName);
            }
        });

        $this->assertSame([$this->stored('sns_settings') => 2], $fetched);
    }

    public function test_a_setting_written_and_cleared_in_a_request_is_read_back_in_it(): void
    {
        $settings = app(SnsSettingService::class);
        $this->assertSame((string) config('app.name'), $settings->get(SnsSettingKey::SnsName));

        DB::table('sns_settings')->insert(['key' => 'sns_name', 'value' => 'My Group']);

        // The cached map still answers: the row alone changes nothing until the keys are dropped.
        $this->assertSame((string) config('app.name'), $settings->get(SnsSettingKey::SnsName));

        $settings->clearCache();

        $this->assertSame('My Group', $settings->get(SnsSettingKey::SnsName));
    }

    /** What another process wrote and cleared is seen by the request that starts after it. */
    public function test_a_key_cleared_elsewhere_is_read_anew_by_the_next_request(): void
    {
        $settings = app(SnsSettingService::class);
        // Twice: the read that fills a cold key leaves nothing in memory, the one after it does.
        $settings->get(SnsSettingKey::SnsName);
        $settings->get(SnsSettingKey::SnsName);

        DB::table('sns_settings')->insert(['key' => 'sns_name', 'value' => 'My Group']);
        DB::table('cache')->where('key', $this->stored('sns_settings'))->delete();

        $this->assertSame((string) config('app.name'), $settings->get(SnsSettingKey::SnsName));

        $this->endOfRequest();

        $this->assertSame('My Group', app(SnsSettingService::class)->get(SnsSettingKey::SnsName));
    }

    /** The memoized store is scoped; a request ends its scope through TestCase::call(), a direct service read here does not. */
    private function endOfRequest(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function stored(string $key): string
    {
        return config('cache.prefix').$key;
    }

    /**
     * @param  callable(): mixed  $work
     * @return array<string, int> how often each key was fetched from the cache table
     */
    private function fetchesDuring(callable $work): array
    {
        $fetched = [];
        $listening = true;

        DB::listen(function ($query) use (&$fetched, &$listening): void {
            if (! $listening || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'cache')) {
                return;
            }

            foreach ($query->bindings as $key) {
                $fetched[(string) $key] = ($fetched[(string) $key] ?? 0) + 1;
            }
        });

        $work();
        $listening = false;

        return $fetched;
    }
}
