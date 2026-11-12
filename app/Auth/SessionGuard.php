<?php

namespace App\Auth;

use App\Support\CookiePrefix;
use Illuminate\Auth\SessionGuard as BaseSessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;

/**
 * The framework derives both names from `static::class`, so each pins the framework class: a
 * subclass hash would rename the session login key and sign everyone out.
 */
class SessionGuard extends BaseSessionGuard
{
    /** False: no remember-me cookie is issued or read (docs/internals/sessions.md, "Lifetimes"). */
    private bool $recallerEnabled = true;

    /** Minutes after sign-in at which the session stops naming its user; null for no limit. */
    private ?int $absoluteMinutes = null;

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

        $guard->recallerEnabled = (bool) ($config['recaller'] ?? true);
        $guard->absoluteMinutes = isset($config['absolute_lifetime']) ? (int) $config['absolute_lifetime'] : null;

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

    /**
     * An over-age session loses its login key but is not invalidated: the CSRF token stands, so the
     * request that finds it expired is answered as any guest's rather than with a 419.
     */
    public function user()
    {
        if ($this->user === null && ! $this->loggedOut) {
            $this->forgetLegacyRecaller();
            $this->endOverAgeLogin();
        }

        return parent::user();
    }

    public function login(Authenticatable $user, $remember = false)
    {
        parent::login($user, $remember && $this->recallerEnabled);

        if ($this->absoluteMinutes !== null) {
            $this->session->put($this->signedInAtKey(), now()->getTimestamp());
        }
    }

    protected function recaller()
    {
        return $this->recallerEnabled ? parent::recaller() : null;
    }

    private function signedInAtKey(): string
    {
        return $this->getName().'_at';
    }

    private function endOverAgeLogin(): void
    {
        if ($this->absoluteMinutes === null || ! $this->session->has($this->getName())) {
            return;
        }

        $signedInAt = $this->session->get($this->signedInAtKey());

        // A session opened before the stamp existed starts its clock now.
        if (! is_int($signedInAt)) {
            $this->session->put($this->signedInAtKey(), now()->getTimestamp());

            return;
        }

        if (now()->getTimestamp() - $signedInAt > $this->absoluteMinutes * 60) {
            $this->session->forget([$this->getName(), $this->signedInAtKey()]);
        }
    }

    /** With recaller() answering null the framework's own logout no longer clears the cookie. */
    private function forgetLegacyRecaller(): void
    {
        if ($this->recallerEnabled || $this->request === null || ! $this->request->cookies->has($this->getRecallerName())) {
            return;
        }

        $jar = $this->getCookieJar();
        if (! $jar->hasQueued($this->getRecallerName())) {
            $jar->queue($jar->forget($this->getRecallerName()));
        }
    }
}
