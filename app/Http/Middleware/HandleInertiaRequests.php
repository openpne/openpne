<?php

namespace App\Http\Middleware;

use App\Services\TermService;
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

        return [
            ...parent::share($request),
            'name' => sns_name(),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ] : null,
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
