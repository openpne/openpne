# Member preferences & config

Per-member preferences (OpenPNE 3's `member_config` grab-bag, retyped): a closed registry of
keys, each stored at most once per member, surfaced for editing on the member config page
(OpenPNE 3 `member/config`). The page is a dual-surface [feature module](feature-modules.md)
under [`app/Features/Member/`](../../app/Features/Member); the store is
[`member_preferences`](../../database/migrations/2026_06_04_000000_create_member_preferences_table.php).

Not every OpenPNE 3 `member_config` use lands here. Identity-bearing / hot-path attributes are
typed `members` columns instead (e.g. `locale`, read every request by
[`SetLocale`](../../app/Http/Middleware/SetLocale.php)); notification opt-ins are deferred to a
dedicated store. This store holds the feature-scoped preferences that are neither.

## The typed registry

[`PreferenceKey`](../../app/Support/PreferenceKey.php) is the single source of truth for which
preferences exist: the enum **case value is the stored `key`**, and each case declares its
default, its value codec, and its OpenPNE 3 origin. Adding a case is all it takes to add a
preference (and, when it carries an `op3SourceName()`, to migrate it). A row is
[`MemberPreference`](../../app/Models/MemberPreference.php); the `(member_id, key)` unique keeps
it to one row per preference.

The codec is **value-type aware per case**: most keys are on the shared
[`Visibility`](../../app/Support/Visibility.php) scale, but `PreferredSurface` stores an
[`App\Support\Surface`](../../app/Support/Surface.php). `decode()/encode()/default()` branch on
the case, so the registry can hold more than one value type without a second list.

An **absent row means "follow the default"** — the default is never written, so changing a key's
default applies to every member who has not made an explicit choice. Two default shapes:

- A **Visibility** key has a concrete default (e.g. `DiaryDefaultVisibility` → Members), so a
  read always yields a value. A corrupted / non-digit stored value falls back to that default,
  never to the least-restrictive `Open` (the `ctype_digit` guard against PHP's `(int) 'foo' === 0`).
- `PreferredSurface` is **tri-state**: its default is `null`, meaning "no member choice — defer
  to the [surface fallback](feature-modules.md#surface-selection)". So it has a distinct read,
  [`Member::preferredSurface(): ?Surface`](../../app/Models/Member.php), rather than going
  through `Member::preference()` (which is typed to return a non-null `Visibility`).

Writes go through `Member::setPreference()` / `setPreferredSurface()` (store an explicit value,
even one equal to the default) and `resetPreference()` / `resetPreferredSurface()` (delete the
row, back to default-following). `setPreference($default)` is **not** the same as a reset.

## The config page

The page is Classic + Modern ([`MemberConfigController`](../../app/Features/Member/MemberConfigController.php),
canonical `member.config` + `/m/*` siblings). **Each section is its own submit** — diary default
audience, language, surface — so saving one never rewrites another. This is load-bearing, not
cosmetic: the diary section shows `DiaryVisibility::defaultFor()`, which **clamps** a stored
`Open` to Members at read time once web-public is off; a single combined save would write that
clamped value back and destroy the stored `Open`. Independent submits keep the read-time clamp
out of the write path.

- **Language** reuses the shared [`locale.switch`](../../routes/web.php) endpoint (durable
  `members.locale` write + the Inertia hard-navigation it already needs), not a field on this
  page's own form.
- **Surface** is a **binary** Classic/Modern choice, preselected to the member's current surface
  ([`SurfaceResolver::canonicalSurface()`](../../app/Support/SurfaceResolver.php) — resolve() minus
  the config page's own `/m/*` opt-in, but still honouring the modern_status / modern_only hard
  gates so a member already forced onto a surface is shown that surface). There is deliberately
  **no user-facing "follow the default" option**: that abstract state has no user-side signal to
  follow (unlike a device-linked dark-mode "auto") and tested poorly. The tri-state still exists in
  data, preserved by a server-side rule: `updateSurface()` pins only an actual change (chosen ≠
  current), so saving the surface you are already on is a no-op — an unset member stays unset and
  the operator keeps the ability to move them. This is the binary UI's equivalent of a
  "disabled until changed" button, enforced identically on both surfaces (the Classic surface is
  script-free). After a real change the controller lands the member on the chosen surface's own
  config URL — Modern → `/m/member/config`, Classic → canonical `/member/config` — because an
  explicit `/m/*` URL outranks the member preference in resolution, so a Classic choice **must**
  leave `/m/*` or the page would stay Modern.

The OpenPNE 3 `/member/config?category=accessBlock` URL is preserved: `show()` redirects just
that category to the Block list. The seeded config nav link renders because `/member/config` is a
real page; the [renderer](../../app/Services/NavigationService.php) hides a nav item only when its
route is missing or a compatibility shim.

## Upgrade

[`MemberPreferenceUpgrade`](../../app/Upgrade/Steps/MemberPreferenceUpgrade.php) loads OpenPNE 3
`member_config` rows whose `name` is in `PreferenceKey::upgradableCases()` — the cases with a
non-null `op3SourceName()`. An OpenPNE 4-native key (e.g. `PreferredSurface`, null source) is
excluded from both the `WHERE name IN (...)` and the name→key `CASE`, so a native key never leaks
an empty name into the SQL. The value `CASE` maps the OpenPNE 3 `public_flag` onto the Visibility
scale, and the filter keeps the latest row per `(member_id, name)` since `member_config` has no
such unique. All disposition of `member_config` names (migrated vs dropped) is reported by
`StepRegistry::memberConfigDispositions()`.

## Key invariants

1. `PreferenceKey` is the only list of preferences; the case value is the stored `key`, and the
   codec branches on the case so keys may carry different value types.
2. An absent row means "follow the default". A Visibility key's default is concrete;
   `PreferredSurface`'s default is `null` (defer to the surface fallback). Reset deletes the row;
   it is not `setPreference($default)`.
3. The config page saves each section independently, so the diary section's read-time clamp is
   never written back. The surface section is binary; `updateSurface()` pins only an actual change
   (chosen ≠ current), keeping an unset member unset, and redirects to the canonical URL when the
   choice is not Modern.
4. The upgrade migrates exactly `PreferenceKey::upgradableCases()` (non-null `op3SourceName()`);
   native keys are never migrated.
