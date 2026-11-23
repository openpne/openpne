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

    public function test_it_reads_past_the_first_chunk(): void
    {
        foreach (array_chunk(array_map(fn (int $i): array => [
            'email' => $i % 2 === 0 ? "ｕｓｅｒ{$i}@example.com" : "user{$i}@example.com", 'token' => "t{$i}", 'created_at' => now(),
        ], range(1, 1200)), 100) as $part) {
            DB::table('registration_tokens')->insert($part);
        }

        $this->migration()->up();

        $this->assertSame(600, DB::table('registration_tokens')->count());
        $this->assertSame('user1199@example.com', DB::table('registration_tokens')->orderByDesc('id')->value('email'));
    }
}
