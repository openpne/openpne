<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\GroupCategories\Pages\CreateGroupCategory;
use App\Filament\Resources\GroupCategories\Pages\EditGroupCategory;
use App\Filament\Resources\GroupCategories\Pages\ListGroupCategories;
use App\Models\AdminUser;
use App\Models\GroupCategory;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GroupCategoryResourceTest extends TestCase
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
        $categories = GroupCategory::factory()->count(2)->create();

        Livewire::test(ListGroupCategories::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($categories);
    }

    public function test_creates_a_category(): void
    {
        Livewire::test(CreateGroupCategory::class)
            ->fillForm([
                'name' => 'Sports',
                'is_allow_member_group' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = GroupCategory::query()->where('name', 'Sports')->first();
        $this->assertNotNull($category);
        $this->assertFalse($category->is_allow_member_group);
    }

    public function test_delete_removes_the_category(): void
    {
        $category = GroupCategory::factory()->create();

        Livewire::test(EditGroupCategory::class, ['record' => $category->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($category);
    }
}
