<?php

namespace App\Http\Requests\Member;

use App\Models\Member;
use App\Support\Look;
use App\Support\LookResolver;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting a look preview from the member config page. The choice is either a look id or the
 * literal "default" (follow the site's), which is why it is not a plain Rule::enum.
 */
class PreviewLookRequest extends FormRequest
{
    /** The choice that means "no look of my own — show me whatever the site default is". */
    public const FOLLOW_DEFAULT = 'default';

    public function authorize(): bool
    {
        if (! $this->user() instanceof Member) {
            return false;
        }

        $choice = $this->input('choice');
        // Shape is the validator's business (422); following the site default needs no permission,
        // the default being selectable by definition.
        if (! is_string($choice) || $choice === self::FOLLOW_DEFAULT) {
            return true;
        }

        // A hard gate rather than a hidden control: the section is absent while the site offers one
        // look, so a crafted post must not park a member on a layout they were never offered.
        $look = Look::tryFrom($choice);

        return $look !== null && in_array($look, LookResolver::selectable(), true);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['choice' => ['required', 'string']];
    }

    /** The chosen look, or null for "follow the site default". Valid by the time this is read. */
    public function look(): ?Look
    {
        $choice = (string) $this->validated('choice');

        return $choice === self::FOLLOW_DEFAULT ? null : Look::from($choice);
    }
}
