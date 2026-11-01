<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use App\Features\AiAccount\AiAccountSettings;
use App\Features\Member\Serializers\MemberRefSerializer;
use App\Models\Member;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Passport\Client;

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
        $redirect = (string) ($params['request']->query('redirect_uri') ?: ($client->redirect_uris[0] ?? ''));
        $accounts = $params['user']->aiAccounts()->with('avatar.file')->orderBy('id')->get();

        return Inertia::render('auth/oauth-authorize', [
            'client' => [
                'name' => $client->name,
                'host' => (string) parse_url($redirect, PHP_URL_HOST),
            ],
            'actors' => [
                'aiAccounts' => $accounts->map(MemberRefSerializer::ref(...))->all(),
                'canCreate' => $this->settings->enabled() && $accounts->count() < $this->settings->limit(),
            ],
            'authToken' => $params['authToken'],
            'requiresPassword' => ! ConsentReauth::isFresh($params['request']->session()),
            // A plain form, not an Inertia visit: the approval answers with a redirect to the client,
            // which an XHR could not follow.
            'csrfToken' => csrf_token(),
        ]);
    }
}
