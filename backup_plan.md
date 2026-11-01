# BMS — Tenant Backup Plan

**Status:** DRAFT — awaiting approval (2026-10-31)
**Scope:** BMS only (`/var/www/bms.bjptechnologies.co.tz/public_html`). Other systems on the same server
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

| What | When | Kept on server | Off-site |
|---|---|---|---|
| **DB snapshot** (each tenant + BJP + `bms_control`) | Every 6 h — 00:30, 06:30, 12:30, 18:30 | 48 h | No |
| **Full backup** (DB + that tenant's own `uploads/tNN/`) | Daily 00:30 | see §3 | **Yes** — Google Drive, encrypted, 01:30 (§4) |

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

## 4. Off-site copy — Google Drive via rclone (encrypted)

Decided 2026-10-31: **rclone → Google Drive of `bjptechnologieslimited@gmail.com`** instead of
email (no 25 MB attachment cap, no mailbox clutter, proper sync tool).

- **What goes:** each night's full backups only (`auto_backup_*.zip` of the main install, every
  tenant and `bms_control`). Snapshots, manual, pre-restore and uploaded files stay on the server.
- **When:** 01:30 Dar es Salaam (one hour after the full backup; waits if a backup is still
  running). `cron/offsite_backup.php`, installed in the same `/etc/cron.d/bms-backup-<host>`.
- **Encrypted before upload:** the rclone remote must be type `crypt` — file contents AND
  file/folder names are encrypted on the server. A plain remote is refused. Google never sees
  the data.
- **Runs as root:** only root can read `/root/.config/rclone/rclone.conf` (Drive token +
  encryption password), so no web app on this shared server can read them.
- **Safe upload:** never overwrites a file already on Drive; every uploaded file is verified
  (`rclone cryptcheck`).
- **Layout on Drive:** `BMS-Backups/<encrypted>/…` → decrypted: `<host>/auto_backup_….zip`,
  `<host>/t29/…`, `<host>/_platform/…`.
- **Retention on Drive** (runs only after a successful upload): 30 days of nightlies + the first
  of each month for 12 months + **the 3 newest per folder always**. Always a superset of what
  the server keeps. Est. ≈ 42 files per company ≈ 2–3 GB total — within the free 15 GB.
  Deleted files go to Drive's trash for 30 days first (Google default).
- **Visibility:** `backups/_platform/offsite.log` + `offsite_status.json` (read by PR 3's
  health page).

### One-time server setup (root)

1. Install rclone: `sudo -v ; curl https://rclone.org/install.sh | sudo bash`
2. `sudo rclone config` → `n` new remote **`gdrive`**, storage `drive`, client id/secret blank,
   scope **`drive.file`** (rclone sees only the files it creates), advanced `n`,
   auto config **`n`** (headless) → on a PC with a browser, run the `rclone authorize "drive" …`
   command it prints, sign in as `bjptechnologieslimited@gmail.com`, paste the token back.
   Shared drive `n`.
3. `n` new remote **`bms-offsite`**, storage `crypt`, remote `gdrive:BMS-Backups`,
   filename encryption `standard`, directory names `true`, password: `g` (generate, 128+ bits),
   salt password: `g` (generate). **Write both generated passwords down and store them off the
   server (password manager + paper).** Without them the Drive copies can never be opened.
4. Test: `sudo php /var/www/bms.bjptechnologies.co.tz/public_html/cron/offsite_backup.php --host=bms.bjptechnologies.co.tz --check`
5. First upload now: same command without `--check`.

### Restore from Drive (server lost)

On any machine: install rclone, recreate `gdrive` (sign in again) and `bms-offsite` with the
**same two passwords** (`rclone config` → crypt → enter them, not generate), then
`rclone copy bms-offsite:bms.bjptechnologies.co.tz/t29/auto_backup_<date>.zip .` and restore that
ZIP through Settings → Backup & Restore → upload.

---

## 5. Phases

### PR 1 — Safety now
1. **Close the leak** ✅ — tenant backup zips only `uploads/tNN/`; restore writes only there;
   BJP backup excludes `t*/` folders. Tests: tenant A cannot see or overwrite B's files.
2. **Back up every tenant** ✅ — `cron/backup_all_tenants.php`: BJP + `bms_control` + every
   non-deleted provisioned tenant (suspended/archived included), each into its own
   `backups/t{id}/` (the folder its Backup page lists). One failure never stops the others.
   Uses the existing BMS dumper inside `START TRANSACTION WITH CONSISTENT SNAPSHOT`, not
   `mysqldump` — the UI restore is built and tested around that dump format. Every file is
   re-opened and verified. Results → `bms_control.tenant_backup_log`.
3. **Schedule** ✅ — `deploy.yml` runs `scripts/install_backup_cron.php`, which writes
   `/etc/cron.d/bms-backup-<host>` (§2), run as www-data; demo staggered +30 min.
4. **Retention + permissions** ✅ — §3 rules with the 3-newest floor. `backups/` = deploy
   user + group www-data, mode `2770` (not `750`: git, running as the deploy user, must still
   update `backups/.htaccess`, and www-data must write). Other server users get nothing.
   Residual: another app running PHP as www-data could still read dumps on the server — the
   off-site copies (PR 2) are encrypted and their keys are root-only.

### PR 2 — Off-site to Google Drive (§4) ✅
`core/offsite_backup.php` + `cron/offsite_backup.php`, scheduled by the same install script.
Test `tests/test_offsite_backup_cli.php` (real rclone run against a crypt remote when rclone
is available). Needs the one-time server setup in §4 before the first upload; until then the
nightly job logs `NOT CONFIGURED` and changes nothing.

### PR 3 — Monitoring ✅
- Superadmin **Backups** page (`app/superadmin/backups.php`, nav "Backups"): every database's
  last good full backup, size, last snapshot, state (Healthy / Failed / Overdue / Never /
  Pending for companies created < 26 h ago), last error; the off-site copy; failures of the
  last 30 days. Data: `core/backup_health.php` over `tenant_backup_log` + `offsite_status.json`.
- Alert after every scheduled run (`cron/backup_all_tenants.php`): if anything failed, is over
  26 h old, never ran, or the off-site copy failed / is overdue / is not configured → one bell
  notification (`backup_problem`, opens the Backups page) + one email to every superadmin,
  **at most once per day** (`backups/_platform/.backup_alert_day`). The root-run off-site job
  has no database access, so the next www-data run reports on it.
- Not built (deliberately): an automatic weekly test-restore. Every backup is already re-opened
  and checked when written (consistency, manifest, complete dump), and a real tenant backup was
  restored end-to-end through the API (349/349 tables). A scheduled restore on the shared
  production database server is extra load and risk for little gain at this size — revisit as
  a monthly manual drill (restore one company's latest ZIP into a test company).

---

## 6. Open decisions
- [x] Off-site destination: Google Drive of `bjptechnologieslimited@gmail.com` via rclone (not email).
- [ ] 6-hourly DB snapshots OK, or more frequent?
- [ ] Whether to clean the ~325 MB of old `uploaded_*` / `pre_restore_*` clutter in `backups/t29/` once PR 1 ships.

## 7. Google Drive setup — progress (updated 2026-11-01)

**Done**
- [x] Google Cloud project `bms-backups-513409`, OAuth app "BMS Backups" (External), **published: In production** (so the Drive token no longer expires after 7 days; no verification requested).
- [x] Public Privacy Policy live at `https://bms.bjptechnologies.co.tz/privacy` (PR #2678 makes it show each tenant's own Company Profile name/contact; pending merge to develop → main).
- [x] OAuth client `rclone` (Desktop app) created; `rclone config` on the server reached the `rclone authorize "drive" "eyJ..."` line.
- [x] `rclone authorize` was run on the PC (rclone v1.75.2 for Windows).

**Stopped at**
- Google's sign-in on the PC asks to verify `bjptechnologieslimited@gmail.com` with a prompt on the Google Pixel 7, which is held by another person. Needs that person online to tap Yes / the number shown.

**Remaining**
1. Get the Pixel 7 holder to approve; finish the browser consent (Advanced → Go to BMS Backups → Allow).
2. Copy the line between `--->` and `<---End paste` from the PC and paste it at `config_token>` on the server; Shared Drive? `n`; Keep this "gdrive" remote? `y`; stop at the `e/n/d/r/c/s/q>` menu.
3. Verify with `rclone lsd gdrive:` on the server.
4. Encryption: create the `crypt` remote over `gdrive:` and its two passwords (store them offline, separately from the server).
5. Run a real backup upload and a restore test from Drive.
6. Merge PR #2678, deploy, check `/privacy` on the BJP host and on one tenant.

**Never share:** the Client secret or the `eyJ...` token (it contains the secret). If either was exposed, create a new Client secret and redo `rclone config`.
