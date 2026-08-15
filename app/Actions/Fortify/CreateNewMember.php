<?php

namespace App\Actions\Fortify;

use App\Features\Member\MemberNameRules;
use App\Features\Profile\Actions\SaveMemberProfile;
use App\Features\Profile\Data\ProfileFormData;
use App\Features\Profile\ProfileFieldRules;
use App\Http\Middleware\SetLocale;
use App\Models\Member;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewMember implements CreatesNewUsers
{
    use PasswordValidationRules;

    public function __construct(
        private ProfileFieldRules $fieldRules,
        private SaveMemberProfile $saveProfile,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): Member
    {
        $profiles = $this->registrationProfiles();

        $rules = [
            'name' => MemberNameRules::rules(),
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(Member::class)],
            'password' => $this->passwordRules(),
        ];
        foreach ($profiles as $profile) {
            // No member to exclude from a unique field's check — it does not exist yet. A
            // member-editable field also accepts a per-value visibility (OpenPNE 3 registers it).
            $rules += $this->fieldRules->forValue($profile) + $this->fieldRules->visibilityRule($profile);
        }

        $validated = Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($validated, $profiles): Member {
            $member = Member::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
            ]);

            // Promote the guest's explicit pre-login language choice (POST /locale stores it in the
            // session) to the durable per-member locale. forceFill — locale is deliberately not
            // mass-assignable; same write as locale.switch.
            $locale = session('locale');
            if (is_string($locale) && in_array($locale, SetLocale::SUPPORTED_LOCALES, strict: true)) {
                $member->forceFill(['locale' => $locale])->save();
            }

            // Only is_disp_regist fields are saved (saveFields ignores other keys). A submitted
            // visibility is kept for member-editable fields; otherwise it follows the field default.
            $this->saveProfile->saveFields($member, $profiles, new ProfileFormData(
                name: $validated['name'],
                values: $validated['profile'] ?? [],
                visibilities: array_map(
                    fn ($v): ?int => $v === null ? null : (int) $v,
                    $validated['visibility'] ?? [],
                ),
            ));

            return $member;
        });
    }

    /** @return Collection<int, Profile> */
    private function registrationProfiles(): Collection
    {
        return Profile::query()
            ->with('options')
            ->where('is_disp_regist', true)
            ->orderBy('sort_order')
            ->get();
    }
}
