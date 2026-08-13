<?php

return [

    /*
    |--------------------------------------------------------------------------
    | File storage backend
    |--------------------------------------------------------------------------
    |
    | Where uploaded file bytes are stored. 'blob' (the default) keeps them in
    | the database `file_bin` table via DbBlobFileStorage, so a whole site is a
    | single DB dump — the OpenPNE 3 heritage layout. Any other value names a
    | disk declared in config/filesystems.php (e.g. 'local', 's3'), served by
    | DiskFileStorage. See App\Providers\FilesServiceProvider.
    |
    */

    'files' => [
        'disk' => env('OPENPNE_FILES_DISK', 'blob'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image thumbnails
    |--------------------------------------------------------------------------
    |
    | Thumbnails are generated on demand (intervention/image) and cached on the
    | 'cache_disk' filesystem disk. 'allowed_sizes' is a whitelist of WxH targets:
    | an unlisted size is rejected (404), so a request cannot drive unbounded
    | thumbnail generation / cache growth. Matches OpenPNE 3's default set.
    |
    */

    'images' => [
        'driver' => env('OPENPNE_IMAGE_DRIVER', 'gd'), // gd (default) | imagick
        'cache_disk' => env('OPENPNE_IMAGE_CACHE_DISK', 'image_cache'),
        'quality' => (int) env('OPENPNE_IMAGE_QUALITY', 85),
        'allowed_sizes' => ['48x48', '76x76', '120x120', '180x180', '240x320', '320x320', '600x600'],
        // Reject uploads larger than this on a side. The decoder allocates
        // width*height*4 bytes, so an unbounded dimension is a decompression-bomb
        // (memory exhaustion) vector even within the file-size limit.
        'max_upload_dimension' => (int) env('OPENPNE_IMAGE_MAX_DIMENSION', 5000),
        // Strip EXIF/GPS (and XMP/comments) from uploaded jpeg/png/webp losslessly at ingestion, so
        // shared photos don't leak location. On by default (privacy); opt out to retain EXIF, e.g. a
        // photography community. See docs/internals/security.md.
        'strip_metadata' => (bool) env('OPENPNE_STRIP_IMAGE_METADATA', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Surface mode (Classic / Modern)
    |--------------------------------------------------------------------------
    |
    | How this install serves the two surfaces (App\Support\SurfaceMode), read via
    | App\Support\SurfaceResolver:
    |
    |   modern_only     Modern only; the Classic surface and its admin / member
    |                   settings are hidden.
    |   classic_default Classic and Modern coexist; an undecided viewer (and the
    |                   root landing) gets Classic — the OpenPNE 3 -> 4 default.
    |   modern_default  Classic and Modern coexist; an undecided viewer gets Modern.
    |
    | This value is only the ABSENT-ROW fallback: sns_settings is the authoritative
    | store (SnsSettingKey::SurfaceMode), not a competing env tier. A fresh install
    | has no row and resolves to this — modern_only, since a new OpenPNE 4 site has no
    | Classic heritage. The OpenPNE 3 -> 4 upgrade instead writes a classic_default row
    | so a migrated site keeps its Classic look. Change a live site with
    | `php artisan openpne:surface-mode`. See docs/internals/classic-compatibility.md.
    |
    */

    'surface_mode' => env('OPENPNE_SURFACE_MODE', 'modern_only'), // modern_only | classic_default | modern_default

    // The diary and timeline web-public switches are admin settings
    // (SnsSettingKey::DiaryAllowWebPublic / TimelineAllowWebPublic), not env flags.

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | 'token_ttl_minutes' is how long an emailed registration link stays valid
    | (OpenPNE 3's default registration-URL lifetime was 24h). Expiry is derived
    | from registration_tokens.created_at against this value.
    |
    */

    'registration' => [
        // Who may create an account is the admin `registration_mode` setting (App\Support\SnsSettingKey),
        // not env: 'invite' (the fail-closed default) and 'closed' both 404 the open /register entry;
        // only 'open' exposes it, behind the CAPTCHA.
        'token_ttl_minutes' => (int) env('OPENPNE_REGISTRATION_TOKEN_TTL_MINUTES', 1440),
        // Minimum seconds between opening the registration form and submitting it; a faster submit is
        // treated as a script and silently dropped. Even with autofill a person takes longer; tune
        // down if it ever rejects real users.
        'min_form_seconds' => (int) env('OPENPNE_REGISTRATION_MIN_FORM_SECONDS', 2),
    ],

    'email_change' => [
        // How long an emailed email-change confirmation link stays valid. Changing the login identifier
        // is a sensitive credential operation, so the default stays within OWASP's "rarely more than an
        // hour" guidance for such links — deliberately not the 24h registration TTL (an
        // onboarding/OpenPNE 3-parity case). Expiry is derived from email_change_requests.created_at
        // against this value.
        'token_ttl_minutes' => (int) env('OPENPNE_EMAIL_CHANGE_TOKEN_TTL_MINUTES', 60),
    ],

    'mfa_reset' => [
        // How long an admin-issued two-factor reset link stays valid. Removing a second factor is a
        // sensitive credential change, so the default stays within OWASP's "rarely more than an hour"
        // guidance for such links. Expiry is derived from mfa_reset_requests.created_at against
        // this value.
        'token_ttl_minutes' => (int) env('OPENPNE_MFA_RESET_TOKEN_TTL_MINUTES', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Password policy
    |--------------------------------------------------------------------------
    |
    | 'blocklist' gates the guessability checks that the length policy layers on
    | (App\Providers\AppServiceProvider): the bundled common-password blocklist
    | and the context-word check (site name / email local part / member name /
    | admin username). It defaults on; a dev environment can opt out. The minimum
    | length and the 72-byte bcrypt cap always apply. See docs/internals/security.md.
    |
    */

    'password' => [
        'blocklist' => (bool) env('OPENPNE_PASSWORD_BLOCKLIST', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Login abuse
    |--------------------------------------------------------------------------
    |
    | After this many failed logins from one IP (within the window) the login form
    | requires the CAPTCHA — a soft escalation, never a lockout, so it cannot be
    | weaponised to lock a member out. Complements the per-(email, IP) login rate
    | limiter (FortifyServiceProvider), which a single IP spraying many addresses
    | slips past. Has no effect when the CAPTCHA is disabled.
    |
    */

    'login' => [
        'captcha_after_failures' => (int) env('OPENPNE_LOGIN_CAPTCHA_AFTER_FAILURES', 5),
        'failure_window_minutes' => (int) env('OPENPNE_LOGIN_FAILURE_WINDOW_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Write-action throttling
    |--------------------------------------------------------------------------
    |
    | Per-minute caps on the content-posting and mail-triggering member writes,
    | applied by the named limiters in App\Providers\AppServiceProvider. Each
    | limiter has two limbs: the per-member cap is the primary control; the
    | per-IP cap (set 2-3x looser) bounds multi-account abuse from one address.
    | Any limb set to 0 is disabled — set the per-IP limb to 0 behind a shared
    | NAT / proxy where many members share an address. The values are
    | deliberately loose: tuning waits until the security event log surfaces 429s.
    |
    */

    'throttle' => [
        'posting' => (int) env('OPENPNE_THROTTLE_POSTING', 30),
        'posting_ip' => (int) env('OPENPNE_THROTTLE_POSTING_IP', 60),
        // The Markdown preview endpoint fires per keystroke-batch, so it is capped looser than a post.
        'preview' => (int) env('OPENPNE_THROTTLE_PREVIEW', 60),
        'preview_ip' => (int) env('OPENPNE_THROTTLE_PREVIEW_IP', 120),
        // The @mention picker is keystroke-driven (debounced) like the preview, so it shares its cap.
        'mention_search' => (int) env('OPENPNE_THROTTLE_MENTION_SEARCH', 60),
        'mention_search_ip' => (int) env('OPENPNE_THROTTLE_MENTION_SEARCH_IP', 120),
        'direct_message' => (int) env('OPENPNE_THROTTLE_DIRECT_MESSAGE', 10),
        'direct_message_ip' => (int) env('OPENPNE_THROTTLE_DIRECT_MESSAGE_IP', 30),
        'friend' => (int) env('OPENPNE_THROTTLE_FRIEND', 15),
        'friend_ip' => (int) env('OPENPNE_THROTTLE_FRIEND_IP', 40),
        'group' => (int) env('OPENPNE_THROTTLE_GROUP', 15),
        'group_ip' => (int) env('OPENPNE_THROTTLE_GROUP_IP', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | CAPTCHA
    |--------------------------------------------------------------------------
    |
    | Bot challenge on the auth entries (OpenPNE 3 shipped one on by default; this
    | is the parity replacement). Whether it is enforced is the admin `captcha_enabled`
    | setting (App\Support\SnsSettingKey, fail-closed default on); the keys below only
    | configure the driver. The default driver is self-hosted ALTCHA proof-of-work
    | (PBKDF2/SHA-256) — no third-party calls, no per-site keys. The HMAC key defaults
    | to one derived from APP_KEY, so a stock install needs no extra secret. cost ×
    | max_number sets the client work; tune for the UX you want.
    |
    */

    'captcha' => [
        'driver' => env('OPENPNE_CAPTCHA_DRIVER', 'altcha'),
        'hmac_key' => env('OPENPNE_CAPTCHA_HMAC_KEY'),
        'altcha' => [
            'cost' => (int) env('OPENPNE_CAPTCHA_ALTCHA_COST', 10000),
            'max_number' => (int) env('OPENPNE_CAPTCHA_ALTCHA_MAX_NUMBER', 100),
            'expires_seconds' => (int) env('OPENPNE_CAPTCHA_ALTCHA_EXPIRES_SECONDS', 600),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Transport security
    |--------------------------------------------------------------------------
    |
    | 'force_https' makes the app generate https:// URLs and mark the session
    | cookie Secure, regardless of how the request reached PHP. It defaults on in
    | production so a deployment behind a TLS-terminating proxy never emits http
    | links or non-secure cookies; a dev/HTTP environment can opt out. Trusting
    | that proxy's forwarded headers is configured separately (TRUSTED_PROXIES,
    | see bootstrap/app.php and docs/internals/runtime.md).
    |
    */

    'security' => [
        'force_https' => (bool) env('OPENPNE_FORCE_HTTPS', env('APP_ENV') === 'production'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTP
    |--------------------------------------------------------------------------
    |
    | Tuning for App\Outbound\SafeHttpFetcher, the single seam through which this
    | app fetches a member-supplied URL. These are limits, not a feature switch:
    | whether anything fetches at all is an admin setting, and nothing here can
    | relax the SSRF guard. See docs/internals/outbound-http.md.
    |
    | The three deadlines nest. A single request may take 'request_timeout'; one
    | fetch (a request plus its redirects) may take 'fetch_timeout'; a whole job
    | (page + oEmbed + image) budgets 'job_timeout'. Without the outer two, a
    | chain of individually-legal slow hops adds up to an unbounded job.
    |
    | 'denied_cidrs' only ever adds to the built-in non-global list — an operator
    | can exclude, say, an internal range that resolves publicly, but cannot
    | re-permit anything the guard rejects.
    |
    | There is deliberately no proxy setting. An HTTP/SOCKS proxy resolves the
    | destination host itself, which bypasses the address the guard validated and
    | pinned; supporting one means making the proxy the enforcement point, which
    | is a different contract than a config value. The fetcher also disables the
    | proxy environment variables libcurl would otherwise honour.
    |
    */

    'outbound' => [
        'request_timeout' => (int) env('OPENPNE_OUTBOUND_REQUEST_TIMEOUT', 8),
        'connect_timeout' => (int) env('OPENPNE_OUTBOUND_CONNECT_TIMEOUT', 3),
        'fetch_timeout' => (int) env('OPENPNE_OUTBOUND_FETCH_TIMEOUT', 10),
        'job_timeout' => (int) env('OPENPNE_OUTBOUND_JOB_TIMEOUT', 20),
        'max_redirects' => (int) env('OPENPNE_OUTBOUND_MAX_REDIRECTS', 3),
        // Read caps, applied to the DECODED byte count: a Content-Length check alone
        // lets a small gzip response expand without bound.
        'max_html_bytes' => (int) env('OPENPNE_OUTBOUND_MAX_HTML_BYTES', 512 * 1024),
        'max_image_bytes' => (int) env('OPENPNE_OUTBOUND_MAX_IMAGE_BYTES', 5 * 1024 * 1024),
        // Total pixels a fetched image may decode to, which is what actually bounds memory: a
        // decoder allocates roughly width * height * 4 bytes, so the per-side limit alone permits
        // 5000 x 5000 = 100 MB and a worker falls over. 4 MP is ~16 MB decoded, comfortably more
        // than any card needs.
        'max_image_pixels' => (int) env('OPENPNE_OUTBOUND_MAX_IMAGE_PIXELS', 4_000_000),
        'denied_cidrs' => array_filter(explode(',', (string) env('OPENPNE_OUTBOUND_DENIED_CIDRS', ''))),
    ],

];
