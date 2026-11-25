<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\Route;
use Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Laravel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Feature\Auth\PasskeyCredentialLengthTest;
use Tests\TestCase;
use Throwable;

/** A route whose controller takes one of the package's passkey requests gets one whose validation refuses every decoded field past the cap (docs/internals/security.md, "Member passkeys"). */
class PasskeyCredentialLengthSeamTest extends TestCase
{
    public function test_every_route_taking_a_passkey_request_refuses_an_oversized_field(): void
    {
        $fields = array_keys(PasskeyCredentialLengthTest::decodedFields());
        $found = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');
            if (! is_string($action) || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType
                    && (is_a($type->getName(), PasskeyVerificationRequest::class, true) || is_a($type->getName(), PasskeyRegistrationRequest::class, true))) {
                    $found[implode('|', $route->methods()).' '.$route->uri()] = $this->refusedFields($this->resolvedClass($type->getName()), $fields);
                }
            }
        }
        ksort($found);

        $this->assertSame(array_fill_keys(['POST member/config/passkeys', 'POST member/reauth/passkey', 'POST passkeys/login'], $fields), $found);
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

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function refusedFields(string $class, array $fields): array
    {
        $credential = [];
        foreach ($fields as $field) {
            data_set($credential, substr($field, strlen('credential.')), str_repeat('A', PasskeyCredentialLengthTest::MAX + 1));
        }
        $request = $class::create('/', 'POST', ['credential' => $credential]);
        $request->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));

        $validator = (new ReflectionMethod($request, 'getValidatorInstance'))->invoke($request);
        $validator->fails();

        return array_values(array_filter($fields, fn (string $field): bool => isset($validator->failed()[$field]['Max'])));
    }
}
