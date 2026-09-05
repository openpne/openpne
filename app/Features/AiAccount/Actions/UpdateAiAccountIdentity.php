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
 * The caller has already established that the account is the owner's.
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

        // Held to the same rule an ordinary member's name is, here and not only in the form, so no
        // caller can rename an account to nothing.
        Validator::make(['name' => $name], ['name' => MemberNameRules::rules()])->validate();

        $field = ($this->selfIntroduction)();

        DB::transaction(function () use ($aiAccount, $name, $field, $selfIntroduction): void {
            $aiAccount->update(['name' => $name]);

            if ($field === null) {
                return;
            }

            // `saveFields`, never `SaveMemberProfile::__invoke`: that rewrites the member's whole
            // is_disp_config set from the submission, erasing every value this panel does not carry.
            $this->profiles->saveFields($aiAccount, new Collection([$field]), new ProfileFormData(
                name: $name,
                values: [$field->getKey() => (string) $selfIntroduction],
                visibilities: [$field->getKey() => $this->audienceFor($aiAccount, $field)],
            ));
        });
    }

    /**
     * This panel shows no audience control and the row is replaced wholesale, so the audience the
     * value already carries has to be handed back explicitly or every save would widen it to the
     * field default. A value the account has never held starts at that default.
     */
    private function audienceFor(Member $aiAccount, Profile $field): ?int
    {
        $row = $aiAccount->memberProfiles()->where('profile_id', $field->getKey())->first();

        // A stored null follows the field default by design; it is carried over as a null, not
        // frozen into the default it currently resolves to.
        return $row === null ? $field->default_visibility->value : $row->visibility?->value;
    }
}
