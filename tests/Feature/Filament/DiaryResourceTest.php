<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Diaries\Pages\ListDiaries;
use App\Models\AdminUser;
use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\File;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DiaryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_list_page_renders_diaries(): void
    {
        $diaries = Diary::factory()->count(2)->create();

        Livewire::test(ListDiaries::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($diaries);
    }

    public function test_search_by_title(): void
    {
        $match = Diary::factory()->create(['title' => 'Findme Title']);
        $other = Diary::factory()->create(['title' => 'Unrelated']);

        Livewire::test(ListDiaries::class)
            ->searchTable('Findme')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_admin_delete_removes_diary_and_purges_image_file(): void
    {
        $diary = Diary::factory()->create();
        $image = DiaryImage::factory()->create(['diary_id' => $diary->getKey()]);
        $file = File::find($image->file_id);
        $this->assertNotNull($file);

        Livewire::test(ListDiaries::class)
            ->callAction(TestAction::make('delete')->table($diary));

        // The author-less purge core removed the diary and its owned image File bytes.
        $this->assertModelMissing($diary);
        $this->assertModelMissing($file);
    }
}
