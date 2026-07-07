<?php

namespace App\Http\Requests\Member;

use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A partial settings map: `settings[{kind}][{channel}] = bool`. Modern posts one toggle at a
 * time, Classic posts every rendered control — both are subsets of the same shape. Fail-closed:
 * the `array:` key allowlists reject any kind without a sender (unwired kinds cannot be written)
 * and any channel outside web/mail.
 */
class UpdateNotificationSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $wired = implode(',', array_map(
            static fn (NotificationKind $kind): string => $kind->value,
            NotificationKind::wiredCases(),
        ));

        return [
            'settings' => ['required', 'array:'.$wired],
            'settings.*' => ['array:web,mail'],
            'settings.*.*' => ['required', 'boolean'],
        ];
    }
}
