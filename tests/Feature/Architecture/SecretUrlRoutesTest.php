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
    ];

    public function test_every_page_with_a_token_in_its_url_carries_no_store_and_no_referrer(): void
    {
        $found = [];
        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            if (! in_array('GET', $route->methods(), true) || ! in_array('token', $route->parameterNames(), true)) {
                continue;
            }

            $name = (string) $route->getName();
            $found[] = $name;
            if (array_key_exists($name, self::NOT_SECRET)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            $this->assertContains(NoStore::class, $middleware, "[{$name}] puts a token in its URL but lacks NoStore.");
            $this->assertContains(NoReferrer::class, $middleware, "[{$name}] puts a token in its URL but lacks NoReferrer.");
        }

        foreach (['register.form', 'password.reset', 'member.config.email.confirm', 'member.config.email.cancel', 'member.mfa.reset', 'admin.password_setup'] as $known) {
            $this->assertContains($known, $found, "[{$known}] no longer names its parameter {token}, so this sweep would skip it.");
        }

        foreach (array_keys(self::NOT_SECRET) as $exempt) {
            $this->assertContains($exempt, $found, "the exemption for [{$exempt}] is stale.");
        }
    }
}
