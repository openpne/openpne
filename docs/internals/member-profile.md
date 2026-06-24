# Member profile

The configurable member profile (OpenPNE 3 `profile`): an admin defines the fields, each
member holds a value per field with a per-value visibility, and those fields drive the
profile page, the edit form, member search, and registration. It is a
[feature module](feature-modules.md) under
[`app/Features/Profile/`](../../app/Features/Profile) (member search lives in
[`app/Features/Member/`](../../app/Features/Member)), with the Filament admin for field
definitions under [`app/Filament/Resources/Profiles/`](../../app/Filament/Resources/Profiles).

## Field definitions vs values

A **field definition** is a [`Profile`](../../app/Models/Profile.php) row (OpenPNE 3
`profile`): its `form_type`, validation, `default_visibility`, and the `is_disp_*` flags
that decide where it appears (registration / config / search). Choices for a custom
select/radio/checkbox live in `profile_options`; captions/labels are localised in the
`*_translations` tables keyed by `(id, lang)`, the OpenPNE 3 Doctrine-I18n shape.
**Preset** fields (`name` starting `op_preset_`) instead source their structure and
choices from [`config/preset_profile.php`](../../config/preset_profile.php) via
[`PresetProfileService`](../../app/Services/PresetProfileService.php), not the database.

A **value** is a [`MemberProfile`](../../app/Models/MemberProfile.php) row. The table is
redesigned from OpenPNE 3's nested-set `member_profile` (lft/rgt/level/tree_key dropped);
see the [migration](../../database/migrations/2026_06_02_000002_create_member_profiles_table.php).

## Storage model

Every path — upgrade, save, read, search — must agree on one storage shape per
`form_type`, or migrated and freshly-edited data read back differently:

| form_type | stored as |
|-----------|-----------|
| input / textarea / country_select / region_select | `value` |
| preset select / radio | the **choice key** in `value` (no option row) |
| custom select / radio | `profile_option_id` |
| checkbox | **one row per chosen option**, each with `profile_option_id` |
| preset date (birthday) | `value_datetime` |
| custom date | the composed `Y-m-d` string in `value` |

The preset-vs-custom split for select/radio is
[`PresetProfileService::usesValueColumnForChoice()`](../../app/Services/PresetProfileService.php):
a preset stores the catalog key, a custom field stores the option id. Writes go through
[`SaveMemberProfile`](../../app/Features/Profile/Actions/SaveMemberProfile.php), which
replaces a field's rows wholesale (uniformly handling empties, single↔multi transitions,
and a checkbox's variable row set); the OpenPNE 3 → 4 load follows the same shape in
[`MemberProfileUpgrade`](../../app/Upgrade/Steps/MemberProfileUpgrade.php).

## Visibility

Profile values use the shared monotonic [`Visibility`](../../app/Support/Visibility.php)
scale (0 Open < 1 Members < 2 Friends < 3 Private), the same primitive diaries use, so an
access check is one range comparison `value.visibility <= clearanceFor(viewer, owner)`.

- `member_profiles.visibility` is **nullable**; null means "follow the field's
  `default_visibility`". OpenPNE 3 always stored a concrete public_flag, so null-as-default
  is an OpenPNE 4 addition — the upgrade maps a valid OpenPNE 3 public_flag onto the scale
  and leaves null only where OpenPNE 3 had no valid flag.
- **Effective visibility** = `is_edit_public_flag ? (row.visibility ?? default) : default`.
  A field whose flag is not member-editable always uses the field default, ignoring any
  stored per-value visibility.
- A field is per-value-editable only when `is_edit_public_flag` is set; its offered choices
  are [`Profile::visibilityOptions()`](../../app/Models/Profile.php) — Open is offered only
  for an `is_public_web` field, matching OpenPNE 3's editor.
- The reads bake this in:
  [`ShowProfile`](../../app/Features/Profile/Queries/ShowProfile.php) for the profile page
  (a **guest** has Open clearance and additionally only sees `is_public_web` fields; the
  page-level guest gate is the controller's) and `applyVisibility()` in search (below).
  Both also drop owners who block the viewer, like Diary.

## Age (derived from the birthday)

The age shown on the profile is **derived from the `op_preset_birthday` value, never stored**, and is
gated **separately** from that field by the per-member
[`AgeVisibility`](member-preferences.md#the-typed-registry) preference (default Private). The birth
**year** — which the
age reveals — is therefore exposed *only* through that gate, never through the birthday field:

- The birthday field itself **displays and searches month/day only** (the year is stripped, like
  OpenPNE 3's `XShortDateJa`), so it cannot leak the year whatever its own visibility.
- [`VisibleAge`](../../app/Features/Profile/Queries/VisibleAge.php) is the sole reader of the year:
  it returns the age only when `AgeVisibility` is within the viewer's clearance (the same monotonic
  check as a profile value) and never for an owner who blocks the viewer. The owner always sees their
  own age — an intentional divergence from OpenPNE 3's `getAge(true)`.
- **Web-public** age (Open) additionally requires the `AllowWebPublicAge`
  [SNS setting](../../app/Support/SnsSettingKey.php) (default off, **fail-closed** — only an explicit
  `'1'` enables it, the opposite direction from the CAPTCHA flag). When off, an Open age is shown to
  **nobody**, matching OpenPNE 3's `getAge()`, which gates a web-public flag on that admin setting.
  The [setter](../../app/Features/Profile/AgeVisibility.php) offers Open only when it is on.

## One validation + save path for edit and registration

Profile edit and registration validate and store **identically** so they cannot drift:

- [`ProfileFieldRules`](../../app/Features/Profile/ProfileFieldRules.php) builds the
  per-field value rules (preset keys vs option ids, country/region sets, date bounds,
  regexp/min/max, uniqueness) and the per-value `visibilityRule` (member-editable fields
  only, restricted to the field's offered choices).
- [`SaveMemberProfile::saveFields()`](../../app/Features/Profile/Actions/SaveMemberProfile.php)
  persists them; the caller picks the field set — edit uses `is_disp_config`
  ([`ProfileController`](../../app/Features/Profile/ProfileController.php)), registration
  uses `is_disp_regist`
  ([`CreateNewMember`](../../app/Actions/Fortify/CreateNewMember.php)). Only that set is
  saved, so a crafted payload cannot set fields outside it.

Registration shows the same per-value visibility selector as edit for member-editable
fields (OpenPNE 3's `MemberProfileForm` shares one widget builder between the two), so a
registrant can pick Private/Friends instead of being stuck on the field default. A
visibility submitted for a non-editable field is ignored (the value follows the default).

## Member search

[`SearchMembers`](../../app/Features/Member/Queries/SearchMembers.php) (OpenPNE 3
`/member/search`) ANDs an EXISTS subquery per field, matching by the same storage model.
Privacy is enforced **in SQL**, not after the fact:

- A match counts only when the value's effective visibility is within the viewer's
  clearance for that owner — a friendship-aware `CASE`/`EXISTS` on the Visibility scale
  embedded in the subquery — so search cannot probe a value the viewer could not otherwise
  see.
- Owners who block the viewer are excluded.
- A **forcibly-private** field (default Private and not member-editable) is dropped from the
  search form, since no other member could ever match on it (OpenPNE 3's
  `is_check_public_flag`).
- **Age** is a derived criterion (an age range → a birthday window), gated by `AgeVisibility`
  rather than a field visibility, with the same fail-closed (malformed preference → Private) and
  web-public rules as the display. The birthday field is searched by **month/day only**, so a date
  range cannot probe the birth year (= age) — that is reachable solely through the gated age
  criterion. OpenPNE 3 likewise gated its birthday-by-year search on the age public flag.

## Key invariants

1. The per-`form_type` storage shape is one contract shared by upgrade, save, read, and
   search; changing it means changing all four.
2. `member_profiles.visibility` null means "follow the field default"; effective visibility
   ignores a stored per-value visibility unless the field is `is_edit_public_flag`.
3. Edit and registration MUST validate and save through `ProfileFieldRules` +
   `SaveMemberProfile`, and persist only their own `is_disp_*` field set.
4. A read or search MUST constrain matches to the viewer's clearance and exclude
   owner→viewer blocks; search additionally hides forcibly-private fields from the form.
5. The birth **year** (= age) is exposed only through `AgeVisibility` — via `VisibleAge` and the age
   search criterion. The birthday field displays and searches month/day only; web-public age also
   requires the `AllowWebPublicAge` SNS setting (fail-closed, default off).
