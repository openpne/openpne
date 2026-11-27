# Logging

Two log streams with different audiences: framework diagnostics and a dedicated security event
trail. Channels are in [`config/logging.php`](../../config/logging.php); the security API is
[`App\Support\SecurityLog`](../../app/Support/SecurityLog.php).

## Channel map

| Stream | Channel | File | Retention |
|---|---|---|---|
| Framework errors / uncaught exceptions | default `stack` → `daily` | `laravel-YYYY-MM-DD.log` | `LOG_DAILY_DAYS` (14) |
| Security events | `security` (`daily`, fixed `info`) | `security-YYYY-MM-DD.log` | `LOG_SECURITY_DAYS` (90) |
| 429s (rate-limit hits) | `security` only | `security-YYYY-MM-DD.log` | `LOG_SECURITY_DAYS` (90) |

The `security` channel is off the app stack: `SecurityLog` writes to it directly, so `LOG_STACK`
does not affect the trail, and `LOG_LEVEL` cannot silence it (level is hardcoded `info`). The
default app log filename changed from `laravel.log` (single) to `laravel-YYYY-MM-DD.log` (daily) —
`LOG_STACK` now defaults to `daily`, so a rotation-free unbounded log is no longer the default.
Deployers tailing `laravel.log` must switch to the dated filenames.

## Security event vocabulary

Every event additionally carries `ip` and (truncated) `user_agent` when raised in an HTTP request;
a console command adds neither. Listeners live in
[`app/Listeners/Security/`](../../app/Listeners/Security) (auto-discovered by their `handle()` type
hint, all synchronous — see below); seams are direct `SecurityLog::event()` calls. A seam logs
immediately after the durable mutation and **before** any notification/event dispatch: enqueueing is
fallible and must not be able to suppress the audit record of a change that already happened.

| Event | Source | Context (beyond ip/user_agent) |
|---|---|---|
| `login.success` | listener `LogSuccessfulLogin` (`Login`) | `guard`, `remember` (false for `member`, whose guard issues no recaller), `member_id` |
| `login.failed` | listener `LogFailedLogin` (`Failed`) | `guard`, `identifier` |
| `login.lockout` | listener `LogLockout` (`Lockout`) | `identifier` |
| `logout` | listener `LogLogout` (`Logout`) | `guard`, `member_id` |
| `password.reset` | listener `LogPasswordReset` (`PasswordReset`) | `guard`, `member_id` |
| `login.code_sent` | seam: `App\Features\Auth\LoginCode::issue` — a passkey holder's password verified and a confirmation code was mailed | `guard`, `member_id` |
| `login.code_failed` | seam: `LoginCodeController::store` — a submitted code that was refused (wrong, over the attempt cap, or the challenge voided since); an expired challenge logs nothing, and the code itself is never logged | `guard`, `member_id` |
| `mfa.failed` | listener `LogTwoFactorFailure` (member TOTP) | `guard`, `member_id` |
| `member.registered` | listener `LogMemberRegistered` (`MemberRegistered`) | `guard`, `member_id` |
| `registration.link_printed` | seam: `InviteMemberCommand`, `InviteAdministratorCommand` — a mailed link logs nothing | `email`, `via` (cli), `role` (admin) and `expires_at` for an administrator invite |
| `admin.granted` / `admin.revoked` | seam: `GrantAdministrator` / `RevokeAdministrator` (actions), and `CompleteRegistration` for a registration through a live administrator invite | `member_id`, `actor` (cli, or the acting administrator's member id), `via` (invite) |
| `admin.entered` | seam: `AdminEntryController::store` (a re-authentication proof), and listener `StampAdminEntry` (`Login`) for a login headed for the panel ([administrators.md](administrators.md#the-panels-entry)) | `member_id`, `via` (password, passkey, login) |
| `mfa.enabled` | seam: `MemberMfaController::confirm` | `guard`, `member_id` |
| `mfa.disabled` | seam: `MemberMfaController::disable` (live), `DisableMemberMfaCommand` | `guard`, `member_id`, `via` (cli) |
| `mfa.recovery_codes_regenerated` | seam: `MemberMfaController::regenerate` | `guard`, `member_id` |
| `mfa.recovery_code_used` | listener `LogRecoveryCodeReplaced` | `guard`, `member_id` |
| `mfa.reset_link_sent` | seam: `RequestMfaReset` (action) | `guard`, `member_id`, `admin_member_id` |
| `passkey.registered` | seam: `MemberPasskeyController::store` | `guard`, `member_id`, `passkey_id` |
| `passkey.removed` | seam: `MemberPasskeyController::destroy` | `guard`, `member_id`, `passkey_id` |
| `mfa.codes_paused` | seam: `App\Auth\Reauth\ReauthCodeBudget` — a member's step-ups stopped accepting TOTP codes after too many wrong ones | `guard`, `member_id` |
| `passkey.verified` | listener `LogPasskeyVerified` (`PasskeyVerified`) — the assertion verified, before the login gate; a refused (banned) member logs this with no `login.success`. A step-up assertion adds `via` (`reauth`) and `purpose` | `guard`, `member_id`, `passkey_id`, `via`, `purpose` |
| `passkey.refused` | seam: the passkey login gate (`FortifyServiceProvider`) — a verified assertion refused for a banned or AI-account member | `guard`, `member_id` |
| `passkey.failed` | seam: `App\Features\Auth\PasskeyLoginController::store` — an assertion the ceremony refused (unknown credential, stale challenge, bad signature, a counter that went backwards); the gate's refusal is not doubled here. `ReauthPasskeyController::store` logs the same for a step-up assertion, with `via` (`reauth`) | `guard`, `credential_id`, `member_id` (when the credential is known), `via` |
| `password.changed` | seam: `MemberConfigController::updatePassword` | `guard`, `member_id` |
| `email.change_requested` | seam: `RequestEmailChange` (action) | `guard`, `member_id`, `new_email` |
| `email.changed` | seam: `EmailChangeLinkController::confirmEmail` | `guard`, `member_id`, `old_email`, `new_email` |
| `email.change_cancelled` | seam: `EmailChangeLinkController::cancelEmail` | `guard`, `member_id`, `new_email` |
| `member.withdrawn` | seam: `WithdrawMember` | `member_id`, `actor` (self\|admin), `admin_member_id` |
| `member.banned` / `member.unbanned` | seam: `RejectMemberLogin` / `AllowMemberLogin` (actions) | `member_id`, `admin_member_id` |
| `ai_account.created` / `ai_account.deleted` | seam: `CreateAiAccount` / `DeleteAiAccount` (actions) | `member_id`, `owner_id` |
| `throttle.hit` | `bootstrap/app.php` report hook; `SendDirectMessageTool`, whose limit answers as a tool error rather than a 429 | `route`, `member_id`, `tool` (the MCP tool only) |

## PII / injection contract

`SecurityLog` enforces one contract (stated in full on the class):

- **Actor.** Always the resolved actor where one exists — a member id, an administrator's included.
  The attempted identifier is logged only for `login.failed` /
  `login.lockout` (no actor resolved yet), and for the `email.*` events and `registration.link_printed`
  (the address is the subject).
- **Never logged.** Passwords, tokens, session ids, recovery codes, a `Failed` event's raw
  credentials array, or a rate limiter's key (login keys embed the attempted email). Listeners pass
  a single identifier value, never the credentials map.
- **Sanitisation.** Every context value is cast to string (bool → `"true"`/`"false"`), then control
  characters — including CR/LF, which would otherwise forge log lines — are collapsed to a single
  space, and the result is truncated to 256 characters. `null` and any non-scalar / non-Stringable
  value is dropped (an absent actor leaves no key rather than an empty one).

## Rotation / retention

The `daily` driver rotates in-process (each write checks the date), so there is no cron dependency.
`LOG_SECURITY_DAYS` (90) keeps the security trail longer than the diagnostic log's 14. Filenames:
`laravel-YYYY-MM-DD.log`, `security-YYYY-MM-DD.log`.

## Shipping to syslog / SIEM

Tail or ship `storage/logs/security-*.log`. Because `SecurityLog` targets the channel directly,
`LOG_STACK` does not influence it — pointing the app stack at `syslog`/`stderr` leaves the security
trail on disk.

## 429 observability

The rate-limit hit hook is in [`bootstrap/app.php`](../../bootstrap/app.php)'s `withExceptions`.
`ThrottleRequestsException` is an `HttpException`, and the exception handler ignores every
`HttpException` by default (`internalDontReport`), matched by the `instanceof` parent — so
un-ignoring the `ThrottleRequestsException` subclass alone does **not** lift it (verified against
`Illuminate\Foundation\Exceptions\Handler`). The parent is un-ignored and the callback re-narrows to
`ThrottleRequestsException`; `->stop()` fires for every `HttpException`, so 429s reach the security
channel and all other HTTP exceptions stay out of the default channel exactly as before (they were
already ignored).

## Queue workers

Notifications are queued (`ShouldQueue`), so worker logs and the `failed_jobs` table are part of the
operational picture for the mail side of a security event (e.g. a password-change alert). The
security *event* listeners are deliberately **synchronous** (not `ShouldQueue`): a worker no longer
holds the originating request, so the `ip`/`user_agent` auto-attach would be lost and events could
reorder relative to the request that caused them.
