<?php

namespace Tests\Feature\Diary;

use App\Models\Diary;
use App\Models\Member;
use App\Support\BodyFormat;
use App\Support\Visibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiaryFormatAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persists_the_markdown_format(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/diary/create', [
            'title' => 'MD',
            'body' => '**bold**',
            'visibility' => '1',
            'format' => 'markdown',
        ]);

        $this->assertDatabaseHas('diaries', ['title' => 'MD', 'format' => BodyFormat::Markdown->value]);
    }

    public function test_classic_new_form_shows_the_markdown_toggle(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->get('/diary/new')
            ->assertOk()
            // The hidden field posts plain, so unchecking really switches a record back to plain.
            ->assertSee('<input type="hidden" name="format" value="plain">', false)
            ->assertSee('name="format" value="markdown"', false)
            ->assertDontSee('value="markdown" checked', false); // unchecked on a fresh form
    }

    public function test_classic_edit_of_a_markdown_diary_checks_the_box_with_the_hidden_plain_field(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'format' => BodyFormat::Markdown]);

        $this->actingAs($owner)->get("/diary/edit/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('<input type="hidden" name="format" value="plain">', false)
            ->assertSee('name="format" value="markdown" checked', false);
    }

    public function test_classic_edit_of_an_op3_diary_shows_the_note_and_no_format_input(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create(['member_id' => $owner->getKey(), 'format' => BodyFormat::Op3]);

        $this->actingAs($owner)->get("/diary/edit/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('OpenPNE 3') // the muted note (locale-independent fragment)
            ->assertDontSee('name="format"', false); // no hidden field, no checkbox — absent preserves op3
    }

    public function test_unchecking_markdown_on_edit_switches_the_diary_back_to_plain(): void
    {
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'format' => BodyFormat::Markdown,
            'visibility' => Visibility::Members,
        ]);

        // Unchecked checkbox posts nothing; only the hidden field's plain reaches the server.
        $this->actingAs($owner)->post("/diary/update/{$diary->getKey()}", [
            'title' => $diary->title,
            'body' => $diary->body,
            'visibility' => '1',
            'format' => 'plain',
        ]);

        $this->assertSame(BodyFormat::Plain, $diary->fresh()->format);
    }

    public function test_create_without_a_format_defaults_to_plain(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/diary/create', [
            'title' => 'Plain',
            'body' => 'text',
            'visibility' => '1',
        ]);

        $this->assertDatabaseHas('diaries', ['title' => 'Plain', 'format' => BodyFormat::Plain->value]);
    }

    public function test_op3_format_in_input_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/diary/create', [
            'title' => 'Nope',
            'body' => '<op:b>x</op:b>',
            'visibility' => '1',
            'format' => 'op3',
        ])->assertStatus(422)->assertJsonValidationErrors('format');

        $this->assertDatabaseMissing('diaries', ['title' => 'Nope']);
    }

    public function test_body_at_the_text_column_byte_cap_is_accepted(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->post('/diary/create', [
            'title' => 'Max',
            'body' => str_repeat('a', 65535),
            'visibility' => '1',
        ]);

        $this->assertDatabaseHas('diaries', ['title' => 'Max']);
    }

    public function test_body_over_the_text_column_byte_cap_is_rejected(): void
    {
        $member = Member::factory()->create();

        $this->actingAs($member)->postJson('/diary/create', [
            'title' => 'Over',
            'body' => str_repeat('a', 65536),
            'visibility' => '1',
        ])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_the_body_cap_counts_bytes_not_characters(): void
    {
        $member = Member::factory()->create();

        // 21,846 three-byte characters = 65,538 bytes but only ~22k characters: a character-count
        // rule would accept what the TEXT column rejects.
        $this->actingAs($member)->postJson('/diary/create', [
            'title' => 'Multibyte',
            'body' => str_repeat('あ', 21846),
            'visibility' => '1',
        ])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_classic_show_renders_a_markdown_body(): void
    {
        config(['openpne.surface_mode' => 'classic_default']);
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'format' => BodyFormat::Markdown,
            'body' => '**bold**',
        ]);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertOk()
            ->assertSee('<strong>bold</strong>', false);
    }

    public function test_modern_show_carries_rendered_markdown_body_html(): void
    {
        config(['openpne.surface_mode' => 'modern_default']);
        $owner = Member::factory()->create();
        $diary = Diary::factory()->create([
            'member_id' => $owner->getKey(),
            'format' => BodyFormat::Markdown,
            'body' => '**bold**',
        ]);

        $this->actingAs($owner)->get("/diary/{$diary->getKey()}")
            ->assertInertia(fn ($page) => $page
                ->where('diary.format', 'markdown')
                ->where('diary.bodyHtml', fn ($html) => is_string($html) && str_contains($html, '<strong>bold</strong>'))
            );
    }
}
