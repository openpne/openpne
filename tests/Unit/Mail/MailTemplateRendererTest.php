<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\Template\MailTemplateRenderer;
use App\Mail\Template\UnsupportedMailTemplateSyntaxException;
use PHPUnit\Framework\TestCase;

/**
 * The strictVariables flag is what makes the drift guards (MailTemplateDriftGuardTest) non-vacuous:
 * production renders leniently (an absent variable is empty, matching OpenPNE 3), the guards render
 * strictly so an undeclared variable throws. Pin both directions so a regression to the flag can't
 * silently turn the guards into no-ops.
 */
class MailTemplateRendererTest extends TestCase
{
    public function test_lenient_renderer_renders_an_absent_variable_as_empty(): void
    {
        $rendered = (new MailTemplateRenderer)->render('[{{ absent }}]', []);

        $this->assertSame('[]', $rendered);
    }

    public function test_strict_renderer_rejects_an_absent_variable(): void
    {
        $this->expectException(UnsupportedMailTemplateSyntaxException::class);

        (new MailTemplateRenderer(strictVariables: true))->render('{{ absent }}', []);
    }
}
