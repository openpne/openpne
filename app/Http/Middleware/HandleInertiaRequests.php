<?php

namespace App\Http\Middleware;

use App\Features\Friend\Queries\RandomFriends;
use App\Features\GroupTalk\Queries\NavTalkRooms;
use App\Features\Home\Serializers\RightRailSerializer;
use App\Features\Home\UnreadCounts;
use App\Features\Member\Queries\RandomMembers;
use App\Models\Member;
use App\Notifications\Push\WebPushConfig;
use App\Services\TermService;
use App\Support\BrandColor;
use App\Support\Feature;
use App\Support\LookResolver;
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
                    'imageUrl' => $user->avatar?->file?->thumbnailUrl(120, 120, square: true),
                    'avatarColor' => $user->avatar_color?->hex(),
                    // Always false in practice — an AI account cannot sign in — but the shape is a
                    // member reference, and one shape means one Avatar call everywhere.
                    'isAi' => $user->isAiAccount(),
                ] : null,
            ],
            // A guest gets a constant all-false map in the same shape: the gate keeps toggle state
            // unobservable to a guest, and the client type stays non-nullable.
            'enabledFeatures' => $user
                ? Feature::enabledMap()
                : array_fill_keys(array_column(Feature::cases(), 'value'), false),
            // Resolved once for the request and never null: a guest's is always `standard`.
            'look' => LookResolver::resolve($request)->value,
            'unread' => $user ? fn () => app(UnreadCounts::class)->for($user) : null,
            // A plain closure, not Inertia::optional: the rail shows on first render, so the prop must
            // be present then.
            'rightRail' => $user ? fn () => $this->rightRail($user) : null,
            // Null rather than an empty list for a guest or while talk is off; the nav renders no room
            // section on null.
            'talkNavRooms' => $user !== null && Feature::GroupTalk->enabled()
                ? fn () => app(NavTalkRooms::class)($user)
                : null,
            // Null for a guest or a site without a VAPID keypair; the UI hides push on null, so nothing
            // else re-derives availability.
            'push' => $user !== null && WebPushConfig::configured()
                ? ['vapidPublicKey' => (string) config('webpush.vapid.public_key')]
                : null,
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
            // Shipped so the client formats in the site's zone rather than the browser's, which would
            // make Modern and Classic disagree by the viewer's offset (docs/internals/runtime.md).
            'timezone' => config('app.timezone'),
            'terms' => $this->termsForClient($locale),
        ];
    }

    /**
     * The faces grid outlives `friend`: switched off, it samples the whole SNS rather than emptying
     * (docs/internals/feature-toggles.md) — the same rows under the same permissions, a wider pool.
     *
     * @return array<string, mixed>
     */
    private function rightRail(Member $user): array
    {
        $friends = Feature::Friend->enabled();

        return RightRailSerializer::rail(
            $friends ? 'friends' : 'members',
            $friends ? (new RandomFriends)($user) : (new RandomMembers)($user),
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
