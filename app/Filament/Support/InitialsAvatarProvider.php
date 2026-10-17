<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * The initials drawn into an SVG data URL, so the panel sends no administrator's name to a third
 * party for an avatar (Filament's default provider asks ui-avatars.com).
 */
final class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $initials = collect(preg_split('/\s+/u', trim(Filament::getNameForDefaultAvatar($record))) ?: [])
            ->map(fn (string $word): string => mb_substr((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $word), 0, 1))
            ->filter()
            ->take(2)
            ->implode('');

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="32" fill="#030712"/>'
            .'<text x="32" y="32" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="26" fill="#fff">%s</text></svg>',
            htmlspecialchars($initials === '' ? '?' : mb_strtoupper($initials), ENT_QUOTES | ENT_XML1, 'UTF-8'),
        );

        return 'data:image/svg+xml;utf8,'.rawurlencode($svg);
    }
}
