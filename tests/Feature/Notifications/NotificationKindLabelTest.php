<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Features\Notifications\NotificationKindLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every feed kind's sentence, asserted in English — where the term defaults are identity, so a
 * wrong match arm reads as the wrong sentence instead of an equally plausible key.
 */
class NotificationKindLabelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('en');
    }

    #[DataProvider('kinds')]
    public function test_a_kind_words_its_row(string $kind, ?string $reason, string $expected): void
    {
        $this->assertSame($expected, NotificationKindLabel::for($kind, $reason, 'Alice'));
    }

    /** @return array<string, array{string, ?string, string}> */
    public static function kinds(): array
    {
        return [
            'friend request' => ['friend_requested', null, 'Alice sent you a friend request.'],
            'friend request accepted' => ['friend_request_accepted', null, 'Alice accepted your friend request.'],
            'message' => ['message_received', null, 'Alice sent you a message.'],
            'diary comment on mine' => ['diary_commented', 'reply', 'Alice commented on your diary.'],
            'diary comment on one I commented on' => ['diary_commented', 'related', 'Alice commented on a diary you commented on.'],
            'topic comment on mine' => ['community_topic_commented', 'reply', 'Alice commented on your topic.'],
            'topic comment on one I commented on' => ['community_topic_commented', 'related', 'Alice commented on a topic you commented on.'],
            'topic comment in my community' => ['community_topic_commented', 'community', 'Alice commented on a topic in your community.'],
            'event comment on mine' => ['community_event_commented', 'reply', 'Alice commented on your event.'],
            'event comment on one I commented on' => ['community_event_commented', 'related', 'Alice commented on an event you commented on.'],
            'event comment in my community' => ['community_event_commented', 'community', 'Alice commented on an event in your community.'],
            'community joined' => ['community_joined', null, 'Alice joined your community.'],
            'admin transfer requested' => ['community_admin_transfer_requested', null, 'Alice asked you to take over a community administration.'],
            'sub-admin appointed' => ['community_sub_admin_appointed', null, 'Alice appointed you as a community sub-administrator.'],
            'new diary' => ['diary_posted', null, 'Alice posted a new diary.'],
            'new topic' => ['community_topic_posted', null, 'Alice posted a new topic.'],
            'new event' => ['community_event_posted', null, 'Alice posted a new event.'],
            'mention' => ['timeline_mentioned', null, 'Alice mentioned you in a timeline post.'],
        ];
    }

    /** A comment row written before its reason existed (or with an unrecognised one) reads as the direct case. */
    public function test_a_comment_without_a_reason_falls_back_to_the_direct_wording(): void
    {
        $this->assertSame('Alice commented on your diary.', NotificationKindLabel::for('diary_commented', null, 'Alice'));
        $this->assertSame('Alice commented on your topic.', NotificationKindLabel::for('community_topic_commented', 'whatever', 'Alice'));
        $this->assertSame('Alice commented on your event.', NotificationKindLabel::for('community_event_commented', null, 'Alice'));
    }

    /** A kind this version does not know still gets a line, so the row is never blank. */
    public function test_an_unknown_kind_gets_the_generic_line(): void
    {
        $this->assertSame('New notification', NotificationKindLabel::for('from_a_newer_version', null, 'Alice'));
        $this->assertSame('New notification', NotificationKindLabel::for(null, null, 'Alice'));
    }

    /** The actor is hydrated at render time, so a withdrawn one leaves the sentence intact. */
    public function test_a_withdrawn_actor_keeps_the_sentence(): void
    {
        $this->assertSame('Withdrawn member sent you a friend request.', NotificationKindLabel::for('friend_requested', null, null));
        $this->assertSame('Withdrawn member commented on a diary you commented on.', NotificationKindLabel::for('diary_commented', 'related', null));
    }

    public function test_the_japanese_wording_comes_from_the_dictionary(): void
    {
        $this->app->setLocale('ja');

        $this->assertSame('Alice さんからフレンド申請が届いています。', NotificationKindLabel::for('friend_requested', null, 'Alice'));
    }
}
