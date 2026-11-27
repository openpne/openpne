<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Auth\Reauth\ReauthPurpose;
use App\Auth\Reauth\RequiresReauth;
use App\Models\Member;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\FormRequest;

class AdminEntryRequest extends FormRequest
{
    use RequiresReauth;

    public function authorize(): bool
    {
        $member = $this->user();

        return $member instanceof Member && $member->canAccessPanel(Filament::getPanel('admin'));
    }

    public function reauthPurpose(): ReauthPurpose
    {
        return ReauthPurpose::AdminEntry;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->reauthRules();
    }
}
