<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateDefaults;
use PHPUnit\Framework\TestCase;

/** Registry self-consistency: every template is fully specified and the OpenPNE 3 mapping is well-formed. */
class MailTemplateTest extends TestCase
{
    public function test_every_template_has_defaults_for_both_locales(): void
    {
        $defaults = MailTemplateDefaults::all();

        foreach (MailTemplate::cases() as $template) {
            $this->assertArrayHasKey($template->value, $defaults, "missing defaults for {$template->value}");

            foreach (['ja', 'en'] as $locale) {
                $this->assertNotSame('', $template->defaultBody($locale), "empty body for {$template->value}/{$locale}");

                if ($template->isSendable()) {
                    $this->assertNotNull($template->defaultSubject($locale), "sendable {$template->value}/{$locale} needs a subject");
                    $this->assertNotSame('', $template->defaultSubject($locale));
                } else {
                    $this->assertNull($template->defaultSubject($locale), 'the signature has no subject');
                }
            }
        }
    }

    public function test_op3_source_names_are_unique_and_round_trip(): void
    {
        $names = [];
        foreach (MailTemplate::cases() as $template) {
            $name = $template->op3SourceName();
            if ($name === null) {
                continue;
            }
            $names[] = $name;
            $this->assertSame($template, MailTemplate::fromOp3SourceName($name));
        }

        $this->assertSame($names, array_unique($names), 'OpenPNE 3 source names must be unique');
        $this->assertNull(MailTemplate::fromOp3SourceName('pc_unknown'));
    }

    public function test_only_notification_type_mails_are_configurable(): void
    {
        $configurable = array_values(array_filter(
            MailTemplate::cases(),
            static fn (MailTemplate $t): bool => $t->isConfigurable(),
        ));

        $this->assertSame(
            [MailTemplate::FriendRequested, MailTemplate::FriendAccepted, MailTemplate::MessageReceived],
            $configurable,
        );
    }

    public function test_signature_is_the_only_non_sendable_template(): void
    {
        $this->assertFalse(MailTemplate::Signature->isSendable());
        $this->assertNotContains(MailTemplate::Signature, MailTemplate::sendable());
        $this->assertCount(count(MailTemplate::cases()) - 1, MailTemplate::sendable());
    }
}
