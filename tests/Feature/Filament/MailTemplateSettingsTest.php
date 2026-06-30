<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\MailTemplateSettings;
use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Models\AdminUser;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The mail-template editor lists the registry templates and edits one at a time in a modal. Each edit
 * saves only that template and persists a row only when a field diverges from the default (absence =
 * default); the configurable toggle gates the mail; the body is byte-bounded and rejected when it uses
 * syntax the engine cannot send.
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

    public function test_lists_every_registry_template(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->assertOk()
            ->assertSee(MailTemplate::RegistrationLink->caption())
            ->assertSee(MailTemplate::FriendAccepted->caption())
            ->assertSee(MailTemplate::Signature->caption());
    }

    public function test_editing_a_body_persists_an_override_and_the_service_renders_it(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->callAction(
                TestAction::make('edit')->table('friend-accepted'),
                data: ['ja__body' => 'カスタム本文 {{ member.name }}'],
            )
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
            ->callAction(
                TestAction::make('edit')->table('friend-accepted'),
                data: ['ja__body' => MailTemplate::FriendAccepted->defaultBody('ja')],
            )
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('mail_templates', ['key' => 'friend-accepted']);
    }

    public function test_toggling_a_configurable_template_off_disables_the_mail(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->callAction(
                TestAction::make('edit')->table('friend-accepted'),
                data: ['enabled' => false],
            )
            ->assertHasNoErrors();

        $this->assertDatabaseHas('mail_templates', ['key' => 'friend-accepted', 'is_enabled' => false]);
        $this->assertFalse(app(MailTemplateService::class)->isEnabled(MailTemplate::FriendAccepted));
    }

    public function test_saving_a_template_at_its_defaults_writes_no_row(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->callAction(TestAction::make('edit')->table('friend-accepted'))
            ->assertHasNoErrors();

        $this->assertSame(0, DB::table('mail_templates')->count());
        $this->assertSame(0, DB::table('mail_template_translations')->count());
    }

    public function test_an_oversized_body_is_rejected_and_not_saved(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->callAction(
                TestAction::make('edit')->table('friend-accepted'),
                data: ['ja__body' => str_repeat('x', 65536)],
            )
            ->assertHasActionErrors(['ja__body']);

        $this->assertDatabaseMissing('mail_templates', ['key' => 'friend-accepted']);
    }

    public function test_a_body_the_engine_cannot_send_is_rejected_and_not_saved(): void
    {
        Livewire::test(MailTemplateSettings::class)
            ->callAction(
                TestAction::make('edit')->table('friend-accepted'),
                data: ['ja__body' => '{% set x = 1 %}{{ x }}'],
            )
            ->assertHasActionErrors(['ja__body']);

        $this->assertDatabaseMissing('mail_templates', ['key' => 'friend-accepted']);
    }
}
