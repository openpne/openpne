# Security

## Admin two-factor authentication

The Filament admin panel offers **opt-in TOTP** two-factor auth (Filament's
built-in App provider, registered in
[`AdminPanelProvider`](../../app/Providers/Filament/AdminPanelProvider.php)). An
administrator enables it on the **Security** page — reached from the user menu,
next to the theme switch — and is prompted to by a dashboard reminder widget;
the admin-user list shows which administrators have it enabled.

- **Opt-in, not required.** `isRequired` is `false`: MFA is a strong nudge, not
  a gate, to keep the panel easy to try. This is a deliberate deviation from
  NIST SP 800-63B-4's AAL2-for-administrators SHOULD — a blanket requirement is
  impractical for the operator population. The enforcement lever exists as
  Filament's `multiFactorAuthentication(..., isRequired:)` argument; a later PR
  can wire it to a per-site setting (default off).
- **TOTP only.** Email one-time codes are not offered — NIST does not count them
  as an AAL2 authenticator, and admins have no email column anyway.
- **`codeWindow(1)`** (≈±30s / ±1 step) tightens Filament's default of 8 (≈±4
  minutes), which is too lax for a privileged account.
- **Lockout recovery** has two paths: recovery codes (shown once at set-up), and
  the `openpne:admin:disable-mfa <username>` CLI command — gated by server access,
  the same trust boundary as `openpne:admin:reset-password`, since an admin has
  no email for a self-service reset.
- **Session revocation.** Enabling or disabling MFA revokes the admin's other
  sessions (`App\Auth\AdminAppAuthentication` decorates the set-up/disable
  actions, keeping the current session; the CLI revokes all), consistent with a
  password change. Regenerating recovery codes does not revoke — the TOTP factor
  is unchanged.
- **No "remember me."** The admin login drops the remember-me option
  (`App\Filament\Pages\Auth\Login`): a recaller cookie authenticates through the
  guard middleware, which never runs the TOTP challenge, so it would silently
  bypass MFA. Administrators sign in per session.

Filament's default set-up modal text is overridden (`lang/vendor/filament-panels/`)
so it does not deep-link a region-locked (US) App Store URL or name a specific
product; it points to the device's own app-store search instead.

The secret and recovery codes are stored encrypted (APP_KEY); recovery codes are
additionally bcrypt-hashed by Filament before encryption. The MFA challenge in
the login flow is rate-limited by Filament independently of the password login.

## Member two-factor authentication

Members get **opt-in TOTP** two-factor auth through Fortify's two-factor feature
(`config/fortify.php`), in confirm mode: a secret whose set-up was never
confirmed with a valid code is inert at login, so a member cannot lock
themselves out by starting set-up and walking away. The verification window is
1 step (≈±30s), matching the admin panel's `codeWindow(1)`; an accepted code
cannot be replayed inside the window (Fortify caches it). The login challenge
(`/two-factor-challenge`) renders on both surfaces through the same seam as the
other Fortify screens ([`FortifyServiceProvider`](../../app/Providers/FortifyServiceProvider.php)),
and its POST is throttled 5/min per challenged member — not per IP, since the
adversary at this step already holds the password, so the guess budget must
not scale with attacker IPs; the GET render is deliberately unthrottled so a
refresh cannot burn the guess budget.

Differences from the admin posture, all deliberate:

- **"Remember me" stays available.** Fortify only mints the remember cookie
  *after* the challenge succeeds, so a recaller is always evidence of a full
  password + TOTP login — unlike Filament, whose recaller path would bypass the
  challenge (why the admin login dropped it). Enabling or disabling MFA rotates
  `remember_token`, so recallers minted before MFA was enabled stop working.
- **Recovery codes are encrypted but recoverable** (Fortify standard: a used
  code is compared in plaintext), not bcrypt-hashed like the admin's. They are
  displayed only right after confirmation or regeneration and are never stored
  in the session; that display is a server-side one-shot, but a browser may
  re-show the page from its own history state — accepted, since the codes sit
  behind an authenticated session and remain regenerable. A used code is
  deleted rather than silently swapped for a fresh one (Fortify's default,
  overridden in `Member::replaceRecoveryCode`): under show-once display the
  member could never learn the replacement, so swapping would hold the unused
  count at a phantom maximum while their saved codes dwindle.

**Management endpoints are this app's own** (member settings), not Fortify's:
[`FortifyServiceProvider`](../../app/Providers/FortifyServiceProvider.php) calls
`Fortify::ignoreRoutes()` and `routes/web.php` re-declares only the routes the
app uses (login, logout, password reset, the two-factor challenge — names,
methods and middleware pinned by `FortifyRoutesTest`). Fortify's
`/user/two-factor-*` endpoints never ship: they would allow enabling/disabling
the factor without the app's re-auth and revocation contract:

- **Re-auth is per flow, not per step** (the sudo-mode convention). Enabling
  verifies the account password and opens a short re-auth window
  ([`MfaSetupReauth`](../../app/Features/Member/MfaSetupReauth.php)); confirming
  inside the window needs only the TOTP code, after it the password again.
  Disabling a live factor and regenerating recovery codes always re-authenticate
  inline; cancelling an inert pending set-up never does (it gates nothing).
- **Live-factor changes revoke**: confirm and disabling a live factor revoke
  the member's other sessions and rotate `remember_token` in the same
  transaction. Regenerating recovery codes revokes nothing (the factor is
  unchanged), and neither does cancelling a pending set-up — it is
  password-free, so it must also stay side-effect-free.

**Lockout recovery**: recovery codes, or `openpne:member:disable-mfa <email>` —
server access as the trust boundary, same as the admin command; the member's
self-service password reset cannot remove a lost second factor.

Accepted residual: a pending (unconfirmed) set-up QR is visible to whoever
holds the member's session, and inside the enable re-auth window it could even
be confirmed without the password — bounded by the window's length, and until
confirmed the pending secret gates nothing. Outside the window every
security-relevant action requires the account password, and restarting set-up
mints a fresh secret.

There is no enforcement lever yet ("this site requires MFA"); the natural
insertion point is a post-login check on `hasEnabledTwoFactorAuthentication()`,
kept open by design.

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
- **Commonly-used-password blocklist** — NIST SP 800-63B-4 §3.1.1.2 SHALL,
  ASVS 5.0 6.2.4 (L1). Rejected case-insensitively against a bundled offline
  list (no external service): a SecLists-derived top 100,000 of entries ≥8
  characters, provenance and regeneration in
  [`resources/data/README.md`](../../resources/data/README.md)
  (`App\Rules\NotCommonPassword`).
- **Context words** — ASVS 5.0 6.2.4. Rejects a password that contains, case-
  insensitively, a ≥4-character subtoken of the site name, the member's name or
  email local part, or the admin username (`App\Rules\NotContextWord`). Context
  is resolved best-effort — anything unresolvable (e.g. no schema mid-install)
  is skipped rather than blocking. Accepted trade-off: a member whose name is a
  common ≥4-character word cannot embed it in a password.

Both guessability checks are gated by `OPENPNE_PASSWORD_BLOCKLIST` (default on);
disabling it drops only these two — the minimum length and the byte cap always
apply. The blocklist is never fetched at build or in CI; its regeneration policy
lives with the data (see the README above).

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

## Write rate limits

Content-posting and mail-triggering member writes carry named per-minute limiters
([`AppServiceProvider`](../../app/Providers/AppServiceProvider.php)), attached per route in
`routes/web.php` and pinned by `WriteThrottleRoutesTest`. Each has two limbs: a per-member cap
(primary) and a looser per-IP cap that bounds multi-account abuse from one address.

| Limiter | Default (member / IP per min) | Key shape | Routes |
|---|---|---|---|
| `posting` | 30 / 60 | member id / client IP | diary, community topic and event create + update, their comment posts, timeline post + reply (11) |
| `message-send` | 10 / 30 | member id / client IP | message compose send, draft-edit send (2) |
| `friend-request` | 15 / 40 | member id / client IP | friend link request, accept (2) |
| `community-join` | 15 / 40 | member id / client IP | community join, member approve, member decline (3) |

The defaults are deliberately loose: tuning waits until the security event log gives 429
observability. Env overrides (`OPENPNE_THROTTLE_*`, `0` disables that limb) exist for shared-NAT /
proxy deployments where the per-IP limb should be relaxed or turned off. A throttled request renders
the framework default 429 page for now (no custom surface).

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
