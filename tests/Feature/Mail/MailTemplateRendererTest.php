<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\Template\MailTemplateRenderer;
use App\Mail\Template\UnsupportedMailTemplateSyntaxException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The OpenPNE 3 dialect subset: substitution, the `{% if %}` subset, app_url_for mapping, term resolution, and diagnostics. */
class MailTemplateRendererTest extends TestCase
{
    use RefreshDatabase;

    private function renderer(): MailTemplateRenderer
    {
        return app(MailTemplateRenderer::class);
    }

    public function test_substitutes_dotted_tokens(): void
    {
        $out = $this->renderer()->render('Hi {{ member.name }} from {{ op_config.sns_name }}', [
            'member.name' => 'Bob',
            'op_config.sns_name' => 'My Community',
        ], 'en');

        $this->assertSame('Hi Bob from My Community', $out);
    }

    public function test_missing_token_renders_empty(): void
    {
        $this->assertSame('A=[]', $this->renderer()->render('A=[{{ absent }}]', [], 'en'));
    }

    public function test_nested_conditionals(): void
    {
        $tpl = '{% if name %}Hi {{ name }}{% if message %} says {{ message }}{% endif %}{% endif %}END';

        $this->assertSame('Hi A says MEND', $this->renderer()->render($tpl, ['name' => 'A', 'message' => 'M'], 'en'));
        $this->assertSame('Hi AEND', $this->renderer()->render($tpl, ['name' => 'A'], 'en'));
        $this->assertSame('END', $this->renderer()->render($tpl, [], 'en'));
    }

    public function test_if_else(): void
    {
        $tpl = '{% if x %}Y{% else %}N{% endif %}';

        $this->assertSame('Y', $this->renderer()->render($tpl, ['x' => '1'], 'en'));
        $this->assertSame('N', $this->renderer()->render($tpl, [], 'en'));
    }

    public function test_app_url_for_maps_to_canonical_openpne4_urls(): void
    {
        $register = $this->renderer()->render(
            "{% app_url_for('pc_frontend', 'member/register?token='~token, true) %}",
            ['token' => 'abc'],
            'en',
        );
        $this->assertSame(url('/register/abc'), $register);

        // OpenPNE 3 carried token+id+type; only the token survives in OpenPNE 4.
        $confirm = $this->renderer()->render(
            "{% app_url_for('pc_frontend', 'member/configComplete?token='~token~'&id='~id~'&type='~type, true) %}",
            ['token' => 'xyz', 'id' => '9', 'type' => 'pc'],
            'en',
        );
        $this->assertSame(url('/member/config/email/confirm/xyz'), $confirm);
    }

    public function test_unmapped_app_url_for_route_is_rejected(): void
    {
        $this->expectException(UnsupportedMailTemplateSyntaxException::class);
        $this->renderer()->render("{% app_url_for('pc_frontend', '@community_home?id='~id, true) %}", ['id' => '1'], 'en');
    }

    public function test_app_url_for_requires_a_token(): void
    {
        $this->expectException(UnsupportedMailTemplateSyntaxException::class);
        $this->renderer()->render("{% app_url_for('pc_frontend', 'member/register?token='~token, true) %}", [], 'en');
    }

    public function test_malformed_variable_tag_is_rejected(): void
    {
        $this->expectException(UnsupportedMailTemplateSyntaxException::class);
        $this->renderer()->render('Hello {{ member.name', ['member.name' => 'Bob'], 'en');
    }

    public function test_context_value_containing_braces_is_not_treated_as_syntax(): void
    {
        // The value has `{{ … }}`/`%}` of its own; validation runs pre-substitution so it stays literal.
        $out = $this->renderer()->render('[{{ name }}]', ['name' => 'a {{ x }} %} b'], 'en');

        $this->assertSame('[a {{ x }} %} b]', $out);
    }

    public function test_op_term_resolves_via_term_service(): void
    {
        $this->assertSame('friend', $this->renderer()->render('{{ op_term.friend }}', [], 'en'));
        $this->assertSame('フレンド', $this->renderer()->render('{{ op_term.friend }}', [], 'ja'));
    }

    public function test_unsupported_for_loop_is_rejected(): void
    {
        $this->expectException(UnsupportedMailTemplateSyntaxException::class);
        $this->renderer()->render('{% for x in items %}{{ x }}{% endfor %}', [], 'en');
    }

    public function test_unsupported_filter_is_rejected(): void
    {
        $this->expectException(UnsupportedMailTemplateSyntaxException::class);
        $this->renderer()->render('{{ birthday|date("m/d") }}', ['birthday' => 'x'], 'en');
    }

    public function test_render_subject_is_collapsed_to_one_line(): void
    {
        $out = $this->renderer()->renderSubject("Hi\r\n{{ name }}", ['name' => "X\nY"], 'en');

        $this->assertSame('Hi X Y', $out);
        $this->assertStringNotContainsString("\n", $out);
    }
}
