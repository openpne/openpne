<?php

namespace Tests\Feature\Auth;

use App\Notifications\Auth\LoginCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LoginCodeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_queued_mail_does_not_carry_the_code_in_the_clear(): void
    {
        config(['queue.default' => 'database']);

        Notification::route('mail', 'member@example.test')->notify(new LoginCodeNotification('code-sentinel-7391', 10, 'en'));

        $payload = DB::table('jobs')->sole()->payload;
        $this->assertStringContainsString('LoginCodeNotification', $payload);
        $this->assertStringNotContainsString('code-sentinel-7391', $payload);
    }
}
