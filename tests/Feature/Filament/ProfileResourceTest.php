<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\Profiles\Pages\CreateProfile;
use App\Filament\Resources\Profiles\Pages\EditProfile;
use App\Filament\Resources\Profiles\Pages\ListProfiles;
use App\Filament\Resources\Profiles\RelationManagers\ProfileOptionsRelationManager;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Models\ProfileOption;
use App\Support\Visibility;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileResourceTest extends TestCase
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
        $profiles = Profile::factory()->count(2)->create();

        Livewire::test(ListProfiles::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords($profiles);
    }

    public function test_creates_a_custom_field_with_translations_and_visibility(): void
    {
        Livewire::test(CreateProfile::class)
            ->fillForm([
                '_creation_mode' => 'custom',
                'name' => 'fav_color',
                'form_type' => 'input',
                'caption_ja' => '好きな色',
                'caption_en' => 'Favorite color',
                'default_visibility' => Visibility::Friends->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $profile = Profile::query()->where('name', 'fav_color')->first();
        $this->assertNotNull($profile);
        $this->assertSame(Visibility::Friends, $profile->default_visibility);
        $this->assertSame('好きな色', $profile->getCaption('ja_JP'));
        $this->assertSame('Favorite color', $profile->getCaption('en'));
    }

    public function test_registering_a_preset_locks_the_structure_and_creates_no_options(): void
    {
        Livewire::test(CreateProfile::class)
            ->fillForm([
                '_creation_mode' => 'preset',
                '_preset_key' => 'sex',
                'caption_ja' => '性別',
                'caption_en' => 'Sex',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $profile = Profile::query()->where('name', 'op_preset_sex')->first();
        $this->assertNotNull($profile);
        $this->assertSame('select', $profile->form_type);
        // Preset select/radio choices come from the catalog, not profile_options.
        $this->assertSame(0, $profile->options()->count());
    }

    public function test_region_preset_resolves_to_the_shared_name_and_value_type(): void
    {
        Livewire::test(CreateProfile::class)
            ->fillForm([
                '_creation_mode' => 'preset',
                '_preset_key' => 'region_JP',
                'caption_ja' => '都道府県',
                'caption_en' => 'Region in Japan',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $profile = Profile::query()->where('name', 'op_preset_region')->first();
        $this->assertNotNull($profile);
        $this->assertSame('region_select', $profile->form_type);
        $this->assertSame('JP', $profile->value_type);
    }

    public function test_editing_a_preset_disables_structural_fields(): void
    {
        $preset = Profile::factory()->preset('sex')->create(['form_type' => 'select']);
        $preset->setTranslation('ja_JP', '性別');
        $preset->setTranslation('en', 'Sex');

        Livewire::test(EditProfile::class, ['record' => $preset->getKey()])
            ->assertSuccessful()
            ->assertFormFieldIsDisabled('form_type')
            ->assertFormFieldIsDisabled('is_unique');
    }

    public function test_edit_updates_the_caption_translation(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'input']);
        $profile->setTranslation('ja_JP', '旧キャプション');
        $profile->setTranslation('en', 'Old caption');

        Livewire::test(EditProfile::class, ['record' => $profile->getKey()])
            ->fillForm(['caption_ja' => '新キャプション'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('新キャプション', $profile->fresh()->getCaption('ja_JP'));
    }

    public function test_delete_removes_the_field(): void
    {
        $profile = Profile::factory()->create();

        Livewire::test(EditProfile::class, ['record' => $profile->getKey()])
            ->callAction(DeleteAction::class);

        $this->assertModelMissing($profile);
    }

    public function test_options_relation_manager_adds_a_custom_option_with_labels(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'select']); // custom select

        Livewire::test(ProfileOptionsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => EditProfile::class,
        ])
            ->callTableAction('create', data: [
                'caption_ja' => '赤',
                'caption_en' => 'Red',
            ])
            ->assertHasNoTableActionErrors();

        $option = $profile->options()->first();
        $this->assertNotNull($option);
        $this->assertSame('赤', $option->getLabel('ja_JP'));
        $this->assertSame('Red', $option->getLabel('en'));
    }

    public function test_custom_name_rejects_the_preset_prefix_and_invalid_formats(): void
    {
        foreach (['op_preset_hack', '123', '日本語'] as $bad) {
            Livewire::test(CreateProfile::class)
                ->fillForm([
                    '_creation_mode' => 'custom',
                    'name' => $bad,
                    'form_type' => 'input',
                    'caption_ja' => 'x',
                    'caption_en' => 'x',
                ])
                ->call('create')
                ->assertHasFormErrors(['name']);
        }

        Livewire::test(CreateProfile::class)
            ->fillForm([
                '_creation_mode' => 'custom',
                'name' => 'fav_color',
                'form_type' => 'input',
                'caption_ja' => 'x',
                'caption_en' => 'x',
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_clearing_an_option_english_label_removes_the_translation(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'select']);
        $option = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        $option->setLabel('ja_JP', '赤');
        $option->setLabel('en', 'Red');

        Livewire::test(ProfileOptionsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => EditProfile::class,
        ])
            ->callTableAction('edit', $option, data: ['caption_ja' => '赤', 'caption_en' => ''])
            ->assertHasNoTableActionErrors();

        $this->assertSame('赤', $option->fresh()->getLabel('ja_JP'));
        $this->assertSame('', $option->fresh()->getLabel('en'));
    }

    public function test_options_are_not_editable_for_a_preset_choice_field(): void
    {
        $preset = Profile::factory()->preset('sex')->create(['form_type' => 'select']);

        Livewire::test(ProfileOptionsRelationManager::class, [
            'ownerRecord' => $preset,
            'pageClass' => EditProfile::class,
        ])
            ->assertSuccessful()
            ->assertTableActionDoesNotExist('create');
    }

    public function test_stale_options_are_hidden_when_the_field_is_not_an_option_type(): void
    {
        $profile = Profile::factory()->create(['form_type' => 'input']); // left over from a former select
        $option = ProfileOption::factory()->create(['profile_id' => $profile->getKey()]);
        $option->setLabel('ja_JP', '残り');

        Livewire::test(ProfileOptionsRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => EditProfile::class,
        ])
            ->assertSuccessful()
            ->assertCanNotSeeTableRecords([$option])
            ->assertTableActionDoesNotExist('create');
    }
}
