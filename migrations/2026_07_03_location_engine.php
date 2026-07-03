<?php
/**
 * Location engine foundation:
 *  - wards: re-key to the official district frame (district_id), keep the
 *    33 legacy council-keyed rows but deactivate them (junk free-typed data).
 *  - villages: new table (Street/Village level under a ward).
 *  - location_sync_log: audit of every dataset sync run.
 *  - initial import: Tanzania wards + streets from the vendored mtaa CSV
 *    dataset (data/locations/tz) via the OOP sync engine.
 * Idempotent: schema checks + the sync engine skips already-imported rows.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: location engine (wards/villages/sync)...\n";

try {
    // 1. wards.district_id + ward_code + is_active
    $cols = [
        'district_id' => "ALTER TABLE wards ADD COLUMN district_id INT NULL AFTER ward_name, ADD INDEX idx_wards_district (district_id)",
        'ward_code'   => "ALTER TABLE wards ADD COLUMN ward_code VARCHAR(20) NULL AFTER district_id",
        'is_active'   => "ALTER TABLE wards ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER ward_code",
    ];
    foreach ($cols as $col => $sql) {
        $exists = $pdo->query("SHOW COLUMNS FROM wards LIKE '$col'")->fetch();
        if ($exists) {
            echo "  · wards.$col already exists, skipping.\n";
        } else {
            $pdo->exec($sql);
            echo "  + added wards.$col.\n";
        }
    }

    // Deactivate legacy council-keyed rows (free-typed junk from the old flow).
    $n = $pdo->exec("UPDATE wards SET is_active = 0 WHERE district_id IS NULL AND is_active = 1");
    if ($n > 0) {
        echo "  ~ deactivated $n legacy council-keyed ward rows.\n";
    }

    // Unique per district for idempotent imports (NULL district_id rows exempt by MySQL semantics).
    $idx = $pdo->query("SHOW INDEX FROM wards WHERE Key_name = 'uq_ward_per_district'")->fetch();
    if (!$idx) {
        $pdo->exec("ALTER TABLE wards ADD UNIQUE KEY uq_ward_per_district (district_id, ward_name)");
        echo "  + added unique key wards(district_id, ward_name).\n";
    } else {
        echo "  · unique key uq_ward_per_district already exists, skipping.\n";
    }

    // 2. villages
    $pdo->exec("CREATE TABLE IF NOT EXISTS villages (
        village_id INT AUTO_INCREMENT PRIMARY KEY,
        village_name VARCHAR(150) NOT NULL,
        ward_id INT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_village_per_ward (ward_id, village_name),
        INDEX idx_villages_ward (ward_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + villages table ready.\n";

    // 3. location_sync_log
    $pdo->exec("CREATE TABLE IF NOT EXISTS location_sync_log (
        sync_id INT AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(50) NOT NULL,
        version VARCHAR(100) NOT NULL,
        status ENUM('success','failed') NOT NULL,
        report MEDIUMTEXT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NOT NULL,
        created_by INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  + location_sync_log table ready.\n";

    // 4. Frame corrections: two districts gazetted after our frame was seeded
    //    (Tanganyika DC ~2015 in Katavi, Kibiti DC ~2016 in Pwani, both split
    //    from existing districts). Added so the dataset's wards land correctly.
    $frameFixes = [
        ['Katavi', 'Tanganyika District', 'TZ-64'],
        ['Pwani',  'Kibiti District',     'TZ-208'],
    ];
    foreach ($frameFixes as [$regionName, $districtName, $code]) {
        $rid = $pdo->prepare("SELECT region_id FROM regions WHERE region_name = ?");
        $rid->execute([$regionName]);
        $regionId = $rid->fetchColumn();
        if (!$regionId) {
            echo "  ! region $regionName not found — skipped frame fix for $districtName.\n";
            continue;
        }
        $chk = $pdo->prepare("SELECT COUNT(*) FROM districts WHERE region_id = ? AND district_name = ?");
        $chk->execute([$regionId, $districtName]);
        if ($chk->fetchColumn() > 0) {
            echo "  · district $districtName already present, skipping.\n";
        } else {
            $pdo->prepare("INSERT INTO districts (district_name, district_code, region_id, is_active) VALUES (?, ?, ?, 1)")
                ->execute([$districtName, $code, $regionId]);
            echo "  + added missing district: $districtName ($regionName).\n";
        }
    }

    // 5. Initial import from the vendored dataset (skips anything already present).
    require_once __DIR__ . '/../core/Location/bootstrap.php';
    echo "  … running initial sync from mtaa CSV dataset (this can take a minute)…\n";
    $sync = new LocationSyncService($pdo);
    $report = $sync->sync(new MtaaCsvProvider());

    echo "    rows read:           {$report['rows_read']}\n";
    echo "    regions matched:     " . count($report['regions_matched']) . "\n";
    echo "    districts matched:   {$report['districts_matched']}\n";
    echo "    wards inserted:      {$report['wards_inserted']} (existing: {$report['wards_existing']})\n";
    echo "    villages inserted:   {$report['villages_inserted']} (existing: {$report['villages_existing']})\n";
    if ($report['districts_unmatched']) {
        echo "    UNMATCHED districts (" . count($report['districts_unmatched']) . "):\n";
        foreach ($report['districts_unmatched'] as $d) {
            echo "      - $d\n";
        }
    }
    if ($report['regions_without_data']) {
        echo "    regions without dataset coverage: " . implode(', ', $report['regions_without_data']) . "\n";
    }

    echo "\nMigration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
