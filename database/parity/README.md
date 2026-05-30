# Route parity

`openpne3-pc-frontend-routes.php` is the OpenPNE 3 `pc_frontend` route inventory the
Classic adapters map from, consumed by `App\Compat`. It grounds the route-parity audit:
every OpenPNE 3 route is checked to be mapped or gapped, and the OpenPNE 3 URLs in the
mappings are checked against this inventory.

## Regenerating

From a clean OpenPNE 3 install (OpenPNE 3 is Apache-2.0, so this inventory ships in-repo):

```
php symfony app:routes pc_frontend
```

gives each route's name, HTTP methods, and URL pattern. A module's routes are explicit
here; in addition the fallback `/:module/:action` reaches any action without a named
route, recorded per module as `fallback`.
