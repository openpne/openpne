<?php

namespace App\Http\Middleware;

use App\Features\Community\Queries\RandomJoinedCommunities;
use App\Features\Friend\Queries\RandomFriends;
use App\Features\Home\Serializers\RightRailSerializer;
use App\Features\Home\UnreadCounts;
use App\Services\TermService;
use App\Support\Feature;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();
        // Explicit guard: these are member-realm props, and the web group also runs
        // on admin-realm Livewire requests where the default guard is `admin`.
        $user = $request->user('member');

        return [
            ...parent::share($request),
            'name' => sns_name(),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'imageUrl' => $user->avatar?->file?->thumbnailUrl(76, 76, square: true),
                    'avatarColor' => $user->avatar_color?->hex(),
                ] : null,
            ],
            // Which units the administrator has switched on, dependencies resolved. Presentation only —
            // a disabled unit's data does not enter the payload either. Free: the core settings map is
            // already loaded (sns_name above). A guest gets a constant all-false map: the gate's
            // auth-first contract keeps toggle state unobservable to guests (EnsureFeatureEnabled),
            // so the shared prop must not disclose it either — and no guest-visible component renders
            // feature chrome, so false is safe. Same shape, so the client types stay non-nullable.
            'enabledFeatures' => $user
                ? Feature::enabledMap()
                : array_fill_keys(array_column(Feature::cases(), 'value'), false),
            // Shell nav badges: attention counts for the signed-in member, memoized per request so the
            // dashboard notices reuse them. Null for a guest (a web-public profile renders signed out).
            'unread' => $user ? fn () => app(UnreadCounts::class)->for($user) : null,
            // Right rail (xl+ only): the viewer's friends and joined communities as thumbnail grids.
            // Evaluated per request for a member; a plain closure (not Inertia::optional) so it is
            // present on first render, which is where the rail shows. A switched-off unit contributes
            // no rows and runs no query — the client hides a grid on an empty list.
            'rightRail' => $user ? fn () => RightRailSerializer::rail(
                Feature::Friend->enabled() ? (new RandomFriends)($user) : collect(),
                Feature::Community->enabled() ? (new RandomJoinedCommunities)($user) : collect(),
            ) : null,
            // Modern brand mark: color + optional logo URL; a null url renders a color initial badge.
            'snsLogo' => [
                'color' => '#2563eb',
                'url' => null,
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'locale' => $locale,
            'terms' => $this->termsForClient($locale),
        ];
    }

    /**
     * Expand each term into the case/plural placeholder variants the client
     * looks up directly (`%name%`, `%Name%`, `%names%`, `%Names%`), so the
     * frontend stays a flat dictionary read and irregular plurals are
     * resolved here. Japanese collapses every variant to the same value
     * because it has no case and no pluralisation.
     *
     * @return array<string, string>
     */
    private function termsForClient(string $locale): array
    {
        $terms = app(TermService::class)->getTerms($locale);
        $isJa = str_starts_with($locale, 'ja');

        $expanded = [];
        foreach ($terms as $name => $value) {
            $upper = $isJa ? $value : Str::ucfirst($value);
            $plural = $isJa ? $value : Str::plural($value);
            $pluralUpper = $isJa ? $value : Str::ucfirst($plural);

            $expanded[$name] = $value;
            $expanded[Str::ucfirst($name)] = $upper;
            $pluralKey = Str::plural($name);
            $expanded[$pluralKey] = $plural;
            $expanded[Str::ucfirst($pluralKey)] = $pluralUpper;
        }

        return $expanded;
    }
}
