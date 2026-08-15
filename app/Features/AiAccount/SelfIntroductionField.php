<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Profile;
use App\Services\PresetProfileService;

/**
 * The profile field the AI identity panel writes as an account's self-introduction, or null when
 * this install offers none.
 *
 * Null is not exceptional: the preset is seeded on a fresh install and carried over by the upgrade,
 * but an operator may delete it, hide it from the profile editor, or reshape it into something a
 * free-text panel cannot edit. Every caller answers null the same way — the field is not offered, a
 * submitted value is not validated against it and never written — so a site without it loses the
 * bio box, not the page.
 */
final class SelfIntroductionField
{
    public function __construct(private readonly PresetProfileService $presets) {}

    public function __invoke(): ?Profile
    {
        $profile = Profile::query()->where('name', $this->presets->nameForKey('self_introduction')['name'])->first();

        // is_disp_config is the operator's answer to "may a member write this on their own
        // profile?"; the AI panel is that same edit, so it asks the same question rather than
        // reaching a field the profile editor has been told to leave alone.
        return $profile !== null
            && $profile->is_disp_config
            && in_array($profile->form_type, ['input', 'textarea'], true)
                ? $profile
                : null;
    }
}
