<?php

namespace Tests\Feature\Compose;

use App\Models\Member;
use App\Support\ComposeEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->post('/compose/editor', ['compose_editor' => 'rich'])->assertRedirect('/login');
    }

    public function test_it_persists_each_valid_choice_and_answers_204(): void
    {
        foreach (ComposeEditor::cases() as $editor) {
            $member = Member::factory()->create();

            $this->actingAs($member)
                ->postJson('/compose/editor', ['compose_editor' => $editor->value])
                ->assertNoContent();

            $this->assertDatabaseHas('member_preferences', [
                'member_id' => $member->id, 'key' => 'compose_editor', 'value' => $editor->value,
            ]);
        }
    }

    public function test_it_rejects_an_unknown_choice(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/compose/editor', ['compose_editor' => 'wysiwyg'])
            ->assertStatus(422)->assertJsonValidationErrors('compose_editor');
    }

    public function test_a_second_post_overwrites_the_single_row(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/compose/editor', ['compose_editor' => 'rich'])->assertNoContent();
        $this->actingAs($member)->postJson('/compose/editor', ['compose_editor' => 'markdown'])->assertNoContent();

        $this->assertSame(1, $member->preferences()->where('key', 'compose_editor')->count());
        $this->assertDatabaseHas('member_preferences', [
            'member_id' => $member->id, 'key' => 'compose_editor', 'value' => 'markdown',
        ]);
    }
}
