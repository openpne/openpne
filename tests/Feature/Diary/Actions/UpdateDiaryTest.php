<?php

namespace Tests\Feature\Diary\Actions;

use App\Features\Diary\Actions\UpdateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Exceptions\DiaryActionException;
use App\Features\Diary\Exceptions\DiaryActionFailure;
use App\Features\Diary\Visibility;
use App\Models\Diary;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateDiaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_update_diary(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey()]);
        $data = new DiaryFormData('New title', 'New body', Visibility::Private);

        (new UpdateDiary)($owner, $diary, $data);

        $this->assertDatabaseHas('diaries', [
            'id' => $diary->getKey(),
            'title' => 'New title',
            'visibility' => Visibility::Private->value,
        ]);
    }

    public function test_non_owner_throws_not_author_and_leaves_db_unchanged(): void
    {
        [$owner, $other] = Member::factory()->count(2)->create()->all();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'title' => 'Original',
        ]);
        $data = new DiaryFormData('Hacked', 'body', Visibility::Members);

        try {
            (new UpdateDiary)($other, $diary, $data);
            $this->fail('Expected DiaryActionException');
        } catch (DiaryActionException $e) {
            $this->assertSame(DiaryActionFailure::NotAuthor, $e->reason);
        }

        $this->assertDatabaseHas('diaries', ['id' => $diary->getKey(), 'title' => 'Original']);
    }
}
