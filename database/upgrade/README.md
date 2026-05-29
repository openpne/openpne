# OpenPNE 3 → 4 upgrade

This directory holds the OpenPNE 3 → 4 data upgrade SSoT consumed by `App\Upgrade`.

## `openpne3-schema.sql`

The OpenPNE 3 source schema the upgrade maps **from**. It grounds the per-feature
mappings (`App\Upgrade\Steps\*`) and is loaded by the upgrade seed tests so the
transforms run against the real source DDL (TEXT vs VARCHAR, tinyint flags,
DATETIME, utf8mb3) rather than an approximation.

- Source: a clean OpenPNE 3 install, `mysqldump --no-data`.
- Version: OpenPNE 3.10.19 + the canonical plugins (opCommunityTopic 1.1.5,
  opCsvExport 0.9.2, opDiary 1.5.3, opLike 1.2.8, opMessage 2.0.0,
  opSkinTheme 1.0.16, opTimeline 1.2.12, opUploadFile 0.9.2).
- License: OpenPNE 3 is Apache-2.0; its standard schema ships here as a fixture.

Keep it byte-faithful to `mysqldump --no-data` so a future re-dump diffs cleanly.
Site-specific customizations are out of scope for the OSS upgrade tool.

## Mapping artifacts

Each feature maps to the OpenPNE 4 schema with a typed `App\Upgrade\Steps\*` step.
The mapping is the SSoT: it declares source table, target table, per-column
source/expression, and accepted gaps. `InsertSelectCompiler` compiles a step into
the `INSERT ... SELECT` the tool runs; the matrix audit test cross-checks every step
against this fixture (source columns) and the migrations (target columns) to catch
drift. Render the human-readable matrix with `php artisan openpne:upgrade-matrix`.
