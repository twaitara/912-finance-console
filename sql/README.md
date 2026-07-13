# Database schema & migrations

## `schema.sql` — the single source of truth
`schema.sql` is the canonical, full schema for all 18 tables. It is **generated
from the app's own runtime ensure-functions** (the `*_table($pdo)` helpers like
`pc_table`, `quotes_table`, `users_table`, plus the inline `CREATE TABLE` calls in
the loans / tasks / email / audrey endpoints). Each table is its exact `CREATE
TABLE` followed by the `ALTER … ADD COLUMN` statements that evolved it, so running
the file on an empty database reproduces today's schema.

Use it to:
- **See** the real shape of the database in one place.
- **Bootstrap a fresh install**: `mysql your_db < schema.sql`.
- **Review** changes in pull requests.

## The app still self-heals at runtime
The application continues to create/upgrade its own tables on each request
(`CREATE TABLE IF NOT EXISTS` + additive `ALTER TABLE … ADD COLUMN`). So on a
normal deploy you do **not** need to run anything by hand — `schema.sql` is a
reference/fresh-install artifact, not something the running app reads.

## Making a schema change (the convention)
1. Make the change the way the app already does it — add/extend the relevant
   `*_table()` ensure-function (new `CREATE TABLE IF NOT EXISTS`, or a new
   `ALTER TABLE … ADD COLUMN` guarded by `try { … } catch {}`).
2. Record it as a **dated migration file** in this folder, e.g.
   `2026-07-13_project_costs.sql` (the existing one) — named
   `YYYY-MM-DD_<what-changed>.sql`, containing just that change's DDL.
3. **Fold the same change into `schema.sql`** so the canonical file stays current
   (add the column to the table's block / append the `ALTER`).

This keeps three things in sync: the live self-healing code, the per-change
migration history, and the canonical `schema.sql`.

## Notes
- Everything is `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`.
- These `.sql` files are repo/reference material only. They are not deployed to
  the server (not in `.cpanel.yml`) and `.htaccess` denies web access to `*.sql`.
