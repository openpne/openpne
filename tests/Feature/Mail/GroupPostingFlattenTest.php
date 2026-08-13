<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Models\CommunityEvent;
use App\Models\Group;
use App\Models\GroupTopic;
use App\Models\Member;
use App\Notifications\CommunityEvent\EventPostedNotification;
use App\Notifications\GroupTopic\TopicPostedNotification;
use App\Support\BodyFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The topic/event "posted" mails are text/plain, so a non-plain body must be flattened before it is
 * interpolated into the template — otherwise a Markdown body arrives as literal `**bold**` and an
 * op3 body carries its <op:*> tags.
 */
class GroupPostingFlattenTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_markdown_topic_body_arrives_flattened_in_the_mail_text(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $topic = GroupTopic::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'format' => BodyFormat::Markdown,
            'body' => '**bold** text',
        ]);

        $text = $this->renderMailText(
            (new TopicPostedNotification($group, $topic, $author, ['mail']))->toMail(Member::factory()->create()),
        );

        $this->assertStringContainsString('bold text', $text);
        $this->assertStringNotContainsString('**bold**', $text);
    }

    public function test_an_op3_topic_body_arrives_without_decoration_tags(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $topic = GroupTopic::factory()->create([
            'group_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'format' => BodyFormat::Op3,
            'body' => "<op:b>hi</op:b>\nthere",
        ]);

        $text = $this->renderMailText(
            (new TopicPostedNotification($group, $topic, $author, ['mail']))->toMail(Member::factory()->create()),
        );

        $this->assertStringContainsString("hi\nthere", $text);
        $this->assertStringNotContainsString('<op:b>', $text);
    }

    public function test_a_markdown_event_body_arrives_flattened_in_the_mail_text(): void
    {
        $group = Group::factory()->create();
        $author = Member::factory()->create();
        $event = CommunityEvent::factory()->create([
            'community_id' => $group->getKey(),
            'member_id' => $author->getKey(),
            'format' => BodyFormat::Markdown,
            'body' => '**bold** text',
        ]);

        $text = $this->renderMailText(
            (new EventPostedNotification($group, $event, $author, ['mail']))->toMail(Member::factory()->create()),
        );

        $this->assertStringContainsString('bold text', $text);
        $this->assertStringNotContainsString('**bold**', $text);
    }
}
