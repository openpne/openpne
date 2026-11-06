<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\Member;
use App\Support\SurfaceResolver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Passport\Client;
use Symfony\Component\HttpFoundation\Response;

/**
 * The screen names the host the code will be sent to, which league has already matched against the
 * client's registration; the client's own name is a label the registrant chose.
 */
final class ConsentPage
{
    public function __construct(private readonly AiAccountSettings $settings) {}

    /** @param  array{client: Client, user: Member, request: Request, authToken: string}  $params */
    public function render(array $params): Response
    {
        $client = $params['client'];
        $request = $params['request'];
        $redirect = (string) ($request->query('redirect_uri') ?: ($client->redirect_uris[0] ?? ''));
        $accounts = $params['user']->aiAccounts()->with('avatar.file')->orderBy('id')->get();

        $props = [
            'client' => [
                'name' => $client->name,
                'host' => (string) parse_url($redirect, PHP_URL_HOST),
            ],
            'actors' => [
                'aiAccounts' => $accounts->map(MemberRefSerializer::ref(...))->all(),
                'canCreate' => $this->settings->canCreate($accounts->count()),
            ],
            'authToken' => $params['authToken'],
            'requiresPassword' => ! ConsentReauth::isFresh($request->session()),
            // A plain form, not an Inertia visit: the approval answers with a redirect to the client,
            // which an XHR could not follow.
            'csrfToken' => csrf_token(),
        ];

        if (SurfaceResolver::resolve($request, 'mcp') === SurfaceResolver::CLASSIC) {
            return response()->view('auth.oauth-authorize', $props);
        }

        return Inertia::render('auth/oauth-authorize', $props)->toResponse($request);
    }
}
