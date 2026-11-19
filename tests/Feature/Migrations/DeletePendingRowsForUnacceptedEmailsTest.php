<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Member;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeletePendingRowsForUnacceptedEmailsTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_19_000002_delete_pending_rows_for_unaccepted_emails.php');
    }

    public function test_the_rows_a_new_address_would_not_pass_go_and_the_rest_stay(): void
    {
        foreach (['kept@example.com', 'user@xn--n8jyh.jp', 'たろう@example.com', 'user@れい.jp', '"a b"@example.com'] as $i => $email) {
            DB::table('registration_tokens')->insert(['email' => $email, 'token' => "r{$i}", 'created_at' => now()]);
        }
        foreach (['changed@example.com', 'ｕｓｅｒ@example.com'] as $i => $email) {
            DB::table('email_change_requests')->insert(['member_id' => Member::factory()->create()->getKey(), 'new_email' => $email, 'token' => "c{$i}", 'created_at' => now()]);
        }

        $this->migration()->up();

        $this->assertEqualsCanonicalizing(['kept@example.com', 'user@xn--n8jyh.jp'], DB::table('registration_tokens')->pluck('email')->all());
        $this->assertSame(['changed@example.com'], DB::table('email_change_requests')->pluck('new_email')->all());
    }
}
