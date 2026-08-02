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
  Filament's `multiFactorAuthentication(..., isRequired:)` argument; it is not
  wired to any setting.
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
- **Inline re-authentication (sudo mode).** All three management actions also
  require the account password, on top of the code requirements above: set-up
  keeps the new secret's TOTP proof, disable keeps code-or-recovery, and
  regenerate — which used to accept a code **or** the password — now needs the
  password **and** a current code (`App\Auth\AdminMfaPasswordReauth`). Password-
  only regeneration would let an adversary holding the password and a hijacked
  session mint fresh recovery codes that bypass the TOTP login challenge. A
  walked-up unlocked session likewise can no longer enroll its own authenticator
  — which would also have revoked the admin's other sessions, so a password-free
  operation must stay side-effect-free. There is no re-auth window: each flow is
  a single modal, so the password is asked exactly once per action. The check is
  throttled by a dedicated per-admin limiter (5/min, shared across the three
  modals) because the set-up wizard's per-step validation bypasses Filament's
  action rate limit; and a wrong password never consumes a submitted recovery
  code (it fails fast before the vendor rule spends the code). Recovering a lost
  authenticator for regeneration means disabling with the password and a
  recovery code, then re-enrolling.
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

- **Set-up re-auth spans requests, so it uses a window.** Member set-up runs
  across several requests (enable, scan, confirm), so it verifies the password
  once and opens a short window (below) rather than re-asking per step. The
  admin's set-up is a single modal, so it demands the password **and** a code
  inline with no window. Disabling and regenerating match the admin posture
  directly: the account password **and** a second-factor proof, inline, every time.
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

- **Re-auth is per flow and demands a second factor.** Enabling verifies the
  account password and opens a short re-auth window
  ([`MfaSetupReauth`](../../app/Features/Member/MfaSetupReauth.php)); confirming
  inside the window needs only the TOTP code, after it the password again.
  Disabling a live factor and regenerating recovery codes demand the account
  password **and** a second-factor proof inline every time — a current TOTP code,
  or (disable only) an unused recovery code. Cancelling an inert pending set-up
  demands neither (it gates nothing). The ordering is structural: the FormRequest
  verifies only the password and the proof's *presence*, and the feature Action
  verifies the proof's value only after that password rule has passed — so a wrong
  password never marks a TOTP code used in Fortify's replay cache nor spends a
  recovery code. A disable that spends a recovery code logs
  `mfa.recovery_code_used`, deferred until the transaction commits so a rollback
  records nothing.
- **State is re-derived — and mutated — under a row lock.** Every state-dependent
  action re-reads the member `FOR UPDATE` inside its transaction, re-checks the
  state the FormRequest validated against (required-ness was decided on a
  pre-controller snapshot), and hands that locked row to the Fortify mutation —
  whose own internal state guard would otherwise silently no-op against a stale
  instance. A concurrent change that invalidated the snapshot — a set-up
  confirmed, a factor disabled — fails closed, rather than removing a now-live
  factor with no proof or minting recovery codes for a factor a parallel disable
  just removed.
- **Live-factor changes revoke**: confirm and disabling a live factor revoke
  the member's other sessions and rotate `remember_token` in the same
  transaction. Regenerating recovery codes revokes nothing (the factor is
  unchanged), and neither does cancelling a pending set-up — being password- and
  proof-free, it must also stay side-effect-free. "Free" means no credentials and
  no mutation, not exempt from throttling: all four management POSTs share one
  5/min-per-member budget (the `mfa-manage` limiter, keyed by member for the same
  reason as the challenge), so a cancel still draws from it; the GET render does not.

**Lockout recovery** has three layers, in order of escalation:

1. **Recovery codes** — shown once at set-up; a member who kept them signs in and
   disables the factor themselves.
2. **Admin-issued reset link** — a site admin mails the member's registered
   address a time-limited link (the *Send 2FA reset link* member action). The
   member opens it as a guest and clears the factor by entering their **account
   password** (`App\Features\Member\MfaResetLinkController`, `ConsumeMfaReset`).
   This is the delegable path: member support is the site admin's job, and the
   admin CLI is not offered to admins.
3. **Operator CLI** — `openpne:member:disable-mfa <email>`, server access as the
   trust boundary (same as the admin command), for when the registered address is
   also lost. The self-service password reset cannot remove a lost second factor.

A pending reset link dies on factor removal, re-enrollment, recovery-code
regeneration, or a completed email change, and survives **only** a password change
(the proof it demands *is* the current password) — see the invalidation contract
below.

**Reset-link boundary invariant** (the reason the admin path is safe against a
malicious or hijacked admin, T2/T3): issuing a link gives the admin panel **no
direct account-takeover ability**. The link is delivered only to the member's
registered mailbox, and consuming it requires the member's own account password —
both evidence outside the admin's reach. So the action has no primary-member gate
(there is nothing to seize) and no ban gate (a ban is enforced at login; recovery
and moderation are orthogonal). Display-only delivery, admin editing of the
address, and one-time access codes were all considered and rejected — each would
hand the admin a takeover primitive. When the registered address itself is dead,
the answer is the operator CLI, or a fresh account plus an admin withdrawal of the
old one — never a seam that lets the admin redirect the proof.

**Reset-link invalidation contract**: a link proves the factor and the registered
address that existed when it was issued, so it must die once that ground truth
moves. (a) **A factor's lifecycle drops the pending row** — its removal
(`ForceDisableMemberMfa` for the CLI + link flow, the step-up `DisableMemberMfa`),
its re-enrollment (`EnableMemberMfa` / `ConfirmMemberMfa`, defense-in-depth), and a
recovery-code regeneration (`RegenerateMemberRecoveryCodes` — regenerating proves
current authenticator possession, so an outstanding lost-factor link is moot) each
delete it inside their transaction, closing "send → disable → re-enable within the
TTL → old link now valid against the new factor". (b) **A completed email change
voids it** (`ConfirmEmailChange`) — the address is the proof channel, the same
compensating-control shape as a password change voiding a pending email change. A
password change alone does **not** void it: the proof it still demands is the
current password. GET renders only (a mail scanner / prefetch must not consume the
token or clear a factor); the reset is the password-gated POST, per-token
rate-limited (`mfa-reset`) so distributed guessing cannot pool onto one link. The
whole global lock order is Member → `mfa_reset_requests`.

Accepted residual: a pending (unconfirmed) set-up QR is visible to whoever
holds the member's session, and inside the enable re-auth window it could even
be confirmed without the password — bounded by the window's length, and until
confirmed the pending secret gates nothing. Outside the window every
security-relevant action requires the account password, and restarting set-up
mints a fresh secret.

There is no site-wide enforcement setting ("this site requires MFA"): enabling
two-factor is always the member's own choice.

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
| `posting` | 30 / 60 | member id / client IP | diary, community topic and event create + update, their comment posts, timeline post + reply |
| `message-send` | 10 / 30 | member id / client IP | message compose send, draft-edit send |
| `friend-request` | 15 / 40 | member id / client IP | friend link request, accept |
| `community-join` | 15 / 40 | member id / client IP | community join, member approve, member decline |

The defaults are deliberately loose: tuning draws on the 429 observability the security event log
now provides — every throttled request logs a `throttle.hit` event (route + member, never the
limiter key). Env overrides (`OPENPNE_THROTTLE_*`, `0` disables that limb) exist for shared-NAT /
proxy deployments where the per-IP limb should be relaxed or turned off. A throttled request renders
the framework default 429 page.

Authentication and credential-mutation events (login, MFA, password/email change, ban, withdrawal)
are recorded on a dedicated `security` log channel — the event vocabulary, PII/injection contract,
and retention are in [logging](logging.md).

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

- **A content CSP (`script-src`)** — the Vite/Inertia bundle has no nonce/hash
  wiring, and the absence is also what lets the panel's inline Livewire/Alpine
  scripts run.
- **`Cross-Origin-Resource-Policy`** — web-public bytes (avatars, banners, and
  the images of a web-public diary or timeline post) are served for cross-origin
  embedding, which `same-origin` would break.

## Uploaded image metadata

Files are delivered as original bytes (above), so an uploaded photo's EXIF —
GPS coordinates included — would reach every viewer. Every upload funnels
through [`FileUploader`](../../app/Files/FileUploader.php), which strips
metadata from `image/jpeg`, `image/png` and `image/webp` before `byte_size` is
captured, via [`ImageMetadataStripper`](../../app/Files/ImageMetadataStripper.php).
The strip is lossless — it rewrites the container, never re-encoding the image
data — so there is no quality loss.

Per format:

- **JPEG** — an allow-list segment walk (SOI→EOI). Structural markers are kept;
  among the APP segments only JFIF/JFXX (APP0), ICC (APP2) and Adobe (APP14,
  the CMYK/YCCK transform flag) survive. EXIF (APP1), XMP and every other APPn,
  plus comments (COM), are dropped — including markers placed between scans of a
  progressive JPEG.
- **PNG** — a chunk walk dropping `eXIf`/`tEXt`/`zTXt`/`iTXt` (XMP rides
  `iTXt`); `iCCP`/`gAMA` and all image chunks are kept. Each chunk's CRC is
  verified while walking.
- **WebP** — a RIFF walk dropping the `EXIF` and `XMP ` chunks and clearing only
  the EXIF/XMP flag bits of a `VP8X` chunk (ICC/alpha/animation left intact).

Color-critical segments (ICC/Adobe) are deliberately kept so stripping never
shifts colors. **EXIF Orientation** is preserved: it is read before stripping
and re-emitted as a minimal one-tag APP1 after SOI, because both original
display and thumbnail generation ([`ImageCache`](../../app/Files/ImageCache.php),
intervention/image auto-orient) rotate from it. Thumbnail rotation needs
`ext-exif` at runtime — intervention reads Orientation only when
`exif_read_data` exists and silently skips rotation otherwise (the stripper
itself parses TIFF by hand and does not need the extension).

Accepted residuals: GIF passes through untouched (no standard geo metadata), and
WebP loses Orientation (EXIF-bearing camera WebP is effectively nonexistent).

The strip **fails closed** — a structurally unparseable image throws rather than
storing the original bytes (upstream validation already cleared magic bytes and
dimensions, so an unparseable container is corrupt or adversarial, and a privacy
control must not silently pass it through). The upload paths convert this to an
inline form-validation error, never a 500.

Toggle with `OPENPNE_STRIP_IMAGE_METADATA` (default on); turn it off to retain
EXIF (e.g. a photography community). OpenPNE 3-imported files bypass this
pipeline (a table-level move, not a re-upload) and are not stripped.

## Cookies

When `session.secure` is on (explicit `SESSION_SECURE_COOKIE`, or `force_https`),
the two realm session cookies are renamed with the `__Secure-` prefix
([`UseAdminSessionStore`](../../app/Http/Middleware/UseAdminSessionStore.php)),
which the browser accepts only over HTTPS with the Secure attribute. A
plain-HTTP host stays unprefixed so login still works. The `XSRF-TOKEN` cookie
is read from JS by name and the remember-me cookies are guard-named, so neither
takes a prefix — they carry the Secure flag from `session.secure` but not the
prefix. The prefix requirement is met for the session cookies only.
