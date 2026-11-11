<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

/**
 * The script-restricting Content-Security-Policy a host switches on for every member-realm response
 * the Modern shell did not draw (docs/internals/security.md, "Operator script policy").
 */
final class ClassicScriptPolicy
{
    private bool $active = false;

    private bool $modernShell = false;

    /** @var list<string> */
    private array $formTargets = [];

    /** Resets the per-request state as well: the instance and Vite's nonce outlive a request in a test or a long-lived worker. */
    public function begin(Request $request): void
    {
        $this->modernShell = false;
        $this->formTargets = [];
        $this->active = (bool) config('openpne.security.classic_script_policy') && ! AdminRealm::matches($request);

        if ($this->active) {
            Vite::useCspNonce();
        }
    }

    /** The last shell drawn decides, so a Classic error page that replaces a Modern render still carries the policy. */
    public function shellRendered(bool $modern): void
    {
        $this->modernShell = $modern;
    }

    /** For a form whose POST answers with a redirect off-site: a browser applies form-action to the redirect target too. */
    public function allowFormTarget(string $uri): void
    {
        $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));
        if (preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme) !== 1) {
            return;
        }

        if (! in_array($scheme, ['http', 'https'], true)) {
            $this->formTargets[] = $scheme.':';

            return;
        }

        $host = strtolower((string) parse_url($uri, PHP_URL_HOST));
        if (preg_match('/^(?:[a-z0-9.\-]+|\[[0-9a-f:.]+\])$/', $host) !== 1) {
            return;
        }
        $port = parse_url($uri, PHP_URL_PORT);

        $this->formTargets[] = $scheme.'://'.$host.(is_int($port) ? ':'.$port : '');
    }

    public function nonce(): ?string
    {
        return $this->active ? Vite::cspNonce() : null;
    }

    public function nonceAttribute(): string
    {
        $nonce = $this->nonce();

        return $nonce === null ? '' : 'nonce="'.e($nonce).'"';
    }

    public function header(): ?string
    {
        $nonce = $this->nonce();
        if ($nonce === null || $this->modernShell) {
            return null;
        }

        $formAction = implode(' ', ["'self'", ...array_unique($this->formTargets)]);

        return "script-src 'nonce-{$nonce}' 'strict-dynamic'; object-src 'none'; worker-src blob:; form-action {$formAction}";
    }
}
