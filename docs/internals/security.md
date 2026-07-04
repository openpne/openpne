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
([`PasswordWrap`](../../app/Upgrade/Runner/PasswordWrap.php)), so a
wrong-password probe against an **existing** account can distinguish
"migrated, not yet logged in" from other accounts by timing — it reveals that
state, not whether an account exists, and disappears on the account's first
login.
