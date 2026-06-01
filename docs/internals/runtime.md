# Runtime

Some deployments keep an application's secrets and writable runtime state
outside its code checkout — for example when the checkout is a read-only,
immutable release. By default the application reads its `.env` from the project
root and writes to the in-project `storage/` directory, but two process
environment variables, read during startup (see
[`bootstrap/app.php`](../../bootstrap/app.php)), let a deployer relocate either:

| Variable | Relocates | Laravel call |
|----------|-----------|--------------|
| `OPENPNE_ENV_PATH` | the directory the `.env` file is loaded from | `useEnvironmentPath()` |
| `LARAVEL_STORAGE_PATH` | the `storage/` directory | `useStoragePath()` |

Set them in the process manager, web server, or container environment — both
are read with `getenv()` before the app boots. When neither is set the
application uses its default in-project paths and behaves identically to a
stock install.

## Key invariants

1. With `OPENPNE_ENV_PATH` and `LARAVEL_STORAGE_PATH` unset, the hook is a no-op:
   it only relocates paths, never changes behavior.
2. `OPENPNE_ENV_PATH` MUST NOT be set inside `.env` — it is resolved before the
   `.env` file is loaded and is what tells the framework where that file lives.
