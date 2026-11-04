<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Notifications\Auth\RegistrationLinkNotification;
use App\Support\SnsSettingKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class AdminReplyToTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.from.address' => 'noreply@example.test', 'mail.from.name' => 'Mailer']);
        Mail::forgetMailers();
        $this->setSnsSetting(SnsSettingKey::SnsName, 'My Group');
    }

    public function test_a_notification_is_from_the_mailer_address_and_replies_go_to_the_administrator(): void
    {
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');

        Notification::route('mail', 'member@example.test')->notifyNow(new RegistrationLinkNotification('raw-token', 'en'));

        $email = $this->onlySentEmail();
        $this->assertSame('noreply@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('My Group', $email->getFrom()[0]->getName());
        $this->assertSame('ops@example.test', $email->getReplyTo()[0]->getAddress());
        $this->assertSame('My Group', $email->getReplyTo()[0]->getName());
    }

    public function test_no_reply_to_when_the_administrator_address_is_the_mailer_address(): void
    {
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'NoReply@example.test');

        Notification::route('mail', 'member@example.test')->notifyNow(new RegistrationLinkNotification('raw-token', 'en'));

        $this->assertSame([], $this->onlySentEmail()->getReplyTo());
    }

    public function test_no_reply_to_when_the_saved_administrator_address_is_a_placeholder(): void
    {
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'hello@example.com');

        Notification::route('mail', 'member@example.test')->notifyNow(new RegistrationLinkNotification('raw-token', 'en'));

        $email = $this->onlySentEmail();
        $this->assertSame('noreply@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame([], $email->getReplyTo());
    }

    public function test_no_reply_to_by_default(): void
    {
        Notification::route('mail', 'member@example.test')->notifyNow(new RegistrationLinkNotification('raw-token', 'en'));

        $this->assertSame([], $this->onlySentEmail()->getReplyTo());
    }

    public function test_a_mail_that_is_not_a_notification_gets_the_same_reply_to(): void
    {
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');

        Mail::raw('body', fn ($message) => $message->to('member@example.test')->subject('raw'));

        $this->assertSame('ops@example.test', $this->onlySentEmail()->getReplyTo()[0]->getAddress());
    }

    public function test_an_explicit_reply_to_is_kept(): void
    {
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');

        Mail::raw('body', fn ($message) => $message->to('member@example.test')->replyTo('author@example.test')->subject('raw'));

        $replyTo = $this->onlySentEmail()->getReplyTo();
        $this->assertCount(1, $replyTo);
        $this->assertSame('author@example.test', $replyTo[0]->getAddress());
    }

    public function test_a_placeholder_from_address_is_replaced_by_the_administrator_and_gets_no_reply_to(): void
    {
        config(['mail.from.address' => 'hello@example.com']);
        Mail::forgetMailers();
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');

        Notification::route('mail', 'member@example.test')->notifyNow(new RegistrationLinkNotification('raw-token', 'en'));

        $email = $this->onlySentEmail();
        $this->assertCount(1, $email->getFrom());
        $this->assertSame('ops@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('My Group', $email->getFrom()[0]->getName());
        $this->assertSame([], $email->getReplyTo());
    }

    public function test_a_raw_mail_under_a_placeholder_from_address_is_also_sent_by_the_administrator(): void
    {
        config(['mail.from.address' => 'hello@example.com']);
        Mail::forgetMailers();
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');

        Mail::raw('body', fn ($message) => $message->to('member@example.test')->subject('raw'));

        $email = $this->onlySentEmail();
        $this->assertSame('ops@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('Mailer', $email->getFrom()[0]->getName());
        $this->assertSame([], $email->getReplyTo());
    }

    public function test_an_empty_from_address_is_replaced_by_the_administrator_for_a_notification(): void
    {
        config(['mail.from.address' => '']);
        Mail::forgetMailers();
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');

        Notification::route('mail', 'member@example.test')->notifyNow(new RegistrationLinkNotification('raw-token', 'en'));

        $email = $this->onlySentEmail();
        $this->assertSame('ops@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('My Group', $email->getFrom()[0]->getName());
        $this->assertSame([], $email->getReplyTo());
    }

    public function test_an_empty_from_address_is_replaced_by_the_administrator_for_a_raw_mail(): void
    {
        config(['mail.from.address' => '']);
        Mail::forgetMailers();
        $this->setSnsSetting(SnsSettingKey::AdminMailAddress, 'ops@example.test');

        Mail::raw('body', fn ($message) => $message->to('member@example.test')->subject('raw'));

        $email = $this->onlySentEmail();
        $this->assertSame('ops@example.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('My Group', $email->getFrom()[0]->getName());
        $this->assertSame([], $email->getReplyTo());
    }

    private function onlySentEmail(): Email
    {
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $original = $messages->first()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $original);

        return $original;
    }
}
