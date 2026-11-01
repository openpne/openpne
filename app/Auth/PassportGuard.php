<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\RequestGuard;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Guards\TokenGuard;
use Laravel\Passport\PassportUserProvider;
use League\OAuth2\Server\ResourceServer;

/**
 * Passport's own guard builds the ResourceServer eagerly, which throws without a key pair, so a site
 * without keys gets a guard that answers nobody instead of a 500 on every unauthenticated request.
 */
final class PassportGuard
{
    /** @param  array{provider: string}  $config */
    public static function make(Application $app, array $config): Guard
    {
        $provider = Auth::createUserProvider($config['provider']);

        if (! PassportKeys::present()) {
            return new RequestGuard(fn () => null, $app['request'], $provider);
        }

        $guard = new TokenGuard(
            $app->make(ResourceServer::class),
            new PassportUserProvider($provider, $config['provider']),
            $app->make(ClientRepository::class),
            $app->make('encrypter'),
            $app->make('request'),
        );
        $app->refresh('request', $guard, 'setRequest');

        return $guard;
    }
}
