<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\Template\MailTemplateFault;
use App\Mail\Template\MailTemplateRenderer;
use App\Mail\Template\UnsupportedMailTemplateSyntaxException;
use PHPUnit\Framework\TestCase;

/**
 * The strictVariables flag is what makes the drift guards (MailTemplateDriftGuardTest) non-vacuous:
 * production renders leniently (an absent variable is empty, matching OpenPNE 3), the guards render
 * strictly so an undeclared variable throws. Pin both directions so a regression to the flag can't
 * silently turn the guards into no-ops.
 *
 * Also pins the fault classification the import preflight groups by, including the boundary that keeps
 * it honest: an honest runtime fault must NOT be reported as a missing variable.
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

    public function test_an_unparsable_template_is_a_parse_error(): void
    {
        $this->assertSame(MailTemplateFault::ParseError, $this->faultOf('{% if x %}never closed'));
    }

    public function test_a_tag_outside_the_sandbox_allowlist_is_a_sandbox_violation(): void
    {
        $this->assertSame(MailTemplateFault::SandboxViolation, $this->faultOf('{% set x = 1 %}'));
    }

    public function test_a_filter_outside_the_sandbox_allowlist_is_a_sandbox_violation(): void
    {
        $this->assertSame(MailTemplateFault::SandboxViolation, $this->faultOf('{{ "x"|upper }}'));
    }

    public function test_an_unmapped_app_url_for_route_is_a_route_map_failure(): void
    {
        // Twig wraps what the function threw in a RuntimeError; the inner fault is the real one.
        $this->assertSame(
            MailTemplateFault::RouteMapFailure,
            $this->faultOf("{% app_url_for('pc_frontend', 'member/thereIsNoSuchAction') %}"),
        );
    }

    public function test_a_runtime_fault_that_is_not_a_missing_variable_stays_generic(): void
    {
        // The boundary MailTemplateFault exists for: this is a RuntimeError just like a strict-variable
        // miss, so classifying by type alone would mislabel it as missing context.
        $this->assertSame(MailTemplateFault::RenderFailure, $this->faultOf('{{ 1 / 0 }}'));
    }

    private function faultOf(string $template): MailTemplateFault
    {
        try {
            (new MailTemplateRenderer)->render($template, []);
        } catch (UnsupportedMailTemplateSyntaxException $e) {
            return $e->fault;
        }

        $this->fail("Expected {$template} to fail rendering.");
    }
}
