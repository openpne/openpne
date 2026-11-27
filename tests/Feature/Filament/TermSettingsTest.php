<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\TermSettings;
use App\Http\Middleware\SetLocale;
use App\Services\TermService;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class TermSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAsAdmin();
    }

    public function test_changing_a_field_from_default_inserts_a_row(): void
    {
        Livewire::test(TermSettings::class)
            ->fillForm(['ja__friend' => 'ともだち'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('term_overrides', [
            'name' => 'friend',
            'locale' => 'ja',
            'value' => 'ともだち',
        ]);
    }

    public function test_resetting_a_field_to_its_default_value_deletes_the_row(): void
    {
        DB::table('term_overrides')->insert([
            'name' => 'friend',
            'locale' => 'ja',
            'value' => 'ともだち',
        ]);

        Livewire::test(TermSettings::class)
            ->fillForm(['ja__friend' => 'フレンド'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('term_overrides', [
            'name' => 'friend',
            'locale' => 'ja',
        ]);
    }

    public function test_clearing_a_field_deletes_the_row(): void
    {
        DB::table('term_overrides')->insert([
            'name' => 'friend',
            'locale' => 'ja',
            'value' => 'ともだち',
        ]);

        Livewire::test(TermSettings::class)
            ->fillForm(['ja__friend' => ''])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('term_overrides', [
            'name' => 'friend',
            'locale' => 'ja',
        ]);
    }

    public function test_whitespace_only_input_deletes_the_row(): void
    {
        DB::table('term_overrides')->insert([
            'name' => 'friend',
            'locale' => 'ja',
            'value' => 'ともだち',
        ]);

        Livewire::test(TermSettings::class)
            ->fillForm(['ja__friend' => '   '])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('term_overrides', [
            'name' => 'friend',
            'locale' => 'ja',
        ]);
    }

    public function test_save_with_only_default_values_leaves_the_table_empty(): void
    {
        // Submitting the form unmodified (every field still showing the
        // default value) must not seed the table with redundant rows.
        Livewire::test(TermSettings::class)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('term_overrides')->count());
    }

    public function test_form_reflects_persisted_override_after_save(): void
    {
        Livewire::test(TermSettings::class)
            ->fillForm(['ja__friend' => 'ともだち'])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.ja__friend', 'ともだち');
    }

    public function test_a_label_names_the_term_by_its_default_in_the_panel_language_and_its_key(): void
    {
        DB::table('term_overrides')->insert([
            ['name' => 'community', 'locale' => 'ja', 'value' => 'サークル'],
            ['name' => 'community', 'locale' => 'en', 'value' => 'club'],
        ]);

        foreach (['ja' => 'グループ (community)', 'en' => 'Group (community)'] as $panelLocale => $expected) {
            app()->setLocale($panelLocale);

            $component = Livewire::test(TermSettings::class);
            foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
                $component->assertFormFieldExists(
                    "{$locale}__community",
                    fn (TextInput $field): bool => $field->getLabel() === $expected,
                );
            }
        }
    }

    public function test_every_label_pairs_the_ja_default_with_its_key_under_a_ja_panel(): void
    {
        app()->setLocale('ja');
        $captions = TermService::defaults('ja');

        $component = Livewire::test(TermSettings::class);
        foreach (SetLocale::SUPPORTED_LOCALES as $locale) {
            foreach (array_keys(TermService::defaults($locale)) as $name) {
                $component->assertFormFieldExists(
                    "{$locale}__{$name}",
                    fn (TextInput $field): bool => $field->getLabel() === "{$captions[$name]} ({$name})",
                );
            }
        }
    }

    public function test_unrelated_overrides_are_not_touched_when_saving_others(): void
    {
        DB::table('term_overrides')->insert([
            'name' => 'community',
            'locale' => 'en',
            'value' => 'club',
        ]);

        Livewire::test(TermSettings::class)
            ->fillForm(['ja__friend' => 'ともだち'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('term_overrides', [
            'name' => 'community',
            'locale' => 'en',
            'value' => 'club',
        ]);
    }
}
