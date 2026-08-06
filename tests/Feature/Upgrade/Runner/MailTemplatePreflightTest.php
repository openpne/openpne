<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Mail\Template\MailTemplateFault;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\MailTemplatePreflight;
use App\Upgrade\Runner\RunOptions;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MailTemplateTranslationUpgrade;
use App\Upgrade\Steps\MailTemplateUpgrade;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The mail-template preflight: the translation step copies a body without parsing it, so a template that
 * cannot render on OpenPNE 4 would only surface at the first send after the cutover. These pin what the
 * dry run reports instead — per fault, and with the source states that would make the step itself fail
 * aborting before any write.
 */
class MailTemplatePreflightTest extends TestCase
{
    use DatabaseMigrations;

    private const SOURCE_TABLES = ['notification_mail_translation', 'notification_mail'];

    /** A migrated template whose declared variables the fixtures below reference. */
    private const SOURCE_NAME = 'pc_notifyNewMessage';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The preflight reads a qualified source table and the runner executes on MySQL.');
        }

        $this->dropSourceTables();
        $this->createSourceTables();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->dropSourceTables();
        }

        parent::tearDown();
    }

    public function test_a_template_outside_the_supported_dialect_is_reported_as_a_sandbox_violation(): void
    {
        $this->insertTemplate(body: '{{ "shout"|upper }}');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok, 'an unrenderable template is a warning, not an abort');
        $this->assertStringContainsString('sandbox violation', $output);
        $this->assertStringContainsString('cannot be sent until the template is fixed', $output);
    }

    public function test_an_unmapped_app_url_for_route_is_reported(): void
    {
        $this->insertTemplate(body: "{% app_url_for('pc_frontend', 'member/thereIsNoSuchAction') %}");

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringContainsString('route map failure', $output);
    }

    public function test_an_unparsable_template_is_reported(): void
    {
        $this->insertTemplate(body: '{% if x %}never closed');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringContainsString('parse error', $output);
    }

    public function test_a_variable_the_application_does_not_supply_is_reported_as_missing_context(): void
    {
        // Renders fine in production (empty), so only the strict pass can see it.
        $this->insertTemplate(body: 'Hello {{ sender_screen_name }}');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringContainsString('missing context', $output);
        $this->assertStringContainsString('rendering that reference as empty text', $output);
    }

    public function test_a_runtime_fault_is_not_reported_as_missing_context(): void
    {
        // Both are a Twig RuntimeError; only the two-pass derivation keeps them apart.
        $this->insertTemplate(body: '{{ 1 / 0 }}');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringContainsString(MailTemplateFault::RenderFailure->label(), $output);
        $this->assertStringNotContainsString(MailTemplateFault::MissingContext->label(), $output);
    }

    public function test_a_template_that_renders_reports_nothing(): void
    {
        $this->insertTemplate(body: '{{ member_name }} sent you {{ message_subject }} — {{ url }}');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringNotContainsString('does not render', $output);
        $this->assertStringNotContainsString('never sent', $output);
    }

    public function test_a_subject_is_render_tested_too(): void
    {
        $this->insertTemplate(subject: '{{ "x"|upper }}', body: 'fine');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringContainsString('sandbox violation', $output);
    }

    public function test_translations_folding_to_the_same_locale_abort_before_any_write(): void
    {
        // ja_JP and ja both become locale `ja`, which mail_template_translations allows only once.
        $this->insertTemplate(lang: 'ja_JP', body: 'fine');
        $this->insertTranslation(1, 'ja', 'fine');

        [$ok, $output] = $this->preflight(dryRun: false);

        $this->assertFalse($ok);
        $this->assertStringContainsString('all migrate as locale `ja`', $output);
        $this->assertStringContainsString('nothing was migrated', $output);
        $this->assertDatabaseCount('mail_templates', 0);
        $this->assertDatabaseCount('mail_template_translations', 0);
    }

    public function test_an_abort_still_reports_the_templates_that_will_not_render(): void
    {
        // One dry run has to show everything the source needs fixed, not just what stopped it.
        $this->insertTemplate(lang: 'ja_JP', body: 'fine');
        $this->insertTranslation(1, 'ja', 'fine');
        $this->insertTemplate(name: 'pc_friendLinkRequest', body: '{{ "shout"|upper }}');

        [$ok, $output] = $this->preflight(dryRun: false);

        $this->assertFalse($ok);
        $this->assertStringContainsString('all migrate as locale `ja`', $output);
        $this->assertStringContainsString('sandbox violation', $output);
    }

    public function test_a_name_the_source_collation_equates_is_covered_like_the_step_covers_it(): void
    {
        // utf8mb3_unicode_ci is PAD SPACE, so the step's `name IN (…)` and its key CASE both carry this
        // row. Resolving the name in PHP would not, and the row would migrate untested.
        $this->insertTemplate(name: self::SOURCE_NAME.'  ', body: '{{ "shout"|upper }}');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringContainsString('sandbox violation', $output);
    }

    public function test_a_locale_the_application_never_reads_is_inert_and_not_render_tested(): void
    {
        // The body would fail the render check; reaching the inert verdict first is what proves it is skipped.
        $this->insertTemplate(lang: 'ko_KR', body: '{{ "shout"|upper }}');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringContainsString('never sent', $output);
        $this->assertStringNotContainsString('sandbox violation', $output);
    }

    public function test_a_lang_is_folded_the_way_the_step_folds_it(): void
    {
        // The step folds with SQL LIKE under a case-insensitive collation, so JA_JP is a `ja` row — not
        // an inert one, as re-folding in PHP with str_starts_with would have decided.
        $this->insertTemplate(lang: 'JA_JP', body: '{{ "shout"|upper }}');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringNotContainsString('never sent', $output);
        $this->assertStringContainsString('sandbox violation', $output);
    }

    public function test_a_template_the_import_does_not_carry_is_not_render_tested(): void
    {
        // pc_dailyNews is deliberately dropped, so its wording never reaches OpenPNE 4.
        $this->insertTemplate(name: 'pc_dailyNews', body: '{{ "shout"|upper }}');

        [$ok, $output] = $this->preflight();

        $this->assertTrue($ok);
        $this->assertStringNotContainsString('does not render', $output);
    }

    public function test_the_preflight_is_read_only(): void
    {
        $this->insertTemplate(body: '{{ "shout"|upper }}');

        (new MailTemplatePreflight)->inspect('', null);

        $this->assertDatabaseCount('mail_templates', 0);
        $this->assertSame(1, (int) DB::table('notification_mail')->count());
    }

    /** @return array{0: bool, 1: string} */
    private function preflight(bool $dryRun = true): array
    {
        $lines = [];
        $ok = (new UpgradeRunner(new InsertSelectCompiler, [new MailTemplateUpgrade, new MailTemplateTranslationUpgrade]))->run(
            new RunOptions(dryRun: $dryRun),
            function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        return [$ok, implode("\n", $lines)];
    }

    private function insertTemplate(
        string $name = self::SOURCE_NAME,
        string $lang = 'ja_JP',
        string $subject = 'subject',
        string $body = 'body',
    ): void {
        $id = (int) DB::table('notification_mail')->insertGetId([
            'name' => $name,
            'renderer' => 'twig',
            'is_enabled' => 1,
        ]);

        $this->insertTranslation($id, $lang, $body, $subject);
    }

    private function insertTranslation(int $id, string $lang, string $body, string $subject = 'subject'): void
    {
        DB::table('notification_mail_translation')->insert([
            'id' => $id,
            'lang' => $lang,
            'title' => $subject,
            'template' => $body,
        ]);
    }

    private function createSourceTables(): void
    {
        foreach (array_reverse(self::SOURCE_TABLES) as $table) {
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    private function dropSourceTables(): void
    {
        foreach (self::SOURCE_TABLES as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
        }
    }
}
