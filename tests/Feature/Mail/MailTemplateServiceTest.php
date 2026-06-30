<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Resolution tiering (override → default), per-locale isolation, is_enabled policy, and cache clearing. */
class MailTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): MailTemplateService
    {
        return app(MailTemplateService::class);
    }

    private function setSnsName(string $name): void
    {
        DB::table('sns_settings')->insert([
            ['key' => 'sns_name', 'value' => $name],
            ['key' => 'admin_mail_address', 'value' => 'ops@example.test'],
        ]);
    }

    public function test_default_render_injects_sns_name_and_appends_signature(): void
    {
        $this->setSnsName('My Community');

        $rendered = $this->service()->render(MailTemplate::FriendAccepted, 'en', ['member.name' => 'Bob']);

        $this->assertSame('Bob accepted your friend link request', $rendered->subject);
        $this->assertStringContainsString('My Community', $rendered->body);
        $this->assertStringContainsString('Bob accepted your friend link request.', $rendered->body);
        // The appended signature carries the contact line.
        $this->assertStringContainsString('ops@example.test', $rendered->body);
    }

    public function test_registration_link_default_preserves_op3_wording_and_conditionals(): void
    {
        $this->setSnsName('My Community');

        $invite = $this->service()->render(MailTemplate::RegistrationLink, 'ja', [
            'name' => 'アリス', 'message' => 'よろしく', 'token' => 'TOK',
        ]);
        // The OpenPNE 3 list marker survives byte-exact, and the optional inviter/message blocks render.
        $this->assertStringContainsString('■', $invite->body);
        $this->assertStringContainsString('アリス', $invite->body);
        $this->assertStringContainsString('よろしく', $invite->body);
        $this->assertStringContainsString(url('/register/TOK'), $invite->body);

        // Self-registration (no inviter): the conditional inviter line is omitted.
        $self = $this->service()->render(MailTemplate::RegistrationLink, 'ja', ['token' => 'TOK']);
        $this->assertStringNotContainsString('があなたを', $self->body);
        $this->assertStringContainsString('■', $self->body);
    }

    public function test_db_override_replaces_the_default_body(): void
    {
        $this->setSnsName('My Community');
        $id = DB::table('mail_templates')->insertGetId([
            'key' => MailTemplate::FriendAccepted->value,
            'is_enabled' => true,
        ]);
        DB::table('mail_template_translations')->insert([
            'mail_template_id' => $id,
            'locale' => 'en',
            'subject' => 'Custom subject',
            'body' => 'Custom {{ member.name }}',
        ]);

        $rendered = $this->service()->render(MailTemplate::FriendAccepted, 'en', ['member.name' => 'Bob']);

        $this->assertSame('Custom subject', $rendered->subject);
        $this->assertStringContainsString('Custom Bob', $rendered->body);
    }

    public function test_override_is_per_locale_with_no_cross_language_fallback(): void
    {
        $this->setSnsName('My Community');
        $id = DB::table('mail_templates')->insertGetId([
            'key' => MailTemplate::FriendAccepted->value,
            'is_enabled' => true,
        ]);
        DB::table('mail_template_translations')->insert([
            'mail_template_id' => $id, 'locale' => 'en', 'subject' => 'EN', 'body' => 'English only',
        ]);

        // A ja recipient gets the ja default, never the en override.
        $ja = $this->service()->render(MailTemplate::FriendAccepted, 'ja', ['member.name' => 'Bob']);
        $this->assertStringNotContainsString('English only', $ja->body);
        $this->assertStringContainsString('承諾', $ja->body);

        $en = $this->service()->render(MailTemplate::FriendAccepted, 'en', ['member.name' => 'Bob']);
        $this->assertStringContainsString('English only', $en->body);
    }

    public function test_blank_signature_override_appends_no_signature(): void
    {
        $this->setSnsName('My Community');
        $id = DB::table('mail_templates')->insertGetId([
            'key' => MailTemplate::Signature->value,
            'is_enabled' => true,
        ]);
        // An admin who blanks the signature wants no signature, not the default restored.
        DB::table('mail_template_translations')->insert([
            'mail_template_id' => $id, 'locale' => 'en', 'subject' => null, 'body' => '',
        ]);

        $rendered = $this->service()->render(MailTemplate::FriendAccepted, 'en', ['member.name' => 'Bob']);

        $this->assertStringEndsWith('Bob accepted your friend link request.', $rendered->body);
        $this->assertStringNotContainsString('ops@example.test', $rendered->body);
    }

    public function test_required_mail_is_always_enabled_even_if_row_disabled(): void
    {
        DB::table('mail_templates')->insert([
            'key' => MailTemplate::RegistrationLink->value,
            'is_enabled' => false,
        ]);

        $this->assertTrue($this->service()->isEnabled(MailTemplate::RegistrationLink));
    }

    public function test_configurable_mail_honors_stored_flag(): void
    {
        DB::table('mail_templates')->insert([
            'key' => MailTemplate::FriendRequested->value,
            'is_enabled' => false,
        ]);

        $this->assertFalse($this->service()->isEnabled(MailTemplate::FriendRequested));
        // An absent row defaults to enabled.
        $this->assertTrue($this->service()->isEnabled(MailTemplate::MessageReceived));
    }

    public function test_clear_cache_picks_up_a_new_override(): void
    {
        $this->setSnsName('My Community');
        $service = $this->service();
        $first = $service->render(MailTemplate::FriendAccepted, 'en', ['member.name' => 'Bob']);
        $this->assertStringContainsString('accepted your friend link request', $first->body);

        $id = DB::table('mail_templates')->insertGetId([
            'key' => MailTemplate::FriendAccepted->value,
            'is_enabled' => true,
        ]);
        DB::table('mail_template_translations')->insert([
            'mail_template_id' => $id, 'locale' => 'en', 'subject' => 'S', 'body' => 'After clear',
        ]);
        $service->clearCache();

        $second = $service->render(MailTemplate::FriendAccepted, 'en', ['member.name' => 'Bob']);
        $this->assertStringContainsString('After clear', $second->body);
    }
}
