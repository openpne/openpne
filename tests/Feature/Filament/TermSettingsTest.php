<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\TermSettings;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Exercises the three save transitions of the term-override editor:
 *   - changing a field from its default writes a row;
 *   - changing it back to the default deletes the row;
 *   - submitting a blank field deletes the row.
 *
 * These guard the "do not persist values that match the default" invariant
 * (a regression would leave the table full of redundant rows and obscure the
 * actual administrator customisations).
 */
class TermSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
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

    public function test_unrelated_overrides_are_not_touched_when_saving_others(): void
    {
        DB::table('term_overrides')->insert([
            'name' => 'community',
            'locale' => 'en',
            'value' => 'group',
        ]);

        Livewire::test(TermSettings::class)
            ->fillForm(['ja__friend' => 'ともだち'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('term_overrides', [
            'name' => 'community',
            'locale' => 'en',
            'value' => 'group',
        ]);
    }
}
