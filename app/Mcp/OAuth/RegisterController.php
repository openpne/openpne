<?php

declare(strict_types=1);

namespace App\Mcp\OAuth;

use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * Any client may register; what it may not do is receive the code anywhere but over https or on
 * the caller's own machine (docs/internals/mcp.md, "Signing in from a client").
 */
class RegisterController extends OAuthRegisterController
{
    public function __invoke(Request $request): JsonResponse
    {
        $response = parent::__invoke($request);

        if ($response->getStatusCode() === 201) {
            $data = $response->getData(true);
            SecurityLog::event('oauth.client.registered', [
                'client_id' => $data['client_id'],
                'redirect_hosts' => implode(' ', array_map(fn (string $uri): string => (string) parse_url($uri, PHP_URL_HOST), $data['redirect_uris'])),
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }

    protected function isValidRedirectUri(string $value): bool
    {
        if (! parent::isValidRedirectUri($value) || parse_url($value, PHP_URL_FRAGMENT) !== null || preg_match('/\s/', $value)) {
            return false;
        }

        return match (parse_url($value, PHP_URL_SCHEME)) {
            'https' => true,
            'http' => $this->isLocalhostUrl($value),
            default => false,
        };
    }
}
