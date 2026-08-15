<?php

declare(strict_types=1);

namespace App\Features\AiAccount\Actions;

use App\Features\AiAccount\SelfIntroductionField;
use App\Features\Member\MemberNameRules;
use App\Features\Profile\Actions\SaveMemberProfile;
use App\Features\Profile\Data\ProfileFormData;
use App\Models\Member;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Rewrite one AI account's identity: the name it speaks under, and the self-introduction its
 * profile carries. The caller has already established that the account is the owner's.
 */
class UpdateAiAccountIdentity
{
    public function __construct(
        private readonly SelfIntroductionField $selfIntroduction,
        private readonly SaveMemberProfile $profiles,
    ) {}

    public function __invoke(Member $aiAccount, string $name, ?string $selfIntroduction): void
    {
        $name = trim($name);

        // Same rule an ordinary member's name is written under, here rather than only in the form
        // that submitted it — as at creation, so no caller can rename an account to nothing.
        Validator::make(['name' => $name], ['name' => MemberNameRules::rules()])->validate();

        $field = ($this->selfIntroduction)();

        DB::transaction(function () use ($aiAccount, $name, $field, $selfIntroduction): void {
            $aiAccount->update(['name' => $name]);

            if ($field === null) {
                return;
            }

            // saveFields with one field, never SaveMemberProfile's __invoke: that rewrites the
            // member's whole is_disp_config set from the submission, so a panel carrying a single
            // field would erase every other value the account holds.
            $this->profiles->saveFields($aiAccount, new Collection([$field]), new ProfileFormData(
                name: $name,
                values: [$field->getKey() => (string) $selfIntroduction],
                visibilities: [$field->getKey() => $this->audienceFor($aiAccount, $field)],
            ));
        });
    }

    /**
     * The audience the rewritten value keeps. This panel shows no audience control and the row is
     * replaced wholesale, so what the value already carries has to be handed back explicitly —
     * otherwise every save would drop it and the value would be read at the field's default
     * instead, which is a widening nobody asked for. A value the account has never held is new
     * content, and starts at that default.
     */
    private function audienceFor(Member $aiAccount, Profile $field): ?int
    {
        $row = $aiAccount->memberProfiles()->where('profile_id', $field->getKey())->first();

        // A stored null follows the field default by design; it is carried over as a null, not
        // frozen into the default it currently resolves to.
        return $row === null ? $field->default_visibility->value : $row->visibility?->value;
    }
}
