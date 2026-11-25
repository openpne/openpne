<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Http\Requests\Concerns\LimitsCredentialLength;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;
use Throwable;

/** A route that reads a posted passkey credential resolves a request that caps it before the package decodes it (docs/internals/security.md, "Member passkeys"). */
class PasskeyCredentialLengthSeamTest extends TestCase
{
    public function test_every_route_that_reads_a_credential_caps_it(): void
    {
        $capped = [];
        $uncapped = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();
                if (! $type instanceof ReflectionNamedType
                    || ! (is_a($type->getName(), PasskeyVerificationRequest::class, true) || is_a($type->getName(), PasskeyRegistrationRequest::class, true))) {
                    continue;
                }
                if (in_array(LimitsCredentialLength::class, class_uses_recursive($this->resolvedClass($type->getName())), true)) {
                    $capped[] = $route->uri();
                } else {
                    $uncapped[] = $route->uri();
                }
            }
        }
        sort($capped);

        $this->assertSame([], $uncapped);
        $this->assertSame(['member/config/passkeys', 'member/reauth/passkey', 'passkeys/login'], $capped);
    }

    /** The container may bind a type-hinted package request to the app's subclass, so the class is read off the resolved instance. */
    private function resolvedClass(string $type): string
    {
        $resolved = $type;
        $this->app->resolving($type, function (object $request) use (&$resolved): void {
            $resolved = $request::class;
        });

        try {
            $this->app->make($type);
        } catch (Throwable) {
            // Resolving validates against an empty request, which fails after the callback ran.
        }

        return $resolved;
    }
}
