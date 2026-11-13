<?php

namespace App\Files;

use App\Models\File;
use App\Models\Member;
use App\Session\MemberSessionLifetime;
use App\Support\AdminRealm;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;

/**
 * Signs a variant URL for the signed-in member only where FilePolicy lets that member view the file
 * now, bound to the image cookie this request ends up sending (docs/internals/images.md, "The fast path").
 * Scoped to the request, so a rotation at login and the URLs drawn after it agree on the value.
 */
class VariantUrlSigner
{
    /** @var array{key: string, version: string}|false|null */
    private array|false|null $config = null;

    /** @var array<int|string, bool> */
    private array $allowed = [];

    private ?string $binding = null;

    private bool $queued = false;

    /** Null leaves the URL unsigned, which the application route answers under FilePolicy as before. */
    public function sign(File $file, string $resource, string $version): ?string
    {
        $id = $file->getKey();

        if ($id === null || preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $file->name) !== 1) {
            return null;
        }

        $viewer = $this->viewer();

        if ($viewer === null || ($config = $this->config()) === false || $config['version'] !== $version) {
            return null;
        }

        if (! ($this->allowed[$id] ??= Gate::forUser($viewer)->allows('view', $file))) {
            return null;
        }

        require_once base_path('bootstrap/image-fast-path.php');

        return openpne_image_fast_path_signature($config['key'], $resource, $version, $this->binding());
    }

    /** Every URL signed before this, in any earlier response, stops verifying. */
    public function rotate(): void
    {
        if ($this->config() === false) {
            return;
        }

        $this->binding = bin2hex(random_bytes(32));
        $this->queued = false;
        $this->queue();
    }

    /** Both names, since the one in use follows `session.secure` and a browser may hold the other from before a change. */
    public function forget(): void
    {
        $this->binding = null;
        $this->queued = true;

        foreach ([ImageFastPath::COOKIE => false, ImageFastPath::SECURE_COOKIE => true] as $name => $secure) {
            if (request()->cookies->has($name)) {
                Cookie::queue(new ResponseCookie($name, '', 1, '/', null, $secure, true, false, ResponseCookie::SAMESITE_LAX));
            }
        }
    }

    private function viewer(): ?Member
    {
        if (AdminRealm::matches(request())) {
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

    private function binding(): string
    {
        if ($this->binding === null) {
            $held = request()->cookies->get(ImageFastPath::cookieName());
            $this->binding = is_string($held) && preg_match('/^[0-9a-f]{64}$/D', $held) === 1 ? $held : bin2hex(random_bytes(32));
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
        $minutes = MemberSessionLifetime::memberCookieMinutes(request());
        $secure = (bool) config('session.secure');

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
