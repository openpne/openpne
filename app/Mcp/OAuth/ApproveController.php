<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use App\Features\AiAccount\Actions\BindConsentActor;
use App\Features\AiAccount\Actions\CreateAiAccount;
use App\Features\AiAccount\Exceptions\AiAccountActionException;
use App\Http\Requests\OAuth\ConsentRequest;
use App\Mcp\McpAbilities;
use App\Models\Member;
use App\Support\SecurityLog;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;
use Laravel\Passport\Http\Controllers\ConvertsPsrResponses;
use Laravel\Passport\Http\Controllers\HandlesOAuthErrors;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport's approve, with the subject and the scopes decided here rather than taken from the
 * signed-in user and the client's request. The session's authorization request stays until the
 * code is committed, so a refused choice can be corrected on the same screen.
 */
class ApproveController
{
    use ConvertsPsrResponses, HandlesOAuthErrors;

    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly BindConsentActor $bind,
        private readonly CreateAiAccount $create,
    ) {}

    public function consent(ConsentRequest $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->authRequestFromSession($request);
        /** @var Member $viewer */
        $viewer = $request->user();
        $actor = $request->actor();
        $readOnly = $request->boolean('read_only');
        // Consumed first and handed back only on a refused choice: a submit arriving after the first
        // answered is a stale token, while two in flight are held apart only by the screen's submit lock.
        $authToken = (string) $request->session()->pull('authToken');

        try {
            if ($actor === 'new') {
                $actor = (int) ($this->create)($viewer, (string) $request->validated('name'))->getKey();
            }

            [$response, $member] = DB::transaction(function () use ($authRequest, $psrResponse, $viewer, $actor, $readOnly): array {
                $member = ($this->bind)($viewer, $actor);

                $authRequest->setUser(new User((string) $member->getKey()));
                $authRequest->setScopes(array_map(
                    fn (string $scope): Scope => new Scope($scope),
                    $readOnly ? [Registrar::OAUTH_SCOPE, McpAbilities::READ] : [Registrar::OAUTH_SCOPE, McpAbilities::READ, McpAbilities::WRITE],
                ));
                $authRequest->setAuthorizationApproved(true);

                return [
                    $this->withErrorHandling(fn (): Response => $this->convertResponse(
                        $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
                    )),
                    $member,
                ];
            });
        } catch (AiAccountActionException $e) {
            $request->session()->put('authToken', $authToken);

            return $this->backWith($e->reason->message());
        }

        $request->session()->forget('authRequest');

        SecurityLog::event('oauth.consent.approved', [
            'member_id' => (int) $viewer->getKey(),
            'actor_id' => (int) $member->getKey(),
            'client_id' => $authRequest->getClient()->getIdentifier(),
            'read_only' => $readOnly,
        ]);

        return $response;
    }

    private function backWith(string $error): RedirectResponse
    {
        return back()->with('error', $error);
    }

    /**
     * Passport's RetrievesAuthRequestFromSession, minus the pull: the two keys stay until the code
     * is committed.
     */
    private function authRequestFromSession(ConsentRequest $request): AuthorizationRequestInterface
    {
        $session = $request->session();

        if ($session->get('authToken') !== $request->validated('auth_token')) {
            $session->forget(['authToken', 'authRequest']);

            throw InvalidAuthTokenException::different();
        }

        $serialized = $session->get('authRequest') ?? throw new Exception('Authorization request was not present in the session.');

        return unserialize($serialized, ['allowed_classes' => [
            AuthorizationRequest::class,
            Client::class,
            Scope::class,
            User::class,
        ]]);
    }
}
