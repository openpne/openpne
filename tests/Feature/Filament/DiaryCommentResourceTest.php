<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\DiaryComments\Pages\ListDiaryComments;
use App\Models\AdminUser;
use App\Models\DiaryComment;
use App\Models\DiaryCommentImage;
use App\Models\File;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DiaryCommentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_list_page_renders_comments(): void
    {
        $comments = DiaryComment::factory()->count(2)->create();

        Livewire::test(ListDiaryComments::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($comments);
    }

    public function test_search_by_body(): void
    {
        $match = DiaryComment::factory()->create(['body' => 'Findme body text']);
        $other = DiaryComment::factory()->create(['body' => 'Unrelated chatter']);

        Livewire::test(ListDiaryComments::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_admin_delete_removes_comment_and_purges_image_file(): void
    {
        $comment = DiaryComment::factory()->create();
        $image = DiaryCommentImage::factory()->create(['diary_comment_id' => $comment->getKey()]);
        $file = File::find($image->file_id);
        $this->assertNotNull($file);

        Livewire::test(ListDiaryComments::class)
            ->callAction(TestAction::make('delete')->table($comment))
            // using() must return truthy, or DeleteAction reports failure after a real delete.
            ->assertNotified(__('filament-actions::delete.single.notifications.deleted.title'));

        // The author-less purge core removed the comment and its owned image File bytes.
        $this->assertModelMissing($comment);
        $this->assertModelMissing($file);
    }
}
