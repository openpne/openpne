# Security

## Password policy

The policy has a single definition — `Password::defaults()` in
[`AppServiceProvider`](../../app/Providers/AppServiceProvider.php) — and every
password-accepting path (member registration, password reset and change, the
admin panel forms, the admin CLI commands) validates through `Password::default()`.

- **Minimum 8 characters.** This meets ASVS 5.0 V6.2.1 (level 1) and matches
  the de-facto floor of large consumer services (large-scale measurement:
  S. Alroomi & F. Li, *Measuring Website Password Creation Policies At Scale*,
  ACM CCS 2023). NIST SP 800-63B-4 §3.1.1.2 requires 15 characters for
  single-factor password authentication; this application deviates knowingly —
  for an SNS whose members are invited casual users, a 15-character floor
  drives lockouts and support load out of proportion to its benefit.
- **Maximum 72 bytes** — bytes, not characters; a multibyte character counts at
  its encoded width. bcrypt reads nothing past its 72nd input byte, so two
  longer secrets sharing a 72-byte prefix would verify as the same password.
  The framework `max:` rule counts characters and cannot express this
  (`App\Rules\MaxBytes`).
- **No composition rules** (required symbol/case classes) — NIST SP 800-63B-4
  says verifiers SHALL NOT impose them.

Login response time does not reveal whether an account exists: every
credential rejection that skips hash verification (unknown email/username,
passwordless row, unrecognised stored hash) burns an equivalent bcrypt first
(ASVS 5.0 V6.3.8). Hashing cost is `BCRYPT_ROUNDS` (`config/hashing.php`),
default 12. Known residual: an account imported from OpenPNE 3 that has not
logged in yet verifies at the import-time wrap cost
([`PasswordWrap`](../../app/Upgrade/Runner/PasswordWrap.php), lower than the
default), so a wrong-password probe that comes back fast is a one-sided oracle
— it identifies a still-wrapped imported account, revealing both that it exists
and that it has not logged in since the migration. A slow response stays
ambiguous (unknown account, or one already on the default cost). The signal
disappears on the account's first login and is accepted for the migration
window.

## Response headers

[`SecurityHeaders`](../../app/Http/Middleware/SecurityHeaders.php) sets the
same baseline on every response — `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, a `frame-ancestors 'none'; base-uri 'self'` CSP,
`Permissions-Policy: camera=(), microphone=(), geolocation=()`,
`Cross-Origin-Opener-Policy: same-origin`, and (under `force_https`) HSTS. It
is registered in the `web` group **and** on the Filament panel's own stack:
the panel does not inherit the `web` group, so the admin pages — the
highest-value clickjacking target — would otherwise ship none of these.

Deliberately not set:

- **A content CSP (`script-src`)** — deferred until the Vite/Inertia bundle
  gets nonce/hash wiring. Its absence is also what lets the panel's inline
  Livewire/Alpine scripts run.
- **`Cross-Origin-Resource-Policy`** — web-public avatars and banners are
  served for cross-origin embedding, which `same-origin` would break.

## Cookies

When `session.secure` is on (explicit `SESSION_SECURE_COOKIE`, or `force_https`),
the two realm session cookies are renamed with the `__Secure-` prefix
([`UseAdminSessionStore`](../../app/Http/Middleware/UseAdminSessionStore.php)),
which the browser accepts only over HTTPS with the Secure attribute. A
plain-HTTP host stays unprefixed so login still works. Not yet covered: the
`XSRF-TOKEN` cookie is read from JS by name and the remember-me cookies are
guard-named, so neither takes the prefix — they carry the Secure flag from
`session.secure` but not the prefix, which the `__Host-` follow-up would
tighten. So this satisfies the prefix requirement for the session cookies, not
yet for every authentication cookie.
