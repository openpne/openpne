<?php

namespace App\Http\Requests\Member;

use App\Notifications\Settings\NotificationKind;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A partial map (`settings[{kind}][{channel}] = bool`): each surface posts a subset of the same shape.
 * Fail-closed: the `array:` key allowlists refuse any kind without a sender and any channel outside
 * web/mail.
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
