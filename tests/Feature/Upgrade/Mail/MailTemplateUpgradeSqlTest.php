<?php

namespace Tests\Feature\Upgrade\Mail;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateService;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\SourceSchema;
use App\Upgrade\Steps\MailTemplateTranslationUpgrade;
use App\Upgrade\Steps\MailTemplateUpgrade;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MigratesUpgradeTargetsOnce;
use Tests\TestCase;

/**
 * Runs the compiled notification_mail(+_translation) → mail_templates(+_translations) copy against the
 * real OpenPNE 3 DDL: the in-scope templates carry over with the key remap, required mails are forced on
 * while a configurable one keeps its OpenPNE 3 flag, the unsupported / mobile names drop, the per-locale
 * wording migrates with the lang fold, and a migrated customized body renders byte-for-byte.
 *
 * MySQL only, like the other upgrade SQL tests.
 */
class MailTemplateUpgradeSqlTest extends TestCase
{
    use MigratesUpgradeTargetsOnce;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgrade INSERT...SELECT runs on MySQL (source DDL + set-based copy).');
        }

        foreach (['notification_mail_translation', 'notification_mail'] as $table) {
            DB::statement("DROP TABLE IF EXISTS `{$table}`");
            DB::statement(SourceSchema::default()->createStatement($table, withoutForeignKeys: true));
        }
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('DROP TABLE IF EXISTS `notification_mail_translation`');
            DB::statement('DROP TABLE IF EXISTS `notification_mail`');
        }

        parent::tearDown();
    }

    public function test_migrates_in_scope_templates_with_the_key_remap(): void
    {
        $this->seedMail(1, 'pc_requestRegisterURL');
        $this->seedMail(2, 'pc_changeMailAddress');
        $this->seedMail(3, 'pc_friendLinkComplete');
        $this->seedMail(4, 'pc_signature');
        $this->seedMail(5, 'pc_friendLinkRequest');
        $this->seedMail(6, 'pc_notifyNewMessage');
        $this->seedMail(7, 'pc_registerEnd');
        $this->seedMail(8, 'pc_leave');
        $this->seedMail(9, 'pc_joinCommunity');
        $this->seedMail(10, 'pc_notifyNewDiary');

        $this->runUpgrade();

        // id is preserved (so the per-locale child can FK by it) and the name is remapped to the key.
        $this->assertDatabaseHas('mail_templates', ['id' => 1, 'key' => 'registration-link']);
        $this->assertDatabaseHas('mail_templates', ['id' => 2, 'key' => 'email-change-confirm']);
        $this->assertDatabaseHas('mail_templates', ['id' => 3, 'key' => 'friend-accepted']);
        $this->assertDatabaseHas('mail_templates', ['id' => 4, 'key' => 'signature']);
        $this->assertDatabaseHas('mail_templates', ['id' => 5, 'key' => 'friend-requested']);
        $this->assertDatabaseHas('mail_templates', ['id' => 6, 'key' => 'message-received']);
        $this->assertDatabaseHas('mail_templates', ['id' => 7, 'key' => 'registration-complete']);
        $this->assertDatabaseHas('mail_templates', ['id' => 8, 'key' => 'withdrawal-complete']);
        $this->assertDatabaseHas('mail_templates', ['id' => 9, 'key' => 'community-join']);
        $this->assertDatabaseHas('mail_templates', ['id' => 10, 'key' => 'diary-posted']);
    }

    public function test_an_imported_openpne3_message_body_renders_with_its_flat_variables(): void
    {
        // The OpenPNE 3 notification extension's notifyNewMessage sample uses flat names
        // (member_name/message_subject/…);
        // the OpenPNE 4 notification context must keep providing them so imported wording renders.
        $this->seedMail(6, 'pc_notifyNewMessage');
        $this->seedTranslation(6, 'ja_JP', '新着メッセージ「{{ message_subject }}」', "{{ member_name }}>>\n\n{{ message_body }}\n\n{{ url }}");

        $this->runUpgrade();
        app(MailTemplateService::class)->clearCache();

        $rendered = app(MailTemplateService::class)->render(MailTemplate::MessageReceived, 'ja', [
            'member_name' => 'Alice',
            'message_subject' => 'Hi',
            'message_body' => 'Hello there',
            'url' => 'https://example.test/message/1',
        ]);

        $this->assertSame('新着メッセージ「Hi」', $rendered->subject);
        $this->assertStringContainsString("Alice>>\n\nHello there\n\nhttps://example.test/message/1", $rendered->body);
    }

    public function test_forces_required_mails_on_and_keeps_a_configurable_flag(): void
    {
        // A required mail disabled in OpenPNE 3 must come up enabled (the flow cannot silently break)...
        $this->seedMail(1, 'pc_requestRegisterURL', isEnabled: false);
        // ...while a configurable one keeps the admin's OpenPNE 3 choice.
        $this->seedMail(3, 'pc_friendLinkComplete', isEnabled: false);
        // Non-configurable like the source template — forced on; the member-level opt-out gates it.
        $this->seedMail(5, 'pc_notifyNewDiaryComment', isEnabled: false);
        // Configurable like the source template — the admin's choice carries.
        $this->seedMail(6, 'pc_notifyCommunityPosting', isEnabled: false);
        // Transactional like the source templates (registerEnd/leave configurable:false) — forced on.
        $this->seedMail(7, 'pc_registerEnd', isEnabled: false);
        $this->seedMail(8, 'pc_leave', isEnabled: false);
        // Configurable like the source template — the admin's choice carries.
        $this->seedMail(9, 'pc_joinCommunity', isEnabled: false);
        // Not admin-toggleable like the source template — forced on; the member-level opt-out gates it.
        $this->seedMail(10, 'pc_notifyNewDiary', isEnabled: false);

        $this->runUpgrade();

        $this->assertDatabaseHas('mail_templates', ['key' => 'registration-link', 'is_enabled' => 1]);
        $this->assertDatabaseHas('mail_templates', ['key' => 'friend-accepted', 'is_enabled' => 0]);
        $this->assertDatabaseHas('mail_templates', ['key' => 'diary-comment', 'is_enabled' => 1]);
        $this->assertDatabaseHas('mail_templates', ['key' => 'community-posting', 'is_enabled' => 0]);
        $this->assertDatabaseHas('mail_templates', ['key' => 'registration-complete', 'is_enabled' => 1]);
        $this->assertDatabaseHas('mail_templates', ['key' => 'withdrawal-complete', 'is_enabled' => 1]);
        $this->assertDatabaseHas('mail_templates', ['key' => 'community-join', 'is_enabled' => 0]);
        $this->assertDatabaseHas('mail_templates', ['key' => 'diary-posted', 'is_enabled' => 1]);
    }

    public function test_drops_mobile_and_unsupported_templates(): void
    {
        $this->seedMail(1, 'pc_requestRegisterURL');
        $this->seedMail(5, 'pc_reissuedPassword');       // OpenPNE 4 sends a reset link instead
        $this->seedMail(6, 'pc_birthday');                // Phase 3 digest
        $this->seedMail(7, 'mobile_changeMailAddress');   // mobile frontend out of scope

        $this->runUpgrade();

        $this->assertSame(1, DB::table('mail_templates')->count());
        $this->assertDatabaseHas('mail_templates', ['key' => 'registration-link']);
    }

    public function test_migrates_translations_with_the_locale_fold_and_drops_filtered_parents(): void
    {
        $this->seedMail(3, 'pc_friendLinkComplete');
        $this->seedMail(5, 'pc_reissuedPassword'); // dropped parent — its translation must drop too
        $this->seedTranslation(3, 'ja_JP', 'フレンド成立', 'できました');
        $this->seedTranslation(3, 'en_US', 'Friend linked', 'You are now friends.');
        $this->seedTranslation(5, 'ja_JP', '再発行', 'パスワード');

        $this->runUpgrade();

        $this->assertDatabaseHas('mail_template_translations', [
            'mail_template_id' => 3, 'locale' => 'ja', 'subject' => 'フレンド成立', 'body' => 'できました',
        ]);
        $this->assertDatabaseHas('mail_template_translations', [
            'mail_template_id' => 3, 'locale' => 'en', 'subject' => 'Friend linked',
        ]);
        // The reissued-password translation is gone because its parent was filtered out.
        $this->assertDatabaseMissing('mail_template_translations', ['mail_template_id' => 5]);
    }

    public function test_a_migrated_customized_body_renders_through_the_service(): void
    {
        // An admin customized the OpenPNE 3 friend-accepted body; after the upgrade the OpenPNE 4 service
        // must render exactly that wording (the cutover requirement: same 文面).
        $this->seedMail(3, 'pc_friendLinkComplete');
        $this->seedTranslation(3, 'ja_JP', 'フレンド成立のお知らせ', '{{ member.name }}さんとフレンドになりました。');

        $this->runUpgrade();
        app(MailTemplateService::class)->clearCache();

        $rendered = app(MailTemplateService::class)->render(
            MailTemplate::FriendAccepted, 'ja', ['member' => ['name' => 'Bob']],
        );

        $this->assertSame('フレンド成立のお知らせ', $rendered->subject);
        $this->assertStringContainsString('Bobさんとフレンドになりました。', $rendered->body);
    }

    private function runUpgrade(): void
    {
        $compiler = new InsertSelectCompiler;
        DB::statement($compiler->compile(new MailTemplateUpgrade));
        DB::statement($compiler->compile(new MailTemplateTranslationUpgrade));
    }

    private function seedMail(int $id, string $name, bool $isEnabled = true): void
    {
        DB::table('notification_mail')->insert([
            'id' => $id, 'name' => $name, 'renderer' => 'twig', 'is_enabled' => $isEnabled,
        ]);
    }

    private function seedTranslation(int $id, string $lang, string $title, string $template): void
    {
        DB::table('notification_mail_translation')->insert([
            'id' => $id, 'lang' => $lang, 'title' => $title, 'template' => $template,
        ]);
    }
}
