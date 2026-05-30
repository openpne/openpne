<?php

namespace App\Compat;

use RuntimeException;

/** Reads the OpenPNE 3 route inventory fixture (database/parity/openpne3-pc-frontend-routes.php). */
final class Openpne3Routes
{
    /** @param array<string, array{fallback?: string, routes: array<string, array{0: string, 1: string}>}> $data */
    public function __construct(private readonly array $data) {}

    public static function default(): self
    {
        return new self(require database_path('parity/openpne3-pc-frontend-routes.php'));
    }

    /** @return list<string> named route names of the module */
    public function routeNames(string $module): array
    {
        return array_keys($this->module($module)['routes']);
    }

    public function url(string $module, string $route): ?string
    {
        return $this->module($module)['routes'][$route][0] ?? null;
    }

    /** @return array{fallback?: string, routes: array<string, array{0: string, 1: string}>} */
    private function module(string $module): array
    {
        return $this->data[$module] ?? throw new RuntimeException("Module `{$module}` not in the OpenPNE 3 route inventory");
    }
}
