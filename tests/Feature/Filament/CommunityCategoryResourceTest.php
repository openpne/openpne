<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\CommunityCategories\Pages\CreateCommunityCategory;
use App\Filament\Resources\CommunityCategories\Pages\EditCommunityCategory;
use App\Filament\Resources\CommunityCategories\Pages\ListCommunityCategories;
use App\Models\AdminUser;
use App\Models\CommunityCategory;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CommunityCategoryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_list_page_renders_records(): void
    {
        $categories = CommunityCategory::factory()->count(2)->create();

        Livewire::test(ListCommunityCategories::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($categories);
    }

    public function test_creates_a_category(): void
    {
        Livewire::test(CreateCommunityCategory::class)
            ->fillForm([
                'name' => 'Sports',
                'is_allow_member_community' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = CommunityCategory::query()->where('name', 'Sports')->first();
        $this->assertNotNull($category);
        $this->assertFalse($category->is_allow_member_community);
    }

    public function test_delete_removes_the_category(): void
    {
        $category = CommunityCategory::factory()->create();

        Livewire::test(EditCommunityCategory::class, ['record' => $category->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($category);
    }
}
