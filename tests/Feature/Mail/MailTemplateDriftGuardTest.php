<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Mail\Template\MailTemplate;
use App\Mail\Template\MailTemplateRenderer;
use App\Mail\Template\MailTemplateService;
use App\Models\CommunityTopic;
use App\Models\Diary;
use App\Models\DirectMessage;
use App\Models\Group;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Notifications\Auth\RegistrationLinkNotification;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\CommentReason;
use App\Notifications\CommunityTopic\TopicCommentedNotification;
use App\Notifications\Diary\DiaryCommentedNotification;
use App\Notifications\Diary\DiaryPostedNotification;
use App\Notifications\DirectMessage\DirectMessageReceivedNotification;
use App\Notifications\Friend\FriendRequestAcceptedNotification;
use App\Notifications\Friend\FriendRequestedNotification;
use App\Notifications\Group\GroupJoinedNotification;
use App\Notifications\Member\EmailChangeConfirmationNotification;
use App\Notifications\Member\EmailChangeNoticeNotification;
use App\Notifications\Member\MfaDisabledNotification;
use App\Notifications\Member\MfaEnabledNotification;
use App\Notifications\Member\MfaResetLinkNotification;
use App\Notifications\Member\PasswordChangedNotification;
use App\Notifications\Member\RegistrationCompletedNotification;
use App\Notifications\Member\WithdrawalAdminNotification;
use App\Notifications\Member\WithdrawalCompletedNotification;
use App\Notifications\Timeline\TimelineMentionedNotification;
use App\Notifications\Timeline\TimelinePostedNotification;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

/**
 * Drift guard for the mail-template registry. Production renders with strict_variables off (an absent
 * variable renders empty, matching OpenPNE 3's lenient templates), so a body/subject referencing an
 * undeclared variable — or a notification that fails to pass one — fails silently. Here everything is
 * rendered through a STRICT renderer, so such a mismatch throws.
 *
 * Caveat: strict rendering only exercises the paths the sample context reaches — a variable behind a
 * false `{% if %}`, an empty `{% for %}`, or absorbed by `|default` is not checked. This guards the
 * current built-in defaults; a default that later gains such a construct needs an added case or token
 * extraction to stay covered.
 */
class MailTemplateDriftGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSnsSetting(SnsSettingKey::SnsName, 'My Group');
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');
        // Every render below goes through this strict service so an undeclared/undelivered variable throws.
        $this->app->instance(MailTemplateService::class, new MailTemplateService(new MailTemplateRenderer(strictVariables: true)));
    }

    public function test_default_templates_reference_only_declared_variables(): void
    {
        $service = app(MailTemplateService::class);

        $rendered = 0;
        foreach (MailTemplate::cases() as $template) {
            foreach (['en', 'ja'] as $locale) {
                // representativeContext() is the declared variable set (plus the globals the service adds);
                // a default subject/body touching anything outside it throws under strict rendering.
                $service->render($template, $locale, $template->representativeContext());
                $rendered++;
            }
        }

        // Reached only if nothing above threw.
        $this->assertSame(count(MailTemplate::cases()) * 2, $rendered);
    }

    public function test_every_sendable_notification_delivers_its_body_variables(): void
    {
        $sender = Member::factory()->create(['name' => 'Sender']);
        $recipient = Member::factory()->create();
        $message = DirectMessage::factory()->create(['sender_id' => $sender->getKey()]);
        $diary = Diary::factory()->create(['member_id' => $recipient->getKey()]);
        $comment = $diary->comments()->create(['member_id' => $sender->getKey(), 'number' => 1, 'body' => 'a comment']);
        $topic = CommunityTopic::factory()->create(['member_id' => $recipient->getKey()]);
        $topicComment = $topic->comments()->create(['member_id' => $sender->getKey(), 'number' => 1, 'body' => 'a comment']);
        $group = Group::factory()->create();
        $post = TimelinePost::factory()->create(['member_id' => $sender->getKey()]);

        // One notification per sendable template; RegistrationLink carries an inviter name + message so the
        // conditional block that uses them is actually exercised.
        $notifications = [
            [new RegistrationLinkNotification('raw-token', 'en', inviterName: 'Inviter', message: 'Welcome'), new AnonymousNotifiable],
            [new ResetPasswordNotification('the-token', 'en'), $recipient],
            [new EmailChangeConfirmationNotification('the-token', (int) $recipient->getKey(), 'en'), new AnonymousNotifiable],
            [new EmailChangeNoticeNotification('new@example.test', 'cancel-token', 'en'), $recipient],
            [new FriendRequestedNotification($sender), $recipient],
            [new FriendRequestAcceptedNotification($sender), $recipient],
            [new DirectMessageReceivedNotification($sender, $message), $recipient],
            [new DiaryCommentedNotification($sender, $diary, $comment, CommentReason::Reply), $recipient],
            [new DiaryPostedNotification($diary, $sender, ['mail']), $recipient],
            [new TopicCommentedNotification($sender, $topic, $topicComment, CommentReason::Reply), $recipient],
            [new GroupJoinedNotification($group, $sender), $recipient],
            [new RegistrationCompletedNotification('en'), $recipient],
            [new WithdrawalCompletedNotification('Sender', 'en'), new AnonymousNotifiable],
            [new WithdrawalAdminNotification('Sender', 'sender@example.test', (int) $sender->getKey(), 'en'), new AnonymousNotifiable],
            [new PasswordChangedNotification('en'), $recipient],
            [new MfaEnabledNotification('en'), $recipient],
            [new MfaDisabledNotification('en'), $recipient],
            [new MfaResetLinkNotification('the-token', 'en'), new AnonymousNotifiable],
            [new TimelineMentionedNotification($sender, $post), $recipient],
            [new TimelinePostedNotification($post, $sender, ['mail']), $recipient],
        ];

        $this->assertCount(count(MailTemplate::sendable()), $notifications, 'one guarded notification per sendable template');

        foreach ($notifications as [$notification, $notifiable]) {
            // Throws under strict rendering if the notification's real context omits a variable its default
            // body/subject uses.
            $this->assertInstanceOf(MailMessage::class, $notification->toMail($notifiable));
        }
    }
}
