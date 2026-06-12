# Route parity

`openpne3-pc-frontend-routes.php` is the OpenPNE 3 `pc_frontend` route inventory the
Classic adapters map from, consumed by `App\Compat`. It grounds the route-parity audit:
every OpenPNE 3 route is checked to be mapped or gapped, and the OpenPNE 3 URLs in the
mappings are checked against this inventory.

`openpne3-module-actions.php` maps an OpenPNE 3 `module/action` (the bare form a navigation row
may store, e.g. `diary/listMember`) to a route name, split into id-less / id-bearing variants
because symfony resolved the pair to different routes by context. It is a separate file, not extra
columns on the inventory, so the inventory stays a faithful `app:routes` dump that can be
regenerated as-is. `App\Upgrade\Steps\NavigationUpgrade` resolves the route name to its URL through
the inventory, keeping the URL single-sourced there.

## Regenerating

From a clean OpenPNE 3 install (OpenPNE 3 is Apache-2.0, so this inventory ships in-repo):

```
php symfony app:routes pc_frontend
```

gives each route's name, HTTP methods, and URL pattern, recorded per module as
`[URL, method]`. `method` is the route's sf_method constraint in the OpenPNE 3 source:
`POST` where it declares `sf_method => ['post']`, otherwise `ANY` (no constraint, so the
route is GET-reachable). This drives URL-compatibility scope — only GET-reachable URLs are
bookmarked / mailed / linked, so only they must be preserved; POST-only form-submit routes
are still covered for completeness but sit outside the URL-compatibility contract.

OpenPNE 3 also defines a global deprecated fallback `/:module/:action/*`
(`opSymfonyDefaultRouteCollection`), so a module's actions are reachable by URL even
without a named route. A module can disable it — opDiaryPlugin adds `diary_nodefaults`
(`/diary/*` → `default/error`) — which makes the named routes the complete set of
reachable URLs. Record that per module as `disables_global_fallback`; the audit relies on
it to know the named-route coverage check is exhaustive.
