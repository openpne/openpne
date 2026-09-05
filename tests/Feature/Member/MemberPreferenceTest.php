<?php

namespace Tests\Feature\Member;

use App\Models\Member;
use App\Support\ComposeEditor;
use App\Support\PreferenceKey;
use App\Support\Surface;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unset_preference_reads_the_key_default(): void
    {
        $member = Member::factory()->create();

        $this->assertSame(Visibility::Members, $member->preference(PreferenceKey::DiaryDefaultVisibility));
        $this->assertSame(Visibility::Private, $member->preference(PreferenceKey::AgeVisibility));
    }

    public function test_set_persists_and_reads_back_without_a_reload(): void
    {
        $member = Member::factory()->create();

        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);

        // No fresh(): setPreference invalidates the cached relation, so the next read reloads.
        $this->assertSame(Visibility::Friends, $member->preference(PreferenceKey::DiaryDefaultVisibility));
        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility', 'value' => '2',
        ]);
    }

    public function test_setting_again_overwrites_the_single_row(): void
    {
        $member = Member::factory()->create();

        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Private);

        $this->assertSame(1, $member->preferences()->where('key', 'diary_default_visibility')->count());
        $this->assertSame(Visibility::Private, $member->preference(PreferenceKey::DiaryDefaultVisibility));
    }

    public function test_setting_the_default_value_stores_an_explicit_row(): void
    {
        $member = Member::factory()->create();

        // OpenPNE 3 recorded an explicit choice equal to the default, so it is stored rather than
        // normalised away.
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Members);

        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility', 'value' => '1',
        ]);
    }

    public function test_reset_drops_the_row_so_reads_follow_the_default(): void
    {
        $member = Member::factory()->create();
        $member->setPreference(PreferenceKey::DiaryDefaultVisibility, Visibility::Friends);

        $member->resetPreference(PreferenceKey::DiaryDefaultVisibility);

        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'diary_default_visibility',
        ]);
        $this->assertSame(Visibility::Members, $member->preference(PreferenceKey::DiaryDefaultVisibility));
    }

    public function test_preferred_surface_is_null_until_set_then_persists(): void
    {
        $member = Member::factory()->create();

        $this->assertNull($member->preferredSurface());

        $member->setPreferredSurface(Surface::Modern);

        $this->assertSame(Surface::Modern, $member->preferredSurface());
        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface', 'value' => 'modern',
        ]);
    }

    public function test_reset_preferred_surface_drops_the_row_so_it_follows_the_default(): void
    {
        $member = Member::factory()->create();
        $member->setPreferredSurface(Surface::Classic);

        $member->resetPreferredSurface();

        $this->assertNull($member->preferredSurface());
        $this->assertDatabaseMissing('member_preferences', [
            'member_id' => $member->id, 'key' => 'preferred_surface',
        ]);
    }

    public function test_compose_editor_reads_the_default_until_set_then_persists(): void
    {
        $member = Member::factory()->create();

        // Concrete default (Rich), unlike the tri-state surface key.
        $this->assertSame(ComposeEditor::Rich, $member->composeEditor());

        $member->setComposeEditor(ComposeEditor::Markdown);

        // No fresh(): setComposeEditor invalidates the cached relation, so the next read reloads.
        $this->assertSame(ComposeEditor::Markdown, $member->composeEditor());
        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'compose_editor', 'value' => 'markdown',
        ]);
    }

    public function test_setting_the_compose_editor_again_overwrites_the_single_row(): void
    {
        $member = Member::factory()->create();

        $member->setComposeEditor(ComposeEditor::Markdown);
        $member->setComposeEditor(ComposeEditor::Rich);

        $this->assertSame(1, $member->preferences()->where('key', 'compose_editor')->count());
        $this->assertSame(ComposeEditor::Rich, $member->composeEditor());
    }
}
