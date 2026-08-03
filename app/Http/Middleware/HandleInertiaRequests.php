<?php

namespace App\Http\Middleware;

use App\Features\Community\Queries\RandomJoinedCommunities;
use App\Features\Friend\Queries\RandomFriends;
use App\Features\Home\Serializers\RightRailSerializer;
use App\Features\Home\UnreadCounts;
use App\Features\Member\Queries\RandomMembers;
use App\Models\Member;
use App\Services\TermService;
use App\Support\BrandColor;
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
            // so the shared prop must not disclose it either — and the constant map only ever costs a
            // guest chrome they could not open anyway (the diary hub's friend tab, member-only), so
            // false is safe. Same shape, so the client types stay non-nullable.
            'enabledFeatures' => $user
                ? Feature::enabledMap()
                : array_fill_keys(array_column(Feature::cases(), 'value'), false),
            // Shell nav badges: attention counts for the signed-in member, memoized per request so the
            // dashboard notices reuse them. Null for a guest (a web-public profile renders signed out).
            'unread' => $user ? fn () => app(UnreadCounts::class)->for($user) : null,
            // Right rail (xl+ only): a faces grid and the viewer's joined communities as thumbnails.
            // Evaluated per request for a member; a plain closure (not Inertia::optional) so it is
            // present on first render, which is where the rail shows.
            'rightRail' => $user ? fn () => $this->rightRail($user) : null,
            // Modern brand mark: color + optional logo URL; a null url renders a color initial badge.
            'snsLogo' => [
                'color' => brand_color() ?? BrandColor::DEFAULT,
                'url' => brand_logo_url(),
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
     * The faces grid outlives `friend`: switched off, it samples the whole SNS rather than emptying
     * (docs/internals/feature-toggles.md) — the same rows under the same permissions, a wider pool.
     * Communities have no such purpose apart from the unit, so they just empty, unqueried; the
     * client hides a grid on an empty list.
     *
     * @return array<string, mixed>
     */
    private function rightRail(Member $user): array
    {
        $friends = Feature::Friend->enabled();

        return RightRailSerializer::rail(
            $friends ? 'friends' : 'members',
            $friends ? (new RandomFriends)($user) : (new RandomMembers)($user),
            Feature::Community->enabled() ? (new RandomJoinedCommunities)($user) : collect(),
        );
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
