<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\Template\MailTemplateRenderer;
use App\Mail\Template\UnsupportedMailTemplateSyntaxException;
use InvalidArgumentException;
use Tests\TestCase;

/** The sandboxed Twig engine: OpenPNE 3 dialect fidelity, the sandbox allowlist, setStrict, and SSTI safety. */
class MailTemplateRendererTest extends TestCase
{
    private function renderer(): MailTemplateRenderer
    {
        return new MailTemplateRenderer;
    }

    public function test_substitutes_nested_and_bracket_access(): void
    {
        $out = $this->renderer()->render('{{ member.name }} / {{ member["name"] }}', ['member' => ['name' => 'Bob']]);

        $this->assertSame('Bob / Bob', $out);
    }

    public function test_op_term_comes_from_context(): void
    {
        $this->assertSame('フレンド', $this->renderer()->render('{{ op_term.friend }}', ['op_term' => ['friend' => 'フレンド']]));
    }

    public function test_operator_if_and_for_and_filters(): void
    {
        $r = $this->renderer();
        $this->assertSame('admin', $r->render('{% if id == "1" %}admin{% else %}user{% endif %}', ['id' => '1']));
        $this->assertSame('[a][b]', $r->render('{% for x in items %}[{{ x }}]{% endfor %}', ['items' => ['a', 'b']]));
        $this->assertSame('fallback', $r->render('{{ missing|default("fallback") }}', []));
    }

    public function test_date_filter_matches_openpne3_semantics(): void
    {
        $r = $this->renderer();
        $this->assertSame('2023-11-14', $r->render('{{ ts|date("Y-m-d") }}', ['ts' => '1700000000']));
        $this->assertSame('2024/05/15', $r->render('{{ d|date("Y/m/d") }}', ['d' => '2024-05-15']));
        // OpenPNE 3 falls back to the epoch (not "now"/an exception) for empty/unparseable input.
        $this->assertSame('1970-01-01', $r->render('{{ e|date("Y-m-d") }}', ['e' => '']));
        $this->assertSame('1970-01-01', $r->render('{{ x|date("Y-m-d") }}', ['x' => 'not-a-date']));
    }

    public function test_constant_function_and_constant_test_are_both_denied(): void
    {
        // Strict mode denies every `is <test>` outside allowedTests (empty), so the `constant` test is
        // rejected just like the `constant()` function. Imported OpenPNE 3 templates use no tests.
        $this->assertRejected('{% if 8 is constant("E_NOTICE") %}yes{% else %}no{% endif %}');
        $this->assertRejected('{{ constant("E_NOTICE") }}');
    }

    public function test_app_url_for_maps_to_canonical_urls_and_drops_id_type(): void
    {
        $r = $this->renderer();
        $this->assertSame(
            url('/register/ABC'),
            $r->render("{% app_url_for('pc_frontend', 'member/register?token='~token, true) %}", ['token' => 'ABC']),
        );
        $this->assertSame(
            url('/member/config/email/confirm/T'),
            $r->render(
                "{% app_url_for('pc_frontend', 'member/configComplete?token='~token~'&id='~id~'&type='~type, true) %}",
                ['token' => 'T', 'id' => '9', 'type' => 'pc_address'],
            ),
        );
        // Named-route (@route?id=N) form: the id names the model, resolved to the canonical URL.
        $this->assertSame(
            route('group.show', ['group' => 3]),
            $r->render("{% app_url_for('pc_frontend', '@community_home?id='~id, true) %}", ['id' => '3']),
        );
        $this->assertSame(
            route('member.profile.show', ['member' => 8]),
            $r->render("{% app_url_for('pc_frontend', '@member_profile?id='~id, true) %}", ['id' => '8']),
        );
    }

    public function test_app_url_for_requires_token_or_id_and_rejects_unmapped_route(): void
    {
        $this->assertRejected("{% app_url_for('pc_frontend', 'member/register', true) %}");
        // A named route with no / a non-numeric id, and a route with no OpenPNE 4 mapping.
        $this->assertRejected("{% app_url_for('pc_frontend', '@community_home', true) %}");
        $this->assertRejected("{% app_url_for('pc_frontend', '@member_profile?id='~id, true) %}", ['id' => 'x']);
        $this->assertRejected("{% app_url_for('pc_frontend', 'community/deleteComment?id='~id, true) %}", ['id' => '1']);
    }

    public function test_app_url_for_tag_on_its_own_line_keeps_the_following_line(): void
    {
        // Twig eats the first newline after a `%}` block tag; the parser re-emits it so an imported
        // OpenPNE 3 body with the tag mid-text (its own line, followed by more) does not merge the URL
        // into the next line. The print form and a tag at end-of-body are unaffected.
        $r = $this->renderer();
        $this->assertSame(
            "Page:\n".url('/groups/3')."\nProfile:",
            $r->render("Page:\n{% app_url_for('pc_frontend', '@community_home?id='~id, true) %}\nProfile:", ['id' => '3']),
        );
    }

    public function test_app_url_for_tag_honors_an_explicit_whitespace_trim(): void
    {
        // A `-%}` trim spanning a blank line eats all trailing whitespace, so the re-emit must not fire —
        // the URL runs straight into the next content.
        $r = $this->renderer();
        $this->assertSame(
            'Page:'.url('/groups/3').'Profile',
            $r->render("Page:{% app_url_for('pc_frontend', '@community_home?id='~id, true) -%}\n\nProfile", ['id' => '3']),
        );
    }

    public function test_string_literal_containing_the_tag_text_is_not_rewritten(): void
    {
        // A real token parser (not a source rewrite): `{% app_url_for %}` inside a string literal is just
        // string content and must survive verbatim.
        $out = $this->renderer()->render('{{ "before {% app_url_for(1,2) %} after" }}', []);

        $this->assertSame('before {% app_url_for(1,2) %} after', $out);
    }

    public function test_range_and_disallowed_filters_tags_are_rejected(): void
    {
        foreach ([
            '{% for i in 1..1000 %}{{ i }}{% endfor %}',   // range / `..`
            '{{ x|upper }}',                                // disallowed filter
            '{{ attribute(m, "name") }}',                   // setStrict: attribute()
            '{% set y = 1 %}{{ y }}',                       // disallowed tag
        ] as $template) {
            $this->assertRejected($template, ['x' => 'a', 'm' => ['name' => 'n']]);
        }
    }

    /** @param array<string, mixed> $context */
    private function assertRejected(string $template, array $context = []): void
    {
        try {
            $this->renderer()->render($template, $context);
            $this->fail("expected rejection for: {$template}");
        } catch (UnsupportedMailTemplateSyntaxException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function test_member_value_is_not_re_rendered_ssti_safe(): void
    {
        $out = $this->renderer()->render('X={{ member.name }}', ['member' => ['name' => '{{ 7*7 }}']]);

        $this->assertSame('X={{ 7*7 }}', $out);
    }

    public function test_object_in_context_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->renderer()->render('{{ x }}', ['x' => new \stdClass]);
    }

    public function test_render_subject_is_collapsed_to_one_line(): void
    {
        $out = $this->renderer()->renderSubject("Hi\r\n{{ member.name }}", ['member' => ['name' => "X\nY"]]);

        $this->assertSame('Hi X Y', $out);
        $this->assertStringNotContainsString("\n", $out);
    }
}
