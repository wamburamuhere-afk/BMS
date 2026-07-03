# Tanzania locations dataset (vendored)

One CSV per mainland region, flat rows:
`region,regioncode,district,districtcode,ward,wardcode,street,places`

- **Source:** https://github.com/Kalebu/mtaa (MIT license), derived from
  https://github.com/HackEAC/tanzania-locations-db — community-standardized
  from the Tanzania NBS census frame.
- **Coverage:** 26 mainland regions, 158 districts (older frame), 3,964 wards,
  ~68k street/village rows. Zanzibar's 5 regions are NOT covered.
- **Imported by:** `core/Location/Providers/MtaaCsvProvider.php` via
  `LocationSyncService` (run from `migrations/2026_07_03_location_engine.php`
  on deploy, or on demand via admin endpoint `api/location/sync.php`).

## Updating the data

Replace/add CSV files here (same column layout), then re-run the sync
(admin endpoint or re-deploy). The import is idempotent — existing wards
and villages are skipped, new ones are added, and every run is recorded in
`location_sync_log` with a full match report. District names are matched
against the OFFICIAL `districts` table by the normalizing matcher in
`LocationSyncService` (+ per-dataset aliases in the provider); anything
unmatched is listed in the report rather than guessed.

For a future official upgrade (e.g. NBS 2022 census frame incl. Zanzibar),
write a new provider class implementing `LocationProviderInterface` and
point the sync at it — nothing else changes.
