<?php

namespace Tests\Feature\File;

use App\Features\Group\GroupRole;
use App\Features\GroupTalk\Actions\CreateGroupMessage;
use App\Features\GroupTalk\GroupTalkPermissions;
use App\Features\GroupTalk\Queries\GroupTalkMessages;
use App\Features\GroupTalk\Serializers\GroupMessageSerializer;
use App\Files\FileUploader;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Models\MemberImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
        // The URLs a page draws take their host from here, whatever APP_URL the developer's .env holds.
        URL::forceRootUrl('http://localhost');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->storage));

        parent::tearDown();
    }

    /**
     * Every message has its own author with an avatar and answers the one before it, so a query an
     * author, a picture or a reply parent would each show as a difference between the two lengths.
     */
    public function test_signing_a_talk_page_costs_a_fixed_number_of_queries_whatever_its_length(): void
    {
        [$viewer, $group] = $this->talk(messages: 4);
        $short = $this->signingCost($viewer, "/groups/{$group->getKey()}/talk");

        [$viewer, $group] = $this->talk(messages: 12);
        $long = $this->signingCost($viewer, "/groups/{$group->getKey()}/talk");

        $this->assertSame($short, $long);
        $this->assertLessThanOrEqual(3, $long);
    }

    public function test_signing_a_list_of_members_costs_a_fixed_number_of_queries_whatever_its_length(): void
    {
        $viewer = $this->members(5)[0];
        $short = $this->signingCost($viewer, '/member/search');

        $this->members(10);
        $long = $this->signingCost($viewer, '/member/search');

        $this->assertSame($short, $long);
        $this->assertLessThanOrEqual(3, $long);
    }

    /** A page's older half arrives without the messages it answers, so the answered one is known only as a parent. */
    public function test_a_reply_parent_outside_the_page_is_signed_without_being_read_again(): void
    {
        [$viewer, $group] = $this->talk(messages: 2);
        config(['openpne.images.fast_path' => true]);
        $this->actingAs($viewer);
        request()->setLaravelSession($this->app['session.store']);

        [$parent, $reply] = GroupMessage::query()->with(GroupTalkMessages::WITH)->orderBy('id')->get()->all();
        DB::enableQueryLog();
        $row = GroupMessageSerializer::message($reply, GroupTalkPermissions::for($group, $viewer), [], [(int) $parent->getKey() => $parent]);
        $read = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertStringContainsString('s=', (string) $row['inReplyTo']['thumbnailUrl']);
        $this->assertSame([], array_values(array_filter($read, fn (string $sql): bool => preg_match('/from\W+group_messages\b/', $sql) === 1)));
    }

    /** Queries the page takes with the fast path on, less the same page with it off. */
    private function signingCost(Member $viewer, string $url): int
    {
        $counts = [];

        // Off once more first, unmeasured, so neither measured request pays for a cold cache.
        foreach ([null, false, true] as $on) {
            config(['openpne.images.fast_path' => (bool) $on]);
            $this->app->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $page = $this->actingAs($viewer)->get($url)->assertOk();
            $counts[(int) $on] = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertSame((bool) $on, preg_match('/v=[0-9a-f]{8}(?:&amp;|&|\\\\u0026)s=[A-Za-z0-9_-]{22}/', (string) $page->getContent()) === 1);
        }

        return $counts[1] - $counts[0];
    }

    /** @return array{Member, Group} */
    private function talk(int $messages): array
    {
        $members = $this->members($messages);
        $group = Group::factory()->create();
        $post = app(CreateGroupMessage::class);
        $previous = null;

        foreach ($members as $i => $member) {
            GroupMember::query()->create(['group_id' => $group->getKey(), 'member_id' => $member->getKey(), 'role' => $i === 0 ? GroupRole::Admin : GroupRole::Member]);
            $previous = $post($member, $group, "m{$i}", [], [UploadedFile::fake()->image("a{$i}.png", 240, 120), UploadedFile::fake()->image("b{$i}.png", 240, 120)], inReplyTo: $previous);
        }

        return [$members[0], $group];
    }

    /** @return list<Member> each with an avatar */
    private function members(int $count): array
    {
        $members = Member::factory()->count($count)->create()->all();

        foreach ($members as $member) {
            $avatar = app(FileUploader::class)->store(UploadedFile::fake()->image('me.png', 240, 120), 'member', (int) $member->getKey());
            MemberImage::factory()->create(['member_id' => $member->getKey(), 'file_id' => $avatar->getKey()]);
        }

        return $members;
    }
}
