# BMS — Tenant Backup Plan

**Status:** DRAFT — awaiting approval (2026-10-31)
**Scope:** BMS only (`/var/www/bms.bjptechnologies.co.tz`). Other systems on the same server
(Vikundi etc.) are NOT touched.

---

## 1. Findings from production (2026-10-31)

| Item | Finding |
|---|---|
| Scheduled backup | **None.** Root crontab only has `process_registration_jobs.php` (BMS) and Vikundi's `backup_all.php` (02:15). `cron/backup.log` never existed. |
| Tenants | **25 active** (+ id 3 deleted), DBs `bms_t4` … `bms_t58`, ~23 MB each on disk. |
| Tenants with any backup | Only `t29` (BJP SHOP) and `t47` (HANGA SS) — made when someone opened the page. **23 tenants have never been backed up.** |
| BJP (legacy DB) | Last backup 2026-10-17 (13 days gap). Manual run 2026-10-31 OK: 15.86 MB, DB 6.92 MB, 109 files. |
| Pruning | That run deleted **both** older backups (age > 7 days) → BJP was left with a single backup. No "keep at least N" rule. |
| Cross-tenant file leak | Every backup zips the **whole** `uploads/` (incl. `t4/`, `t7/`, `t20/`, `t50/`…). Restore writes into the shared `uploads/` root. Evidence: 4 × 81 MB uploaded full-system zips restored inside `backups/t29/`. |
| Permissions | `backups/` is `777` (deploy.yml `chmod -R 777 backups/`) → any user/system on the server can read every BMS dump. |
| Location | Backups live on the same disk, inside the webroot. Server lost = backups lost. |
| Disk | 45 GB, 24 GB free. `uploads/` 17 MB, `backups/` 446 MB (≈ 414 MB is clutter in `t29/`). |

Code references:
- `cron/auto_backup.php` — CLI, no tenant resolved → BJP DB only.
- `api/backup_actions.php:183`, `app/constant/settings/backup_restore.php:44` — zip `ROOT_DIR/uploads` (all tenants).
- `api/backup_actions.php:243`, `:388` — restore copies into `ROOT_DIR/uploads`.
- `core/backup.php:326` `bms_prune_backups()` — age-only pruning, no minimum.

---

## 2. Schedule

| What | When | Kept on server | Emailed |
|---|---|---|---|
| **DB snapshot** (each tenant + BJP + `bms_control`) | Every 6 h — 00:30, 06:30, 12:30, 18:30 | 48 h | No |
| **Full backup** (DB + that tenant's own `uploads/tNN/`) | Daily 00:30 | see §3 | **Yes** |

00:30 avoids Vikundi's 02:15 job. Installed by `deploy.yml` as `/etc/cron.d/bms-backup`
(idempotent, survives every deploy — never a manual step again).

## 3. Retention (automatic deletion)

Applied per tenant folder after each run. A backup is **kept** if ANY rule holds:

| Rule | Keeps |
|---|---|
| 6-hourly DB snapshots | last 48 h |
| Daily full | last 7 days |
| Weekly | first daily of each week, last 4 weeks |
| Monthly | first daily of each month, last 12 months |
| **Safety floor** | the 3 newest backups — **always**, whatever their age |
| Manual (`bms_backup_*`) | never auto-deleted |
| `pre_restore_*`, `uploaded_*` | 30 days (today `uploaded_*` is never deleted) |

Weekly/monthly are the same daily file kept longer — no extra copies. ≈ 23 files per tenant
× ~1.5 MB ≈ 35 MB/tenant ≈ 1 GB for all 27 databases.

Example on 2026-12-15: dailies 9–15 Dec; weeklies of 23 Nov, 30 Nov, 7 Dec; monthlies
1 Nov, 1 Dec; plus the snapshots from the last 48 h.

## 4. Off-site copy — email (phase 1 of off-site)

- Dedicated mailbox (e.g. `backups@…`, Google Workspace/Gmail) with 2FA, used for nothing else.
- Daily, **one email per tenant**: subject `BMS Backup | <company> | bms_tNN | 2026-10-31`.
  Sent via the existing `sendEmail()` (`core/mailer.php`, attachments supported) + platform SMTP.
- Attachment = **AES-256 encrypted zip** (opens in 7-Zip / WinRAR with the password).
  Password lives only in server config (env), never in an email.
- Size guard: attachment > 20 MB → email sends DB only + a note; files stay on the server.
- Mailbox cleanup: script deletes backup emails older than 30 days over IMAP (needs `php-imap`;
  otherwise manual), so the mailbox stays ≈ 1.5 GB.
- Destinations are pluggable: moving later to Google Drive / Backblaze B2 / GCS is a config
  change, not a rewrite.

**Limits accepted for now:** 25 MB attachment cap, mailbox storage cap, email is not a vault.
Revisit when tenants or file sizes grow.

---

## 5. Phases

### PR 1 — Safety now
1. **Close the leak** — tenant backup zips only `uploads/tNN/`; restore writes only there;
   BJP backup excludes `t*/` folders. Tests: tenant A cannot see or overwrite B's files.
2. **Back up every tenant** — `cron/backup_all_tenants.php`: BJP + `bms_control` + every
   active/trial tenant, each into its own folder. One failure never stops the others.
   `mysqldump --single-transaction` + gzip (PHP dump as fallback). Results → `tenant_backup_log`.
3. **Schedule** — `deploy.yml` writes `/etc/cron.d/bms-backup` (§2).
4. **Retention + permissions** — §3 rules with the 3-newest floor; `750` instead of `777`.

### PR 2 — Email off-site (§4)

### PR 3 — Monitoring + restore drills
- Superadmin "Backup Health" page: per tenant last backup, size, ✅/❌, last email sent.
- Alert (notification engine) when a backup fails or is > 26 h old.
- Weekly automatic test-restore into a scratch DB + row/table count check.
- Runbook: restore one tenant / restore the whole server.

---

## 6. Open decisions
- [ ] Mailbox address for backup emails.
- [ ] 6-hourly DB snapshots OK, or more frequent?
- [ ] Whether to clean the ~325 MB of old `uploaded_*` / `pre_restore_*` clutter in `backups/t29/` once PR 1 ships.
