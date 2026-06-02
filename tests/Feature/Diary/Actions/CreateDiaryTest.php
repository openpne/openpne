<?php

namespace Tests\Feature\Diary\Actions;

use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Models\Member;
use App\Support\Visibility;
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

    public function test_accepts_long_title_beyond_varchar_limit(): void
    {
        // OpenPNE 3 diary.title is TEXT with no validator limit; a >255-char title
        // must round-trip (the column is TEXT, not VARCHAR(255)).
        $author = Member::factory()->create();
        $longTitle = str_repeat('あ', 500);
        $data = new DiaryFormData($longTitle, 'Body', Visibility::Members);

        $diary = (new CreateDiary)($author, $data);

        $this->assertSame($longTitle, $diary->fresh()->title);
    }
}
