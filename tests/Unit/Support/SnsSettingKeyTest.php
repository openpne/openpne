<?php

namespace Tests\Unit\Support;

use App\Features\GroupTalk\GroupTalkNotifyMode;
use App\Support\Look;
use App\Support\SettingGroup;
use App\Support\SnsSettingKey;
use PHPUnit\Framework\TestCase;

class SnsSettingKeyTest extends TestCase
{
    public function test_web_public_age_decodes_fail_closed(): void
    {
        $key = SnsSettingKey::AllowWebPublicAge;

        // `true` widens exposure, so only an explicit '1' enables it; a malformed/empty/absent value
        // must stay off (the opposite direction from CaptchaEnabled's fail-closed-on).
        $this->assertTrue($key->decode('1'));
        $this->assertFalse($key->decode('0'));
        $this->assertFalse($key->decode(''));
        $this->assertFalse($key->decode('x'));
        $this->assertFalse($key->decode(null)); // absent → default
        $this->assertFalse($key->default());
    }

    public function test_web_public_age_upgrades_from_op3(): void
    {
        $key = SnsSettingKey::AllowWebPublicAge;

        $this->assertSame('is_allow_web_public_flag_age', $key->op3SourceName());
        $this->assertTrue($key->isMigratedFromOp3());
        $this->assertSame($key, SnsSettingKey::fromOp3SourceName('is_allow_web_public_flag_age'));
    }

    public function test_web_public_diary_defaults_on_but_decodes_fail_closed(): void
    {
        $key = SnsSettingKey::DiaryAllowWebPublic;

        // Absent is OpenPNE 3's default (on) — the site never decided. A STORED value that is neither
        // '1' nor '0' is corruption, and for an exposure switch corruption reads as off.
        $this->assertTrue($key->decode(null));
        $this->assertTrue($key->default());
        $this->assertTrue($key->decode('1'));
        $this->assertFalse($key->decode('0'));
        $this->assertFalse($key->decode(''));
        $this->assertFalse($key->decode('x'));
    }

    public function test_web_public_diary_upgrades_from_op3(): void
    {
        $key = SnsSettingKey::DiaryAllowWebPublic;

        $this->assertSame('op_diary_plugin_use_open_diary', $key->op3SourceName());
        $this->assertTrue($key->isMigratedFromOp3());
        $this->assertSame($key, SnsSettingKey::fromOp3SourceName('op_diary_plugin_use_open_diary'));
    }

    public function test_login_message_is_free_text_bounded_by_the_text_column(): void
    {
        $key = SnsSettingKey::LoginMessage;

        $this->assertSame(SettingGroup::LoginScreen, $key->group());
        // OpenPNE 3 carried this kind of copy in the login gadgets, so there is nothing to copy over.
        $this->assertNull($key->op3SourceName());
        $this->assertFalse($key->isMigratedFromOp3());
        $this->assertSame('', $key->default());
        // Stored verbatim, like the design blobs: a leading indent is Markdown syntax, not noise.
        $this->assertSame("  # Hi\n", $key->coerce("  # Hi\n"));
        $this->assertSame(65535, $key->maxBytes());
    }

    public function test_ai_accounts_are_off_until_an_operator_turns_them_on(): void
    {
        $key = SnsSettingKey::AiAccountsEnabled;

        $this->assertSame(SettingGroup::Ai, $key->group());
        // OpenPNE 3 had no AI accounts, so an upgraded site starts where a fresh one does.
        $this->assertNull($key->op3SourceName());
        $this->assertFalse($key->isMigratedFromOp3());
        $this->assertFalse($key->default());

        // Fail closed, like the other opt-in switches: only an explicit '1' opens creation.
        $this->assertTrue($key->decode('1'));
        $this->assertFalse($key->decode('0'));
        $this->assertFalse($key->decode(''));
        $this->assertFalse($key->decode('x'));
        $this->assertFalse($key->decode(null));
        $this->assertSame('1', $key->encode($key->coerce('1')));
        $this->assertSame('0', $key->encode($key->coerce(false)));
    }

    public function test_the_ai_account_limit_is_the_registrys_one_integer_key(): void
    {
        $key = SnsSettingKey::AiAccountLimit;

        $this->assertSame(SettingGroup::Ai, $key->group());
        $this->assertFalse($key->isMigratedFromOp3());
        $this->assertSame(3, $key->default());

        // Round-trips as an integer, with whitespace and a numeric string tolerated on the way in.
        $this->assertSame('5', $key->encode($key->coerce(' 5 ')));
        $this->assertSame(5, $key->decode('5'));
        $this->assertSame(0, $key->decode('0'));

        // A stored value that is not a number is corruption rather than a decision, so it reads as
        // the shipped cap; a negative one clamps to "create nothing" instead of inverting the
        // comparison it feeds.
        $this->assertSame(3, $key->decode('x'));
        $this->assertSame(3, $key->decode(''));
        $this->assertSame(3, $key->decode(null));
        $this->assertSame(0, $key->decode('-1'));
    }

    public function test_the_default_look_is_standard_until_an_operator_opts_in(): void
    {
        $key = SnsSettingKey::DefaultLook;

        $this->assertSame(SettingGroup::Look, $key->group());
        // OpenPNE 3 had no Modern surface, so there is no layout choice to carry over.
        $this->assertNull($key->op3SourceName());
        $this->assertFalse($key->isMigratedFromOp3());
        $this->assertSame(Look::Standard, $key->default());

        // Round-trips as the typed enum, from either the enum itself or the posted id.
        $this->assertSame('unified', $key->encode($key->coerce(Look::Unified)));
        $this->assertSame('unified', $key->encode($key->coerce(' unified ')));
        $this->assertSame(Look::Unified, $key->decode('unified'));
        $this->assertSame(Look::Standard, $key->decode('standard'));

        // A value no registered look answers to is corruption, not a decision: it reads as the
        // layout the site shipped with rather than as an experiment.
        $this->assertSame(Look::Standard, $key->decode(''));
        $this->assertSame(Look::Standard, $key->decode('Unified'));
        $this->assertSame(Look::Standard, $key->decode('nonesuch'));
        $this->assertSame(Look::Standard, $key->decode(null));
        $this->assertSame('standard', $key->encode($key->coerce('nonesuch')));
    }

    public function test_the_selectable_looks_are_a_list_stored_as_csv(): void
    {
        $key = SnsSettingKey::SelectableLooks;

        $this->assertSame(SettingGroup::Look, $key->group());
        $this->assertNull($key->op3SourceName());
        // Nothing on offer until an operator ticks something; the site runs on its default alone.
        $this->assertSame([], $key->default());
        $this->assertSame([], $key->decode(null));
        $this->assertSame([], $key->decode(''));

        // The checkbox list posts an array of ids; it round-trips through the stored CSV.
        $this->assertSame('standard,unified', $key->encode($key->coerce(['standard', 'unified'])));
        $this->assertSame([Look::Standard, Look::Unified], $key->decode('standard,unified'));

        // Order is the registry's, not the submission's, and a duplicate collapses — one set, one
        // stored representation.
        $this->assertSame('standard,unified', $key->encode($key->coerce([Look::Unified, 'standard', 'unified'])));

        // An id no registered look answers to drops out rather than taking the row down with it.
        $this->assertSame([Look::Unified], $key->decode('nonesuch,unified'));
        $this->assertSame('unified', $key->encode($key->coerce(['unified', 'nonesuch'])));
    }

    public function test_the_selectable_looks_codec_survives_an_actual_array(): void
    {
        // Both arms are explicit for this key because the default ones do `(string) $value`, which
        // fatals on an array — the admin save posts one, so a missing arm would be a 500.
        $key = SnsSettingKey::SelectableLooks;

        $this->assertSame([], $key->coerce([]));
        $this->assertSame('', $key->encode([]));
        $this->assertSame('standard', $key->encode([Look::Standard]));
        // A crafted post can nest an array where an id belongs; it drops instead of fatalling.
        $this->assertSame([], $key->coerce([['unified']]));
    }

    public function test_the_talk_notification_default_is_mentions_until_an_operator_opts_in(): void
    {
        $key = SnsSettingKey::GroupTalkNotifyDefault;

        $this->assertSame(SettingGroup::GroupTalk, $key->group());
        // OpenPNE 3 had no group chat, so there is nothing to carry over.
        $this->assertNull($key->op3SourceName());
        $this->assertFalse($key->isMigratedFromOp3());
        $this->assertFalse($key->isRequired());
        $this->assertSame(GroupTalkNotifyMode::Mentions->value, $key->default());

        // Stored as the plain backing value, like RegistrationMode. What an unreadable one means is
        // GroupTalkNotifyDefault's to answer (the quieter mode), not the codec's to guess.
        $this->assertSame('all', $key->encode($key->coerce(' all ')));
        $this->assertSame('all', $key->decode('all'));
        $this->assertSame(GroupTalkNotifyMode::Mentions->value, $key->decode(null));
    }

    public function test_branding_keys_are_unbranded_by_default_and_never_upgrade(): void
    {
        // OpenPNE 3 had no per-site logo/color/favicon, so there is nothing to copy: a fresh and an
        // upgraded install both start unbranded, and the value stays whatever the admin page stored.
        foreach ([SnsSettingKey::BrandColor, SnsSettingKey::BrandLogoFile, SnsSettingKey::BrandFaviconFile] as $key) {
            $this->assertSame(SettingGroup::Branding, $key->group(), $key->value);
            $this->assertNull($key->op3SourceName(), $key->value);
            $this->assertFalse($key->isMigratedFromOp3(), $key->value);
            $this->assertSame('', $key->default(), $key->value);
            $this->assertSame('#0088aa', $key->decode('#0088aa'), $key->value);
        }
    }

    public function test_board_comment_reply_links_are_off_by_default_and_upgrade_from_op3(): void
    {
        foreach ([
            [SnsSettingKey::GroupTopicCommentReply, 'op_community_topic_plugin_community_topic_comment_reply'],
            [SnsSettingKey::GroupEventCommentReply, 'op_community_topic_plugin_community_event_comment_reply'],
        ] as [$key, $source]) {
            $this->assertFalse($key->default());
            $this->assertSame(SettingGroup::GroupBoard, $key->group());
            $this->assertSame($source, $key->op3SourceName());
            $this->assertTrue($key->isMigratedFromOp3());
            $this->assertTrue($key->coerce('1'));
            $this->assertFalse($key->coerce('0'));
            $this->assertSame('1', $key->encode(true));
            // A stored row decodes to a typed bool, and only an explicit '1' switches the link on.
            $this->assertSame(true, $key->decode('1'));
            $this->assertSame(false, $key->decode('0'));
            $this->assertSame(false, $key->decode('yes'));
        }
    }
}
