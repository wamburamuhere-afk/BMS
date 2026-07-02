# Legacy one-off scripts — QUARANTINED

These 284 files were one-off dev/debug/data-fix scripts that previously lived in the
public webroot with **no authentication** — anyone who guessed a URL could run
`ALTER TABLE` or read production data. They pre-date the proper migration system
(`migrations/runner.php` + the `migrations` tracking table, run on every deploy).

They were moved here on **2026-07-01** (branch `chore/webroot-quarantine`) after
verifying that none of them is referenced by the router (`roots.php`), any page,
JS, include, cron job, CI workflow, or the deploy script.

- HTTP access to `scripts/` is denied by the root `.htaccess`.
- Nothing may `require`/`include` files from this directory.
- **Do not add new scripts here.** Database changes go through `migrations/`
  (see `.claude/migrations.md`); ad-hoc experiments go in `scratch/` (untracked by CI).

**Deletion date:** this whole directory will be deleted one stable release after
the quarantine merge. If something on production unexpectedly breaks before then,
the file is still here (and in git history) to inspect.
