<?php

declare(strict_types=1);

namespace Tests\Feature\AiAccount;

use App\Features\AiAccount\Actions\IssueMcpToken;
use App\Features\AiAccount\Actions\RevokeMcpToken;
use App\Features\AiAccount\Actions\RevokeMcpTokens;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Features\AiAccount\Exceptions\AiAccountActionFailure;
use App\Mcp\McpAbilities;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * The token Actions, which the CLI and the owner's settings screen share. They hold no
 * authorization — that is each caller's — so what is pinned here is what neither caller may differ
 * on: the abilities are named, a banned actor (or a banned owner behind one) is refused, and a
 * revocation reaches only this endpoint's tokens on the member it was asked about.
 */
class McpTokenActionsTest extends TestCase
{
    use RefreshDatabase;

    private function issue(): IssueMcpToken
    {
        return app(IssueMcpToken::class);
    }

    public function test_a_member_gets_a_named_read_write_token(): void
    {
        $member = Member::factory()->create();

        $token = ($this->issue())($member);

        $this->assertSame(McpAbilities::TOKEN_NAME, $token->accessToken->name);
        $this->assertSame([McpAbilities::READ, McpAbilities::WRITE], $token->accessToken->abilities);
        $this->assertTrue($token->accessToken->tokenable->is($member));
    }

    public function test_read_only_drops_the_write_ability_and_nothing_else(): void
    {
        $token = ($this->issue())(Member::factory()->create(), readOnly: true);

        $this->assertSame([McpAbilities::READ], $token->accessToken->abilities);
    }

    public function test_an_ai_account_gets_a_token_of_its_own(): void
    {
        $owner = Member::factory()->create();
        $aiAccount = Member::factory()->aiAccount($owner)->create();

        $token = ($this->issue())($aiAccount);

        $this->assertTrue($token->accessToken->tokenable->is($aiAccount));
        $this->assertSame(0, $owner->tokens()->count());
    }

    public function test_a_frozen_member_is_refused(): void
    {
        $member = Member::factory()->create(['is_login_rejected' => true]);

        $this->expectException(AiAccountActionException::class);

        try {
            ($this->issue())($member);
        } finally {
            $this->assertSame(0, PersonalAccessToken::count());
        }
    }

    public function test_an_ai_account_whose_owner_is_frozen_is_refused(): void
    {
        // The eligibility question is asked of the owner as well as of the account, on the rows this
        // transaction locked. Drop that re-read and a mint slips past a ban that committed while
        // the form was open — which is the hole the freeze sweep exists to close.
        $owner = Member::factory()->create(['is_login_rejected' => true]);
        $aiAccount = Member::factory()->aiAccount($owner)->create();

        try {
            ($this->issue())($aiAccount);
            $this->fail('a frozen owner must not be able to mint for their AI account');
        } catch (AiAccountActionException $e) {
            $this->assertSame(AiAccountActionFailure::ActorFrozen, $e->reason);
        }

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_a_row_that_is_no_longer_there_is_refused_rather_than_minted_against(): void
    {
        $ghost = Member::factory()->make();
        $ghost->id = 999999;

        try {
            ($this->issue())($ghost);
            $this->fail('a withdrawn member must not be mintable');
        } catch (AiAccountActionException $e) {
            $this->assertSame(AiAccountActionFailure::MemberGone, $e->reason);
        }

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_revoking_one_token_reaches_only_that_members_mcp_tokens(): void
    {
        $revoke = app(RevokeMcpToken::class);
        $member = Member::factory()->create();
        $bystander = Member::factory()->create();
        $mine = ($this->issue())($member)->accessToken;
        $theirs = ($this->issue())($bystander)->accessToken;
        // Explicit narrow ability: omitting it would mint Sanctum's wildcard token.
        $other = $member->createToken('other', ['reporting'])->accessToken;

        $this->assertFalse($revoke($member, (int) $theirs->getKey()), 'another member\'s token');
        $this->assertFalse($revoke($member, (int) $other->getKey()), 'a token minted for something else');
        $this->assertFalse($revoke($member, 999999), 'an id naming nothing');
        $this->assertTrue($revoke($member, (int) $mine->getKey()));

        $this->assertSame(
            [$theirs->getKey(), $other->getKey()],
            PersonalAccessToken::orderBy('id')->pluck('id')->all(),
        );
    }

    public function test_revoking_all_of_them_leaves_other_purposes_alone(): void
    {
        $member = Member::factory()->create();
        ($this->issue())($member);
        ($this->issue())($member);
        $other = $member->createToken('other', ['reporting'])->accessToken;

        $this->assertSame(2, app(RevokeMcpTokens::class)($member));

        $this->assertSame([$other->getKey()], PersonalAccessToken::pluck('id')->all());
    }
}
