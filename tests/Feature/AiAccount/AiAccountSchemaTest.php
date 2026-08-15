<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Models\Member;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the members table itself refuses, on whichever engine is running: an owned row carrying a
 * credential, and an owner going away while an account still points at it. Both are stated in SQL so
 * they hold for a write that never went through the application.
 */
class AiAccountSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owned_row_cannot_be_inserted_with_an_email(): void
    {
        $owner = Member::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('members')->insert([
            'name' => 'Impostor',
            'email' => 'impostor@example.test',
            'owner_member_id' => $owner->getKey(),
        ]);
    }

    public function test_an_owned_row_cannot_be_inserted_with_a_password(): void
    {
        $owner = Member::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('members')->insert([
            'name' => 'Impostor',
            'password' => 'a-bcrypt-shaped-string',
            'owner_member_id' => $owner->getKey(),
        ]);
    }

    public function test_an_owned_row_cannot_be_inserted_with_a_remember_token(): void
    {
        // A remember-me token is a credential like the other two: it is the whole of what a recaller
        // cookie is checked against, so a row holding one holds a way in.
        $owner = Member::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('members')->insert([
            'name' => 'Impostor',
            'remember_token' => Str::random(60),
            'owner_member_id' => $owner->getKey(),
        ]);
    }

    public function test_an_existing_account_cannot_be_given_a_remember_token(): void
    {
        $aiAccount = Member::factory()->aiAccount()->create();

        $this->expectException(QueryException::class);
        DB::table('members')->where('id', $aiAccount->getKey())->update([
            'remember_token' => Str::random(60),
        ]);
    }

    public function test_an_existing_account_cannot_be_given_a_credential(): void
    {
        $aiAccount = Member::factory()->aiAccount()->create();

        $this->expectException(QueryException::class);
        DB::table('members')->where('id', $aiAccount->getKey())->update([
            'password' => 'a-bcrypt-shaped-string',
        ]);
    }

    public function test_an_existing_member_with_credentials_cannot_be_given_an_owner(): void
    {
        // The other direction of the same rule: a login-capable row must not become an AI account by
        // acquiring an owner and keeping its way in.
        $owner = Member::factory()->create();
        $member = Member::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('members')->where('id', $member->getKey())->update([
            'owner_member_id' => $owner->getKey(),
        ]);
    }

    public function test_a_credential_less_owned_row_is_admitted(): void
    {
        $owner = Member::factory()->create();

        DB::table('members')->insert([
            'name' => 'Helper',
            'owner_member_id' => $owner->getKey(),
        ]);

        $this->assertDatabaseHas('members', ['name' => 'Helper', 'owner_member_id' => $owner->getKey()]);
    }
}
