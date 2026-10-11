<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Features\GroupTopic\TopicReadAccess;
use App\Files\FileOwners;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupMessage;
use App\Models\Member;
use App\Support\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FilePolicyOwnerMemoTest extends TestCase
{
    use RefreshDatabase;

    private Member $viewer;

    private Group $group;

    private GroupMessage $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewer = Member::factory()->create();
        $this->group = Group::factory()->create(['topic_read_access' => TopicReadAccess::Everyone]);
        $this->message = GroupMessage::factory()->create(['group_id' => $this->group->getKey()]);
        // The unit's settings are read once a request whatever the gate does, so they are not counted.
        Feature::GroupTalk->enabled();
    }

    public function test_a_noted_owner_is_read_from_the_note_relations_and_all(): void
    {
        $file = $this->picture();
        $this->message->setRelation('group', $this->group);
        app(FileOwners::class)->note($this->message);

        $this->assertSame(0, $this->queriesFor(fn () => $this->assertTrue(Gate::forUser($this->viewer)->allows('view', $file))));
    }

    public function test_an_owner_read_for_one_file_answers_the_next(): void
    {
        [$first, $second] = [$this->picture(), $this->picture()];

        $this->assertGreaterThan(0, $this->queriesFor(fn () => $this->assertTrue(Gate::forUser($this->viewer)->allows('view', $first))));
        $this->assertSame(0, $this->queriesFor(fn () => $this->assertTrue(Gate::forUser($this->viewer)->allows('view', $second))));
    }

    public function test_the_memo_ends_with_the_request(): void
    {
        $file = $this->picture();
        Gate::forUser($this->viewer)->allows('view', $file);

        $this->freshRequestState();

        $this->assertGreaterThan(0, $this->queriesFor(fn () => Gate::forUser($this->viewer)->allows('view', $file)));
    }

    public function test_an_owner_that_is_gone_is_asked_for_again(): void
    {
        $owners = app(FileOwners::class);

        $this->assertSame(2, $this->queriesFor(function () use ($owners): void {
            $this->assertNull($owners->find(GroupMessage::class, 424242));
            $this->assertNull($owners->find(GroupMessage::class, 424242));
        }));
    }

    private function picture(): File
    {
        return File::factory()->create([
            'type' => 'image/png',
            'related_entity_type' => 'groupMessage',
            'related_entity_id' => $this->message->getKey(),
        ]);
    }

    private function queriesFor(callable $read): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
