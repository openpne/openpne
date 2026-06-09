<?php

namespace App\Features\Auth\Actions;

use App\Actions\Fortify\CreateNewMember;
use App\Models\Member;
use App\Models\RegistrationToken;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the member for a confirmed registration and consumes its token in one transaction, so a
 * token can never outlive the account it created (single-use) nor leave a member without burning the
 * token. The token's email is authoritative — it is forced onto the input, overriding anything the
 * form posted, since the address was proven by the mailed link, not re-entered.
 */
class CompleteRegistration
{
    public function __construct(private CreateNewMember $create) {}

    /**
     * @param  array<string, mixed>  $input  the posted form (name, password, profile); email is ignored
     *
     * @throws ValidationException name/password/profile failed validation
     * @throws QueryException the email was claimed between check and insert
     */
    public function __invoke(RegistrationToken $pending, array $input): Member
    {
        return DB::transaction(function () use ($pending, $input): Member {
            $member = $this->create->create(['email' => $pending->email] + $input);
            $pending->delete();

            return $member;
        });
    }
}
