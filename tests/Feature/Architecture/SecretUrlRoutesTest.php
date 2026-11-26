<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Http\Middleware\NoReferrer;
use App\Http\Middleware\NoStore;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** See docs/internals/sessions.md, "The previous URL". */
class SecretUrlRoutesTest extends TestCase
{
    /** @var array<string, string> route name => why its {token} is no secret */
    private const NOT_SECRET = [
        'app_icon' => 'the public file name of the uploaded site icon, served as an image',
        'member.config.ai.tokens.destroy' => 'the id of the personal access token to revoke, behind auth',
    ];

    /** @var array<string, string> route name => where its secret is, since no {token} names it */
    private const SECRET_ELSEWHERE = [
        'password.update' => 'posts the link token, and a refused submit redirects to the URL that carries it',
        'livewire.preview-file' => 'the signature in its query reads an uploaded file',
    ];

    public function test_every_route_with_a_secret_in_its_url_carries_no_store_and_no_referrer(): void
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $name = (string) $route->getName();
            if (! in_array('token', $route->parameterNames(), true) && ! array_key_exists($name, self::SECRET_ELSEWHERE)) {
                continue;
            }

            $found[] = $name;
            if (array_key_exists($name, self::NOT_SECRET)) {
                continue;
            }

            $middleware = app('router')->gatherRouteMiddleware($route);
            $this->assertContains(NoStore::class, $middleware, "[{$name}] puts a secret in its URL but lacks NoStore.");
            $this->assertContains(NoReferrer::class, $middleware, "[{$name}] puts a secret in its URL but lacks NoReferrer.");
        }

        foreach (['register.form', 'password.reset', 'member.config.email.confirm', 'member.config.email.cancel', 'member.mfa.reset', 'admin.password_setup'] as $known) {
            $this->assertContains($known, $found, "[{$known}] no longer names its parameter {token}, so this sweep would skip it.");
        }

        foreach ([...array_keys(self::NOT_SECRET), ...array_keys(self::SECRET_ELSEWHERE)] as $listed) {
            $this->assertContains($listed, $found, "the entry for [{$listed}] is stale.");
        }
    }
}
