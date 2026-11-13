<?php

namespace App\Files;

use App\Models\File;
use App\Models\Member;
use App\Session\MemberSessionLifetime;
use App\Support\AdminRealm;
use App\Support\ViewerRelations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;

/**
 * Signs a variant URL for the signed-in member only where FilePolicy lets that member view the file
 * now, bound to a value the member's session holds and the image cookie carries (docs/internals/images.md, "The fast path").
 * Scoped to the request, so a rotation at login and the URLs drawn after it agree on the value.
 */
class VariantUrlSigner
{
    private const SESSION_KEY = 'image_fast_path.binding';

    /** @var array{key: string, version: string}|false|null */
    private array|false|null $config = null;

    /** @var array<string, bool> by "viewer:file" */
    private array $allowed = [];

    private ?string $binding = null;

    private bool $queued = false;

    private ?bool $adminRealm = null;

    /** Null leaves the URL unsigned, which the application route answers under FilePolicy as before. */
    public function sign(File $file, string $resource, string $version): ?string
    {
        $id = $file->getKey();

        if ($id === null || preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $file->name) !== 1) {
            return null;
        }

        $viewer = $this->viewer();

        if ($viewer === null || ! request()->hasSession() || ($config = $this->config()) === false || $config['version'] !== $version) {
            return null;
        }

        // Keyed by the viewer too: the memo must never answer for a member it did not ask about.
        $pair = $viewer->getKey().':'.$id;

        if (! isset($this->allowed[$pair])) {
            // One read for the page: the policy asks whether each picture's owner blocks the viewer.
            app(ViewerRelations::class)->warmEveryBlockOf($viewer);
            $this->allowed[$pair] = Gate::forUser($viewer)->allows('view', $file);
        }

        if (! $this->allowed[$pair]) {
            return null;
        }

        require_once base_path('bootstrap/image-fast-path.php');

        return openpne_image_fast_path_signature($config['key'], $resource, $version, $this->binding());
    }

    /**
     * Every URL signed before this, in any earlier response, stops verifying. Unconditional: a value
     * kept while the fast path was off would verify the previous member's URLs once it is on again.
     */
    public function rotate(): void
    {
        if (! request()->hasSession()) {
            return;
        }

        $this->binding = bin2hex(random_bytes(32));
        request()->session()->put(self::SESSION_KEY, $this->binding);
        $this->queued = false;
        $this->queue();
    }

    /** Both names, since the one in use follows `session.secure` and a browser may hold the other from before a change. */
    public function forget(): void
    {
        $this->binding = null;
        $this->queued = true;

        if (request()->hasSession()) {
            request()->session()->forget(self::SESSION_KEY);
        }

        foreach ([ImageFastPath::COOKIE => false, ImageFastPath::SECURE_COOKIE => true] as $name => $secure) {
            if (request()->cookies->has($name)) {
                Cookie::queue(new ResponseCookie($name, '', 1, '/', null, $secure, true, false, ResponseCookie::SAMESITE_LAX));
            }
        }
    }

    private function viewer(): ?Member
    {
        // The realm is the request's and cannot change under it; the member can, at a login or a logout.
        if ($this->adminRealm ??= AdminRealm::matches(request())) {
            return null;
        }

        $viewer = Auth::guard('member')->user();

        return $viewer instanceof Member ? $viewer : null;
    }

    /** @return array{key: string, version: string}|false */
    private function config(): array|false
    {
        if ($this->config === null) {
            $desired = ImageFastPath::sync() ? ImageFastPath::desired() : null;
            $this->config = $desired === null ? false : ['key' => $desired['key'], 'version' => $desired['version']];
        }

        return $this->config;
    }

    /**
     * The value is the session's, never the request cookie's: without the `__Host-` prefix a sibling
     * host can plant a cookie of this name, and a URL signed for a planted value is one its planter can fetch.
     */
    private function binding(): string
    {
        if ($this->binding === null) {
            $held = request()->session()->get(self::SESSION_KEY);

            if (! is_string($held) || preg_match('/^[0-9a-f]{64}$/D', $held) !== 1) {
                $held = bin2hex(random_bytes(32));
                request()->session()->put(self::SESSION_KEY, $held);
            }

            $this->binding = $held;
        }

        $this->queue();

        return $this->binding;
    }

    /** Sent with every response that signed something, so the cookie's expiry slides as the session cookie's does. */
    private function queue(): void
    {
        if ($this->queued || $this->binding === null) {
            return;
        }

        $this->queued = true;
        $secure = (bool) config('session.secure');
        // The session cookie's own rule (App\Http\Middleware\StartSession), so neither outlives the other across a browser restart.
        $minutes = MemberSessionLifetime::memberCookieMinutes(request())
            ?? (config('session.expire_on_close') ? null : (int) config('session.lifetime'));

        // Built by hand: the cookie jar would fill in `session.domain`, and this cookie is host-only.
        Cookie::queue(new ResponseCookie(
            ImageFastPath::cookieName(),
            $this->binding,
            $minutes === null ? 0 : now()->addMinutes($minutes)->getTimestamp(),
            '/',
            null,
            $secure,
            true,
            false,
            ResponseCookie::SAMESITE_LAX,
        ));
    }
}
