<?php

declare(strict_types=1);

namespace App\Features\AiAccount;

use App\Models\Profile;
use App\Services\PresetProfileService;

/**
 * Null is a valid state: an operator may delete the preset, hide it from the profile editor or
 * reshape it into something a free-text panel cannot edit. A caller answers null by not offering the
 * field and never writing a submitted value, so a site without it loses the bio box, not the page.
 */
final class SelfIntroductionField
{
    public function __construct(private readonly PresetProfileService $presets) {}

    public function __invoke(): ?Profile
    {
        $profile = Profile::query()->where('name', $this->presets->nameForKey('self_introduction')['name'])->first();

        // The AI panel is the same edit the profile editor makes, so it honours `is_disp_config`
        // rather than reaching a field that editor has been told to leave alone.
        return $profile !== null
            && $profile->is_disp_config
            && in_array($profile->form_type, ['input', 'textarea'], true)
                ? $profile
                : null;
    }
}
