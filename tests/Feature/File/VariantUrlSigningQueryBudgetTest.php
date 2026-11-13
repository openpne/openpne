<?php

namespace Tests\Feature\File;

use App\Features\Group\GroupRole;
use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VariantUrlSigningQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = sys_get_temp_dir().'/openpne-signing-budget-'.bin2hex(random_bytes(6));
        mkdir($this->storage.'/framework', 0777, true);
        $this->app->useStoragePath($this->storage);
        Storage::fake('image_cache');
        config(['openpne.surface_mode' => 'modern_only', 'app.url' => 'http://localhost']);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storage));

        parent::tearDown();
    }

    /** Signing asks FilePolicy about every picture on the page, which must not turn into a query a message. */
    public function test_signing_a_talk_page_costs_a_fixed_number_of_queries_whatever_its_length(): void
    {
        [$viewer, $group] = $this->talk(messages: 4);
        $short = $this->signingCost($viewer, $group);

        [$viewer, $group] = $this->talk(messages: 12);
        $long = $this->signingCost($viewer, $group);

        $this->assertSame($short, $long);
        $this->assertLessThanOrEqual(2, $long);
    }

    /** Queries the page takes with the fast path on, less the same page with it off. */
    private function signingCost(Member $viewer, Group $group): int
    {
        $counts = [];

        // Off once more first, unmeasured, so neither measured request pays for a cold cache.
        foreach ([null, false, true] as $on) {
            config(['openpne.images.fast_path' => (bool) $on]);
            $this->app->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page = $this->actingAs($viewer)->get("/groups/{$group->getKey()}/talk")->assertOk();
            $counts[(int) $on] = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame((bool) $on, preg_match('/v=[0-9a-f]{8}(?:&amp;|&|\\\\u0026)s=[A-Za-z0-9_-]{22}/', (string) $page->getContent()) === 1);
        }

        return $counts[1] - $counts[0];
    }

    /** @return array{Member, Group} */
    private function talk(int $messages): array
    {
        $members = Member::factory()->count(3)->create();
        $group = Group::factory()->create();

        foreach ($members as $i => $member) {
            GroupMember::query()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => $i === 0 ? GroupRole::Admin : GroupRole::Member]);
        }

        $post = app(CreateGroupMessage::class);

        for ($i = 0; $i < $messages; $i++) {
            $post($members[$i % 3], $group, "m{$i}", [], [UploadedFile::fake()->image("a{$i}.png", 240, 120), UploadedFile::fake()->image("b{$i}.png", 240, 120)]);
        }

        return [$members[0], $group];
    }
}
