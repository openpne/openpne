<?php

namespace App\Auth;

use App\Support\CookiePrefix;
use Illuminate\Auth\SessionGuard as BaseSessionGuard;
use Illuminate\Contracts\Foundation\Application;

/**
 * The framework derives both names from `static::class`, so each pins the framework class: a
 * subclass hash would rename the session login key and sign everyone out.
 */
class SessionGuard extends BaseSessionGuard
{
    /**
     * Must stay in step with AuthManager::createSessionDriver().
     *
     * @param  array<string, mixed>  $config
     */
    public static function make(Application $app, string $name, array $config): self
    {
        $guard = new self(
            $name,
            $app['auth']->createUserProvider($config['provider'] ?? null),
            $app['session.store'],
            rehashOnLogin: $app['config']->get('hashing.rehash_on_login', true),
            timeboxDuration: $app['config']->get('auth.timebox_duration', 200000),
            hashKey: $app['config']->get('app.key'),
        );

        $guard->setCookieJar($app['cookie']);
        $guard->setDispatcher($app['events']);
        $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

        if (isset($config['remember'])) {
            $guard->setRememberDuration($config['remember']);
        }

        return $guard;
    }

    public function getName()
    {
        return 'login_'.$this->name.'_'.sha1(BaseSessionGuard::class);
    }

    public function getRecallerName()
    {
        return CookiePrefix::apply('remember_'.$this->name.'_'.sha1(BaseSessionGuard::class));
    }
}
