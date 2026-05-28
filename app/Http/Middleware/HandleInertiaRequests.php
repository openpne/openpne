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
            'name' => config('app.name'),
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
     * Pre-compute the four placeholder variants per term so the client only
     * needs a flat key lookup: `%name%`, `%Name%`, `%names%`, `%Names%`.
     * Japanese collapses to the same value for every variant because it has
     * no case and no pluralisation.
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
