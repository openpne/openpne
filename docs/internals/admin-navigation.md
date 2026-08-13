# Admin navigation

The admin sidebar is five groups plus the Dashboard, registered in
[`AdminPanelProvider`](../../app/Providers/Filament/AdminPanelProvider.php). Group labels are
closures so they resolve in the request locale — a bare `__()` there evaluates at boot, and a
locale mismatch would silently drop the group to the end of the sidebar.

| Group | Holds | Examples |
|-------|-------|----------|
| *(ungrouped)* | The panel landing page | Dashboard |
| Members | The people on the site, and how they get in | Member list, Member invites |
| Content | What members posted, for moderation | Groups, Diaries, Files |
| Settings | What an operator configures | Base settings, Feature settings, Profile item settings |
| Appearance (Classic) | Classic-surface look and layout | Gadgets, Navigation, Custom CSS & HTML |
| System | Running the install itself | Cache, Admin Users |

## Naming

- **Members / Content** entries are plain entity nouns — what the screen lists (`Diaries`,
  `Files`). Term-configurable nouns go through `%term%` placeholders (see [i18n](i18n.md)).
- **Settings** entries read `<Subject> settings` (`Branding settings`, `%Diary% settings`), so an
  entry says what it configures without needing its group for context.
- **No two entries may render the same label**, in either locale. This follows from the two rules
  above rather than standing on its own: one subject named twice means two screens claim one job.
- A Resource overrides `getNavigationLabel()` for this, never its `modelLabel` /
  `pluralModelLabel` — those stay the plain entity name that buttons, breadcrumbs and record
  headings read ("Create community"), which is a different job from labelling a sidebar row.

## Placement

Every entry declares its group and an explicit `$navigationSort`. An omitted sort falls back to
discovery order, which tracks the filesystem rather than any intent.

[`AdminNavigationConventionsTest`](../../tests/Feature/Filament/AdminNavigationConventionsTest.php)
enforces the group, sort and label rules. It builds its set from the pages and resources the panel
registers — deliberately not from `getNavigation()`, which returns only what the current request
may see: `InviteMembers` hides itself on a closed registration mode and the Appearance screens hide
themselves on `modern_only`, so a request-derived set would let exactly those screens drift.

## Adding to the navigation

- **Prefer an existing screen.** A new operator-facing option is first a key in
  [`SnsSettingKey`](../../app/Support/SnsSettingKey.php) under an existing
  [`SettingGroup`](../../app/Support/SettingGroup.php), rendered by the page that already owns that
  group. A new sidebar entry is the second choice — a sidebar that grows a row per feature stops
  being scannable, and the cost lands on every operator, not just the one using the new feature.
- **One home per screen.** A screen appears in the sidebar exactly once. Other ways to reach it are
  links inside the pages that need them, never a second entry.

## Page chrome

- **No section heading repeats the page title.** A single-section page leaves the section unheaded;
  a heading earns its place only by naming a subgroup within the page (`Custom CSS`, `Footer`).
- **List pages carry no breadcrumbs** — at depth 1 the trail only self-links. The base
  [`ListPage`](../../app/Filament/Resources/Pages/ListPage.php) drops them; create, edit and view
  keep Filament's default trail.

[`AdminPageChromeTest`](../../tests/Feature/Filament/AdminPageChromeTest.php) enforces both.

## Key invariants

1. Every navigable screen sits in one of the five registered groups and declares an explicit
   `$navigationSort`, unique within its group.
2. No two navigable screens render the same navigation label in any locale.
3. Settings-group labels end in "settings" (`設定` in Japanese).
