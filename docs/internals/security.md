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
- **No code replay.** Filament records the last accepted step per secret, so the
  login challenge, disable and regenerate reject any code that is not newer than
  it — a code used to sign in cannot then authorize factor management. Set-up is
  exempt: it proves a secret that is not live yet.
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
  keeps the new secret's TOTP proof (the password sits on the same wizard step
  as the code), disable keeps code-or-recovery, and regenerate — whose vendor
  code field is only required *without* a password — needs the password **and**
  a current code (`App\Auth\AdminAppAuthentication` re-marks the code required).
  Password-only regeneration would let an adversary holding the password and a
  hijacked session mint fresh recovery codes that bypass the TOTP login
  challenge. A walked-up unlocked session likewise cannot enroll its own
  authenticator — which would also have revoked the admin's other sessions, so a
  password-free operation must stay side-effect-free. It can read the new
  secret, which the wizard shows before the password is checked; that secret is
  saved only if the same open wizard is then finished with the password, so the
  residual risk is an administrator completing a set-up modal someone else
  opened. There is no re-auth
  window: each flow is a single modal, so the password is asked exactly once per
  action. The password field is Filament's own (`current_password:admin`, a
  `Hash::check` that is sound only because of the no-remember-me rule below),
  but its check runs through `App\Auth\AdminMfaPasswordReauth::gate`, installed
  ahead of Filament's own closure in the actions' `beforeFormValidated` slot
  and in place of it in the set-up step's `beforeValidation` slot. The gate
  throttles password guesses on a per-admin key (5/min, shared across the three
  modals, hit only on a wrong password, cleared on success); Filament's limiter
  then still counts every submit that passed the gate (5/min per action), which
  is what bounds code guesses by someone who already holds the password. The
  set-up step keeps only the gate: Filament's step limiter counts every attempt,
  so a few mistyped codes would lock a legitimate enrolment, and a code guess
  there is worthless with the secret on screen. The gate exists at all because
  the wizard's per-step validation bypasses Filament's action rate limit. It
  throws, so a wrong password never consumes a submitted recovery code (the
  vendor rule that spends the code is never reached). Recovering a lost
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
  challenge (why the admin login dropped it). The mailed-code challenge
  ("Member passkeys") mints it at the same point. Enabling or disabling MFA rotates
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

- **Re-auth is per flow and demands a second factor**
  ([Member re-authentication](#member-re-authentication)). Enabling opens a
  short window ([`MfaSetupReauth`](../../app/Features/Member/MfaSetupReauth.php));
  confirming inside the window needs only the pending factor's code, after it
  the password again. Disabling a live factor and regenerating recovery codes
  ask inline every time; regenerating takes a current TOTP code only, never a
  recovery code. Cancelling an inert pending set-up demands nothing (it gates
  nothing). A disable that spends a recovery code logs
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

## Member passkeys

Members can register WebAuthn passkeys as a sign-in method beside the
password. The engine is `laravel/passkeys` (shipped with Fortify) on
`web-auth/webauthn-lib`; this app adds no WebAuthn parsing of its own. The
package defaults are kept: discoverable credentials (sign-in needs no
username), **user verification required**, attestation `none`. Fortify's
provider points the package at `App\Models\Member` and suppresses its routes;
the app declares its own (`routes/web.php`), as with two-factor. The package
names the owner column `passkeys.user_id` in its model, actions and requests,
while its trait would infer `member_id` — `Member::passkeys()` pins `user_id`.

- **A passkey is both factors.** The relying party requires user verification
  and webauthn-lib rejects an assertion whose UV flag is unset, so a passkey
  proves possession of the authenticator plus its local unlock (biometric or
  PIN), a multi-factor cryptographic authenticator in NIST SP 800-63B's terms.
  Signing in with one therefore skips the TOTP challenge; it is a sign-in
  method, not a second factor, and lives as its own settings row above the
  password.
- **A passkey holder's password needs a second proof.** Once a member holds a
  passkey, a correct password alone no longer signs them in
  (`App\Auth\RequireSecondLoginProof`, bound over Fortify's two-factor pipeline
  step, so it runs only while that feature is on): a confirmed TOTP factor
  takes the TOTP challenge as before, and otherwise a six-digit code is mailed
  to the registered address and asked for at `/login/code`
  (`App\Features\Auth\LoginCode`). The branch is taken after the password
  verified, so the login form does not reveal who holds a passkey. The pending
  challenge lives in its own session slot (`login.code`, never Fortify's
  `login.id`), holding HMACs of the code, of the password hash and of the
  address, and a per-issue nonce. A code lasts 10 minutes and is replaced by a
  resend; entering it and resending it are both refused once the password or
  the address changed, the member was banned, or a TOTP factor was confirmed
  since. The challenge's three routes are serialised per session (route
  `block()`), since each request writes the whole session back. Wrong codes are counted in the cache per nonce, not in the
  session — a counter there rewinds with a replayed cookie — and the fifth
  ends the challenge.
  A member is mailed at most five codes an hour; the POST is throttled 5/min
  per challenged member (`login-code`) and the resend only by that hourly
  budget. The mail is queued encrypted, so the code is not readable in `jobs`.
  Accepted residuals: a code can be relayed in real time by a phishing proxy,
  so the floor this raises the password path to is the mailbox, not the
  passkey; whoever holds the password can spend the hourly budget and stall
  the password path (the passkey still signs in, and each mail is an alarm);
  a queued mail can arrive after the code that replaced it, or after its own
  code expired; a same-session request outside those three routes can write a
  replaced slot back, which stays inside that nonce's attempt cap and expiry.
- **Sign-in is the package's own route pair** (`/passkeys/login/options`,
  `/passkeys/login`, `guest:member`), which verifies the assertion and calls
  the guard directly — it never enters Fortify's login pipeline, so the ban
  (`is_login_rejected`) and AI-account refusals that `AuthenticateMember`
  applies are re-applied in `Passkeys::authorizeLoginUsing()`
  (`FortifyServiceProvider`). The package verifies before it asks, so a
  refused member's attempt still records `passkey.verified` and advances the
  passkey's `last_used_at`, and the refusal is logged (`passkey.refused`);
  an assertion the ceremony itself refused logs `passkey.failed`, the
  counterpart of the password path's `login.failed` (a body too malformed to
  deserialise is a validation error, and logs nothing, as a malformed login
  post does). Unlike that path, a row with no password is
  not refused here — a passkey is a credential of its own, the shape a
  credential-less member will need. There is no username step, hence no
  enumeration surface; the login POST is throttled per IP (`passkey-login`,
  10/min, successes included, so a shared egress address shares one bucket)
  as abuse hygiene, and the options GET is not, since the login page's
  autofill fetches it on every view. The challenge is one session slot pulled
  on submit, so a second options fetch (another tab) invalidates the first
  ceremony — the member retries. The client re-arms the browser's passkey
  picker on page load only; after a dismissed button prompt the button remains
  the way in until the page reloads.
- **Adding one is a step-up**
  ([Member re-authentication](#member-re-authentication)). Registration opens
  a 15-minute window (`App\Features\Member\PasskeyReauth`, distinct from the
  MFA window). A successful registration clears the window; two stores racing
  inside it before either clears are bounded by the per-member throttle, not
  prevented (the session's single challenge slot serialises sequential
  retries, not concurrent ones). A cancelled browser prompt keeps the window.
  Accepted residual: a walked-up session inside the window can complete one
  registration without the password.
- **Nothing asks the member to name one.** `RegisterMemberPasskey` titles the row after where the
  passkey is kept, read from the credential's AAGUID, and drops a name the client posted; an
  authenticator that withholds its AAGUID, or one the bundled table does not list, leaves the name
  empty and the screen calls that one "passkey".
- **Removing one revokes.** Deletion is a step-up asked inline and revokes
  the member's other sessions and the remember token (a lost device is the
  usual reason). Adding the first one, for a member with no confirmed TOTP
  factor, revokes the same way as confirming TOTP does: it changes what a
  password sign-in must prove, so a session or recaller opened on the password
  alone must not outlive it. Any other addition revokes nothing — the proof
  was just given in this session and the new credential does not invalidate
  what other sessions were opened with.
- **Every registration and removal is mailed** to the registered address
  (`passkey-registered` / `passkey-removed`, always sent) and logged
  (`passkey.registered` / `passkey.removed`).
- **Throttling** keys the mutating routes (re-auth, store, delete) on the
  member (`passkey-manage`, 5/min); the page render and the challenge GET are
  exempt so a refresh or a cancelled prompt never spends the budget.
- **The last passkey may be removed**: the password remains, so there is no
  lockout and no new recovery path. The list marks synced passkeys (backup
  state flag) so a member holding only device-bound ones is nudged to add a
  second device.
- **Relying party = `APP_URL`'s host**, the host `trustHosts` already pins.
  Moving a site to another domain orphans its passkeys (authenticators stop
  offering them; the rows stay and the password still works). The opaque user
  handle is an HMAC under `fortify.passkeys.user_handle_secret`, which Fortify
  defaults to `APP_KEY` (the package's own env is overridden); rotating the key
  leaves existing passkeys valid and only changes the handle new ones carry.
- `credential_id` is stored at 512 characters under a binary collation on
  MySQL (base64url is case-sensitive). The WebAuthn maximum of 1023 bytes would
  not fit, so `RegisterMemberPasskey` refuses a longer attested id (422) before
  the insert; real authenticators emit far shorter ones. A refused WebAuthn
  ceremony renders as 422 app-wide (`bootstrap/app.php`), the only WebAuthn
  user being the member realm. Synced passkeys report a
  zero signature counter, so clone detection is nominal for them, and their
  security is that of the platform account they sync through — accepted;
  sign-in does not tell a synced passkey from a device-bound one, only the
  list does.

There is no site-wide enforcement or password removal: registering a passkey
is the member's choice, and the password stays usable with the second proof.

## Member re-authentication

A signed-in member proves who they are again before a sensitive change. The
proof is asked through one seam, `App\Auth\Reauth\RequiresReauth`, a trait on
the operation's FormRequest:

- **The proof is the account password, plus the confirmed TOTP factor when the
  member has one** — a current code or an unused recovery code. A member
  without a confirmed factor gives the password alone.
- **A passkey stands in for both.** A member who holds one may assert it
  instead (user verification required), and is then asked for neither the
  password nor the second factor. The one exception is new recovery codes,
  whose own demand is a TOTP code whichever way the member proved themselves.
  Changing the password this way needs no current password.
- **A password that was sent is always the path taken**, so a passkey proof
  never excuses a wrong one and is left unspent.
- **A proof serves one purpose.** Each request names a `ReauthPurpose`; a
  purpose either asks every time or opens its own 15-minute `ReauthWindow`, and
  no window opens another operation. A window is stamped only by a verified
  proof that was asked for: a `current_password` sent inside a fresh window is
  still verified, but does not push the window's end back.
- **Ordering is structural.** The validation rules check the password and only
  the *presence* of the second factor. Its value is checked after every rule
  has passed, so a wrong password never marks a TOTP code used in Fortify's
  replay cache nor spends a recovery code.
- **The verdict is taken on the locked row.** `VerifiesReauthProof` re-checks
  the raw password against the member row read `FOR UPDATE` and compares the
  factor state with what the rules saw, failing closed on a difference: the
  rules read the member as loaded when the request began.
- **A TOTP code is verified once per request**, since the replay cache refuses
  a second check. An operation that changes the factor itself (disable,
  regenerate recovery codes) verifies the proof inside the lock it mutates
  under; every other operation is verified by the seam before its controller
  runs.
- Starting or confirming a TOTP set-up asks for the password only: both refuse
  a member who already holds a confirmed factor.
- **Every route that asks is throttled** per member (5/min, the feature's own
  limiter or `member-reauth`), since the second factor is a six-digit code;
  `MemberReauthSeamTest` refuses a route without one.

### The passkey proof

`ReauthPasskeyController` runs the ceremony on two routes of its own
(`/member/reauth/passkey/options?purpose=…`, `/member/reauth/passkey`), not the
package's confirmation route, which stamps the framework-wide
`auth.password_confirmed_at` and would open every operation at once.

- **The purpose is fixed when the challenge is issued.** It is stored beside
  the challenge in the session (`reauth.passkey_options`, a key the login
  screen's ceremony never writes) and read back from there; the verify request
  cannot name another. The assertion must come from one of the member's own
  passkeys.
- **A verified assertion leaves a proof in the session** (`PasskeyProof`): the
  member, the purpose, a nonce and the time. The seam accepts it for that
  member and purpose for two minutes.
- **A proof opens one request.** The session is not locked, so parallel
  requests read the same proof; the one whose `Cache::add` of the nonce
  succeeds is the one that passes. The challenge is spent the same way, since a
  request that began before the verify can save its stale session back over
  the pulled slot. Both therefore rely on a cache store shared by every
  process that serves the site (`CACHE_STORE=database`, the default, is one;
  `array` is not).
- A proof is not tied to the passkey that made it: removing that passkey from
  another session inside the two minutes revokes this session, and with it the
  proof.
- The ceremony's two routes are serialized per session (`->block()`), and the
  verify POST is throttled per member (`reauth-passkey`, 10/min). The challenge
  GET is not, as for the other ceremonies.
- A step-up assertion logs `passkey.verified` with `via: reauth` and the
  purpose; a refused one logs `passkey.failed` with `via: reauth`.

An accepted residual: someone who knows the device PIN of a member's unlocked
device passes a passkey step-up. The notices mailed on a credential change are
the compensation.

### The code budget

The route limiters bound each group of routes separately, so
`ReauthCodeBudget` bounds what they add up to: how many TOTP codes one member's
step-ups compare, whichever operation asks.

- **Five comparisons a minute** per member and session. A sixth is refused
  without being compared.
- **A hundred wrong codes without a right one between them pause the codes**
  until a day after the first of them: a step-up then refuses every TOTP code
  unseen, logs `mfa.codes_paused` and mails the member once. A right code
  resets the count, once the transaction that verified it has committed. The figure borrows the
  upper bound NIST SP 800-63B-4 (Sec. 3.2.2) sets for consecutive failures, but
  this is not that control: the pause lifts by itself, and the sign-in
  challenge, which checks the same secret, is outside it.
- **Only a code about to be compared counts.** A wrong password is refused by the rules
  before the budget is touched, so a session holder who does not know the
  password cannot pause anything; a recovery code and a passkey proof are not
  guessable and spend nothing, so both still work during a pause — except for
  new recovery codes, which only a TOTP code obtains.
- **The count is taken before the comparison and outside the transaction** the
  verdict runs in: the database cache store shares the default connection, so
  a count taken inside would roll back with every wrong code.
- The framework's limiter can drop a few counts in the instant a counter is
  created, when parallel first hits race (measured on MySQL, not on SQLite).
  The pause count is created anew only after a right code or a day, neither
  of which a guesser brings about, so the hundred moves by no more than one
  such burst; the minute budget can give way by as much each minute, which is
  why it is not the bound.

With a window of one step either side (three valid codes), a hundred guesses
succeed about three times in ten thousand (RFC 4226, Sec. 6). The accepted
cost: whoever holds a session and the password can keep the codes paused, and
a member with neither a passkey nor a recovery code then waits it out.

Every member step-up goes through the seam: the two-factor and passkey
settings, password and email change, withdrawal, deleting an AI account,
minting or revoking its tokens, OAuth consent and ending a connection.
`MemberReauthSeamTest` keeps the member password rule and a raw password check
out of every other file.

## AI account access tokens

An owner minting or revoking one of their AI account's MCP tokens
([mcp.md](mcp.md)) re-authenticates ([Member re-authentication](#member-re-authentication)),
verified once and then carried for 15 minutes by a window of its own
([`AiTokenReauth`](../../app/Features/AiAccount/AiTokenReauth.php)). The window
is per flow, never shared: confirming a two-factor set-up must not also open the
door to minting a credential, which is why `ReauthWindow` is keyed per subclass.
The raw token is flashed for exactly one render, with the same accepted residual
as the recovery codes above — a browser may re-show that render from its own
history state.

The OAuth consent screen ([mcp.md](mcp.md), "Signing in from a client") asks
for the same proof, whoever the connection will speak for, through a window
of its own ([`ConsentReauth`](../../app/Mcp/OAuth/ConsentReauth.php)): a grant
is a credential like a minted token, and a signed-in session alone does not
make one. A password change or reset ends every OAuth grant of the member, with
the other devices.

## Password policy

The policy has a single definition — `Password::defaults()` in
[`AppServiceProvider`](../../app/Providers/AppServiceProvider.php) — and every
password-accepting path (member registration, password reset and change, the
admin panel forms, the admin CLI commands) validates through `Password::default()`.

At the CLI, `openpne:admin:create` and `openpne:admin:reset-password` prompt twice, or read
`OPENPNE_ADMIN_PASSWORD` for non-interactive provisioning; there is deliberately no `--password`
option, which would leak the secret into shell history and the process list.

- **Minimum 8 characters.** This meets ASVS 5.0 V6.2.1 (level 1).
  NIST SP 800-63B-4 §3.1.1.2 requires 15 characters for
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

## Rate limits on member requests

Content-posting and mail-triggering member writes, the keystroke-driven endpoints a compose form
calls, and the reactor list, the one read a pointer or a keyboard fires without navigating, carry
named per-minute limiters
([`AppServiceProvider`](../../app/Providers/AppServiceProvider.php)), attached per route in
`routes/web.php` and pinned by `WriteThrottleRoutesTest`, which also sweeps the route inventory so
a route carrying one of these limiters cannot go unlisted. Each has two limbs: a per-member cap
(primary) and a looser per-IP cap that bounds multi-account abuse from one address.

| Limiter | Default (member / IP per min) | Key shape | Routes |
|---|---|---|---|
| `posting` | 30 / 60 | member id / client IP | diary, group topic and event create + update, their comment posts, timeline post + reply, group talk send |
| `preview` | 60 / 120 | member id / client IP | compose body preview |
| `mention-search` | 60 / 120 | member id / client IP | @mention candidates (timeline, group talk), chat recipient search |
| `direct-message-send` | 10 / 30 | member id / client IP | direct message compose send, draft-edit send, chat send |
| `friend-request` | 15 / 40 | member id / client IP | friend link request, accept (friend page and notification center) |
| `group-join` | 15 / 40 | member id / client IP | group join, member approve, member decline, AI account group join |
| `reaction` | 60 / 120 | member id / client IP | reaction add, remove (group talk, timeline, diary, group boards) |
| `reaction-read` | 120 / 240 | member id / client IP | reactor list (who reacted) on the same four surfaces; read once a chip's tip has stayed open, and each answer lists members with avatars |

The registration-paused screen's request mail has a limiter of its own, `capacity-request`
([`FortifyServiceProvider`](../../app/Providers/FortifyServiceProvider.php)): 3 a minute per client
IP and 20 an hour for the whole site, since a guest may send it
([capacity](capacity.md), "Asking for a seat").

The defaults are deliberately loose: tuning draws on the 429 observability the security event log
now provides — every throttled request logs a `throttle.hit` event (route + member, never the
limiter key). Env overrides (`OPENPNE_THROTTLE_*`, `0` disables that limb) exist for shared-NAT /
proxy deployments where the per-IP limb should be relaxed or turned off; the read limb counts a
tip each time it is held open without names still good for it, so behind a shared address it is
the first to relax. A throttled request
renders the framework default 429 page.

Authentication and credential-mutation events (login, MFA, password/email change, ban, withdrawal)
are recorded on a dedicated `security` log channel — the event vocabulary, PII/injection contract,
and retention are in [logging](logging.md).

## Response headers

[`SecurityHeaders`](../../app/Http/Middleware/SecurityHeaders.php) sets the
same baseline on every response — `X-Content-Type-Options: nosniff`,
`X-Frame-Options: DENY`, a `frame-ancestors 'none'; base-uri 'self'` CSP,
`Permissions-Policy: camera=(), microphone=(), geolocation=()`,
`Cross-Origin-Opener-Policy: same-origin`, `Referrer-Policy:
strict-origin-when-cross-origin` unless the route set one, and (under
`force_https`) HSTS. `SecurityHeaders` is registered in the `web` group
**and** on the Filament panel's own stack:
the panel does not inherit the `web` group, so the admin pages — the
highest-value clickjacking target — would otherwise ship none of these.

Where `SecurityHeaders` sits in the `web` group matters: a response that aborts inside the group
unwinds only through the middleware it had already entered, so a slot at the end of the group would
miss the 419, the guest redirect and the implicit-binding 404 — all pages a member sees. It is
therefore prepended (only the public-file cookie scrub, which reads the finished response, sits
outside it). `SetLocale` needs the session, so it is placed right after `ShareErrorsFromSession` and
ahead of the first middleware that can abort, with `PreventRequestForgery` joined to the priority
list as that anchor ([`bootstrap/app.php`](../../bootstrap/app.php)).

A screen whose URL or form carries a secret — the login and registration
flows, password reset, and the email-change and MFA-reset landings — carries
[`NoReferrer`](../../app/Http/Middleware/NoReferrer.php) as route middleware:
the baseline already withholds the path cross-origin, so what `no-referrer`
closes is the same-origin channel — the landing's own subresources and
follow-up requests, and `document.referrer`. It covers the responses the route
returns; an abort (a 429 from the throttle, a 500) unwinds past it and ships
the baseline.

Deliberately not set:

- **A content CSP (`script-src`)** — the Vite/Inertia bundle has no nonce/hash
  wiring, and the absence is also what lets the panel's inline Livewire/Alpine
  scripts run.
- **`Cross-Origin-Resource-Policy`** — web-public bytes (avatars, banners, and
  the images of a web-public diary or timeline post) are served for cross-origin
  embedding, which `same-origin` would break.

## Inline delivery is re-encoded

An uploaded picture's stored bytes are authoritative and are never what a viewer receives inline.
Every raster route — `/file/{name}`, the `w_h` original under `/cache/img`, the banner, public
asset, link-card image and app icon routes, and the MCP image tools — answers the **canonical**, or
a variant drawn from it: the canonical is a full-size re-encode the image processor produced at
upload, before the row was saved ([file-storage](file-storage.md), "Writing an
upload"; [images](images.md), "files.width / files.height"). A re-encode carries no source metadata
— EXIF with its GPS coordinates, XMP, IPTC, comments — and, since the processor applies EXIF
Orientation as it draws, none is needed for the picture to stand upright. The inline
`Content-Disposition` names the file id, never the uploader's file name.

This holds for rows imported from OpenPNE 3 too: their canonical is produced on first view, or ahead
of time by `openpne:image-cache warm`, from the same bytes and by the same processor, and the
OpenPNE 3 upload-plugin link `/f/show/{id}.{ext}` answers by the same two rules. Only
the admin monitoring route (`/admin/file/{name}/raw`) and a non-raster attachment — a file a member
posted ([attachments](attachments.md)) or an OpenPNE 3 upload — stream the stored bytes as they
are; the attachment is always `application/octet-stream` with `nosniff`, whatever the name or type
says, so a stored HTML or SVG is downloaded, never interpreted as a same-origin document.

What a re-encode does not do is validate the container: a PNG with a wrong CRC or bytes trailing the
end marker, which the metadata stripper this replaced used to refuse, is stored as uploaded if the
decoder accepts it. Those bytes reach nobody but an administrator, and the GD processor's own
residuals are listed under "Decoding an upload".

## Decoding an upload

Everything that decodes a stored image goes through the
[`ImageProcessor`](../../app/Files/ImageProcessor.php) seam, selected by
`OPENPNE_IMAGE_PROCESSOR`. The default, and the only one without a further
dependency, is [`GdImageProcessor`](../../app/Files/GdImageProcessor.php): GD in
the PHP process. Each decoded frame is held as a full-canvas buffer, so decoding
an animation costs frames × width × height however small the encoded file is, and
GD allocates those buffers outside PHP's `memory_limit`; a 31 KB 1000×1000 GIF of
150 frames would cost ~650 MB. GD is therefore built with intervention/image's
`decodeAnimation` off, so **a decode allocates one frame, never the frame count**,
and a variant is a still whatever its URL asks for, as in OpenPNE 3.

The processor also refuses before it decodes. The upload rules bound a member's
upload (`OPENPNE_IMAGE_MAX_UPLOAD_KB`, and where the decode is in-process the
`dimensions` rule at `openpne.images.max_upload_dimension`, [images](images.md)), but a row imported from
OpenPNE 3 never met them, so the GD processor reads the header first and rejects
a source over [`ImageSourceLimit`](../../app/Files/ImageSourceLimit.php) —
`OPENPNE_IMAGE_MAX_SOURCE_KB` bytes, a declared side over `max_upload_dimension`,
or more declared pixels than `OPENPNE_IMAGE_MAX_SOURCE_PIXELS` — without
allocating anything. Left blank, both follow the upload rules (the upload cap, and
the per-side limit squared: 25 MP at the shipped 5000), so raising an upload limit
raises them too. What this bounds is one decode at `max_source_pixels × 4` bytes,
100 MB at the shipped default; a host sized below that sets the pixel cap lower,
and a stored image over it then yields no variant (a 404, or the shipped app icon —
a verdict the cache disk keeps until the file is replaced, whatever the caps are set to afterwards);
an upload over it is refused as an upload, since its canonical is produced before
the row is saved. An out-of-memory kill is not catchable, so
this header check is the whole defence in the GD process; nothing serialises
concurrent misses of the same picture. Under `imgproxy` the same header check
applies the sidecar's budget instead (50 MP, no per-side limit); HEIC and AVIF,
which only the sidecar reads, are decoded nowhere but there, and a container only
the sidecar reads whose header this PHP cannot read (a HEIC before 8.5) is the one
unmeasured source the check lets through, to the sidecar's own budget.

Two GD facts are accepted rather than worked around. GD cannot read an embedded
ICC profile, so every re-encode drops it and a wide-gamut photo is then read as
sRGB — colours shift; imagick, which could convert the profile, is no longer
offered because it could not skip frames before allocating them, and colour
management is what an out-of-process backend is for. And libjpeg recovers from a
truncated JPEG, so cut-short bytes decode to a partial picture instead of being
refused.

Original-size delivery is the canonical, so under GD an uploaded animation is a
still there too, as OpenPNE 3's own GD path left it; a processor that keeps the
frames is pinned by its contract test. Remote images are held to a stricter rule,
4 MP, because the bytes are not a member's upload ([link-cards](link-cards.md)).

Under `imgproxy` the decode leaves the PHP process: the sidecar holds its own
resolution and frame budgets and is what an out-of-memory kill would take down,
and the app's header check still runs first, applying the sidecar's budget rather
than GD's limits, so what each refuses differs only by those limits — and by the
types only the sidecar reads ([images](images.md), "Processing"). The sidecar sees only bytes the app spooled
for it, over a signed URL to one operator-configured address; no URL a member
typed reaches it. The spool is world-readable so that the sidecar's user can read
it, which on a host shared with other unix users leaves each upload readable to
them for the length of one request — bytes whose header PHP could not read included, since those
are the sidecar's to judge; a host that shares users puts the spool on a volume they do not see.

## Cookies

The realm session cookies, the remember-me cookie and the `XSRF-TOKEN` cookie
carry a browser-enforced name prefix on an HTTPS site
([sessions.md](sessions.md#cookie-name-prefix)). Under `__Host-` — a site whose
cookies are host-only at `/` — another host under the same parent domain cannot
plant any of them; a site that falls back to `__Secure-` has no such guarantee.

## Administrator content

An administrator writes four kinds of page content for Classic: the page inserts, the footer, a
banner's custom content, and the body of an `informationBox` / `freeArea` gadget. These reach every
member's page, the login page included, so by default they are **Markdown**, rendered by
`MarkdownText::renderOperator()` ([body-text.md](body-text.md#operator-text)): no script, no form,
no frame, no `style` / `id` / `class` / `data-*` attribute, links and images only over `http(s)`
(links also `mailto:`) or relative to the site. An administrator therefore cannot run script in a
member's session or draw a form over the login page through these fields, which keeps the admin
panel from becoming a way to take over a member account (see "Reset-link boundary invariant").

`OPENPNE_OPERATOR_RAW_HTML=true` switches all four to raw HTML, printed as stored, and brings back
the `<head>` insert (`pc_html_head`), which has no Markdown form. It is an environment setting
because it is the host's decision: whoever sets it accepts that an administrator of that site can
do anything a page script can. Nothing in the admin panel changes it.

[`OperatorContent`](../../app/Support/OperatorContent.php) is the only reader of the switch and the
only place the content turns into output; the helpers and gadget views call `render()` with both
stored forms and print what it returns. Each mode has its own storage (`pc_html_*` / `footer_*`
against the `*_markdown` settings, `banners.html` against `banners.markdown`, the gadget's `value`
row against `value_markdown`), and each admin form reads and writes only the storage of the mode
in force, so switching modes loses nothing. In Markdown mode the forms show the stored HTML read
only, as text.

What Markdown mode leaves open: custom CSS, which is not restricted here and can restyle the page
or load from another host; an image from another host, which tells that host when a page was
viewed; and a link whose text misdescribes its target.
