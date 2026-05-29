<?php

namespace Tests\Feature\Diary\Actions;

use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Features\Diary\Visibility;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateDiaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_diary_owned_by_author(): void
    {
        $author = Member::factory()->create();
        $data = new DiaryFormData('My title', 'My body', Visibility::Members);

        $diary = (new CreateDiary)($author, $data);

        $this->assertDatabaseHas('diaries', [
            'id' => $diary->getKey(),
            'member_id' => $author->getKey(),
            'title' => 'My title',
            'body' => 'My body',
            'visibility' => Visibility::Members->value,
        ]);
    }

    public function test_returned_diary_has_correct_visibility_cast(): void
    {
        $author = Member::factory()->create();
        $data = new DiaryFormData('Title', 'Body', Visibility::Friends);

        $diary = (new CreateDiary)($author, $data);

        $this->assertSame(Visibility::Friends, $diary->visibility);
    }
}
