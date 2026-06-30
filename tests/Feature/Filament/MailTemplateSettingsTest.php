<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\MailTemplateSettings;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The mail-template editor follows the same "persist only what diverges from the default" contract as the
 * other settings pages: editing a body writes an override row (reflected by the service), resetting it to
 * the default removes the row, the configurable toggle gates the mail, and the body is byte-bounded.
 */
class MailTemplateSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(AdminUser::factory()->create(), 'admin');
    }

    public function test_editing_a_body_persists_an_override_and_the_service_renders_it(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->fillForm(['friend_accepted__ja__body' => 'カスタム本文 {{ member.name }}'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('mail_templates', ['key' => 'friend-accepted']);
        $this->assertDatabaseHas('mail_template_translations', [
            'locale' => 'ja',
            'body' => 'カスタム本文 {{ member.name }}',
        ]);

        $rendered = app(MailTemplateService::class)->render(
            MailTemplate::FriendAccepted, 'ja', ['member' => ['name' => 'Bob']],
        );
        $this->assertStringContainsString('カスタム本文 Bob', $rendered->body);
    }

    public function test_resetting_a_body_to_the_default_removes_the_override(): void
    {
        $id = DB::table('mail_templates')->insertGetId(['key' => 'friend-accepted', 'is_enabled' => true]);
        DB::table('mail_template_translations')->insert([
            'mail_template_id' => $id, 'locale' => 'ja', 'subject' => null, 'body' => 'old custom',
        ]);

        Livewire::test(MailTemplateSettings::class)
            ->fillForm(['friend_accepted__ja__body' => MailTemplate::FriendAccepted->defaultBody('ja')])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('mail_template_translations', ['mail_template_id' => $id]);
        $this->assertDatabaseMissing('mail_templates', ['key' => 'friend-accepted']);
    }

    public function test_toggling_a_configurable_template_off_disables_the_mail(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->fillForm(['friend_accepted__enabled' => false])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('mail_templates', ['key' => 'friend-accepted', 'is_enabled' => false]);
        $this->assertFalse(app(MailTemplateService::class)->isEnabled(MailTemplate::FriendAccepted));
    }

    public function test_re_enabling_with_no_overrides_removes_the_row(): void
    {
        DB::table('mail_templates')->insert(['key' => 'friend-accepted', 'is_enabled' => false]);

        Livewire::test(MailTemplateSettings::class)
            ->fillForm(['friend_accepted__enabled' => true])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('mail_templates', ['key' => 'friend-accepted']);
    }

    public function test_saving_only_defaults_leaves_the_tables_empty(): void
    {
        // Submitting unmodified (every field still at its built-in default) must not seed redundant rows.
        Livewire::test(MailTemplateSettings::class)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('mail_templates')->count());
        $this->assertSame(0, DB::table('mail_template_translations')->count());
    }

    public function test_an_oversized_body_is_rejected(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->fillForm(['friend_accepted__ja__body' => str_repeat('x', 65536)])
            ->call('save')
            ->assertHasErrors('data.friend_accepted__ja__body');
    }

    public function test_a_body_the_engine_cannot_send_is_rejected_and_not_stored(): void
    {
        // A sandbox-disallowed tag would throw at send time and break the mail; the editor must refuse it.
        Livewire::test(MailTemplateSettings::class)
            ->fillForm(['friend_accepted__ja__body' => '{% set x = 1 %}{{ x }}'])
            ->call('save')
            ->assertHasErrors('data.friend_accepted__ja__body');

        $this->assertDatabaseMissing('mail_templates', ['key' => 'friend-accepted']);
    }

    public function test_a_subject_the_engine_cannot_send_is_rejected(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->fillForm(['friend_accepted__ja__subject' => '{{ 1 | nonexistent_filter }}'])
            ->call('save')
            ->assertHasErrors('data.friend_accepted__ja__subject');

        $this->assertDatabaseMissing('mail_templates', ['key' => 'friend-accepted']);
    }
}
