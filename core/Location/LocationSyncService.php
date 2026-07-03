<?php
/**
 * Import/refresh engine: pulls a provider's dataset into the local
 * reference tables (wards + villages), matching against the OFFICIAL
 * regions/districts frame already in the DB — the dataset never
 * overwrites the official frame, it only fills the levels below it.
 *
 * Idempotent: re-running the same provider inserts nothing new and is
 * safe on every deploy. New administrative areas therefore arrive by
 * updating the dataset files (or pointing at a newer provider) and
 * re-syncing — never by editing source code.
 *
 * Every run is recorded in location_sync_log with a full report,
 * including unmatched dataset districts so coverage gaps are visible.
 */
require_once __DIR__ . '/Providers/LocationProviderInterface.php';

class LocationSyncService
{
    private PDO $pdo;

    /** Urban dataset names ("X CBD") prefer these qualifiers, in order. */
    private const URBAN_PRIORITY = ['CITY', 'MUNICIPAL', 'TOWN', 'DISTRICT'];
    /** Bare dataset names prefer the rural district first. */
    private const RURAL_PRIORITY = ['DISTRICT', 'CITY', 'MUNICIPAL', 'TOWN'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Run a full sync from the given provider.
     * @return array the report (also persisted to location_sync_log)
     */
    public function sync(LocationProviderInterface $provider, ?int $userId = null): array
    {
        $startedAt = date('Y-m-d H:i:s');
        $report = [
            'provider'            => $provider->name(),
            'version'             => $provider->version(),
            'regions_matched'     => [],
            'regions_unmatched'   => [],
            'districts_matched'   => 0,
            'districts_unmatched' => [],
            'wards_inserted'      => 0,
            'wards_existing'      => 0,
            'villages_inserted'   => 0,
            'villages_existing'   => 0,
            'rows_read'           => 0,
        ];

        // ── Build lookup maps of the OFFICIAL frame ─────────────────────
        $regionMap = [];   // normalized region name → region_id
        foreach ($this->pdo->query("SELECT region_id, region_name FROM regions WHERE is_active = 1") as $r) {
            $regionMap[$this->norm($r['region_name'])] = (int)$r['region_id'];
        }

        // region_id → [ base name → [qualifier → district_id] ]
        $districtMap = [];
        $sql = "SELECT district_id, district_name, region_id FROM districts WHERE is_active = 1";
        foreach ($this->pdo->query($sql) as $d) {
            [$base, $qual] = $this->splitDistrict($this->norm($d['district_name']));
            $districtMap[(int)$d['region_id']][$base][$qual] = (int)$d['district_id'];
        }

        // Existing wards / villages (for idempotency)
        $wardIds = [];     // "district_id|WARD NAME" → ward_id
        foreach ($this->pdo->query("SELECT ward_id, district_id, ward_name FROM wards WHERE district_id IS NOT NULL") as $w) {
            $wardIds[$w['district_id'] . '|' . $this->norm($w['ward_name'])] = (int)$w['ward_id'];
        }
        $villageKeys = []; // "ward_id|VILLAGE NAME" → true
        foreach ($this->pdo->query("SELECT ward_id, village_name FROM villages") as $v) {
            $villageKeys[$v['ward_id'] . '|' . $this->norm($v['village_name'])] = true;
        }

        $aliases = $provider->districtAliases();
        $districtCache = []; // normalized "REGION|DISTRICT" → district_id|null
        $matchedDistricts = [];

        $insertWard = $this->pdo->prepare(
            "INSERT INTO wards (ward_name, ward_code, district_id, is_active, created_at)
             VALUES (?, ?, ?, 1, NOW())"
        );
        $insertVillage = $this->pdo->prepare(
            "INSERT INTO villages (village_name, ward_id, is_active, created_at)
             VALUES (?, ?, 1, NOW())"
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($provider->rows() as $row) {
                $report['rows_read']++;

                $regionKey = $this->norm($row['region']);
                if (!isset($regionMap[$regionKey])) {
                    $report['regions_unmatched'][$regionKey] = true;
                    continue;
                }
                $regionId = $regionMap[$regionKey];
                $report['regions_matched'][$regionKey] = true;

                // ── Resolve the district against the official frame ────
                $districtKeyRaw = $this->norm($row['district']);
                $cacheKey = $regionKey . '|' . $districtKeyRaw;
                if (!array_key_exists($cacheKey, $districtCache)) {
                    $districtCache[$cacheKey] = $this->matchDistrict(
                        $districtKeyRaw,
                        $districtMap[$regionId] ?? [],
                        $aliases
                    );
                    if ($districtCache[$cacheKey] === null) {
                        $report['districts_unmatched'][$regionKey . ' → ' . $districtKeyRaw] = true;
                    } else {
                        $matchedDistricts[$districtCache[$cacheKey]] = true;
                    }
                }
                $districtId = $districtCache[$cacheKey];
                if ($districtId === null) {
                    continue;
                }

                // ── Ward (find-or-insert under the official district) ──
                $wardName = $this->title($row['ward']);
                $wardKey = $districtId . '|' . $this->norm($wardName);
                if (!isset($wardIds[$wardKey])) {
                    $insertWard->execute([$wardName, $row['ward_code'], $districtId]);
                    $wardIds[$wardKey] = (int)$this->pdo->lastInsertId();
                    $report['wards_inserted']++;
                } else {
                    $report['wards_existing']++;
                }
                $wardId = $wardIds[$wardKey];

                // ── Street/Village under the ward ──────────────────────
                if ($row['street'] !== null) {
                    $villageName = $this->title($row['street']);
                    $villageKey = $wardId . '|' . $this->norm($villageName);
                    if (!isset($villageKeys[$villageKey])) {
                        $insertVillage->execute([$villageName, $wardId]);
                        $villageKeys[$villageKey] = true;
                        $report['villages_inserted']++;
                    } else {
                        $report['villages_existing']++;
                    }
                }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $this->log($provider, 'failed', ['error' => $e->getMessage()] + $report, $startedAt, $userId);
            throw $e;
        }

        $report['districts_matched']   = count($matchedDistricts);
        $report['regions_matched']     = array_keys($report['regions_matched']);
        $report['regions_unmatched']   = array_keys($report['regions_unmatched']);
        $report['districts_unmatched'] = array_keys($report['districts_unmatched']);

        // Official regions the dataset never mentioned (e.g. Zanzibar) — visibility, not an error.
        $report['regions_without_data'] = array_values(array_diff(
            array_map([$this, 'norm'], array_keys($regionMap)),
            $report['regions_matched']
        ));

        $this->log($provider, 'success', $report, $startedAt, $userId);
        return $report;
    }

    // ── Matching helpers ────────────────────────────────────────────────

    /** Uppercase, unify separators/quotes, collapse whitespace (incl. embedded newlines). */
    private function norm(string $name): string
    {
        $s = strtoupper(trim($name));
        $s = str_replace(['-', '’', "'", '"', '`'], [' ', '', '', '', ''], $s);
        return preg_replace('/\s+/', ' ', $s);
    }

    /** "KIGOMA UJIJI MUNICIPAL" → ["KIGOMA UJIJI", "MUNICIPAL"]; bare names get qualifier "DISTRICT". */
    private function splitDistrict(string $normName): array
    {
        foreach (['DISTRICT', 'CITY', 'MUNICIPAL', 'TOWN'] as $qual) {
            if (str_ends_with($normName, ' ' . $qual)) {
                return [trim(substr($normName, 0, -strlen($qual) - 1)), $qual];
            }
        }
        return [$normName, 'DISTRICT'];
    }

    /**
     * Resolve a dataset district name to an official district_id within a region.
     * "X CBD" = urban centre → prefer City/Municipal/Town; bare "X" → prefer rural District.
     */
    private function matchDistrict(string $datasetName, array $baseMap, array $aliases): ?int
    {
        if (isset($aliases[$datasetName])) {
            $datasetName = $this->norm($aliases[$datasetName]);
        }

        $urban = false;
        if (str_ends_with($datasetName, ' CBD')) {
            $urban = true;
            $datasetName = trim(substr($datasetName, 0, -4));
        }
        // Dataset may also carry explicit qualifiers — strip to base for lookup.
        [$base, $explicitQual] = $this->splitDistrict($datasetName);

        $candidates = $baseMap[$base] ?? null;
        if ($candidates === null) {
            return null;
        }
        if ($explicitQual !== 'DISTRICT' && isset($candidates[$explicitQual])) {
            return $candidates[$explicitQual];
        }
        $priority = $urban ? self::URBAN_PRIORITY : self::RURAL_PRIORITY;
        foreach ($priority as $qual) {
            if (isset($candidates[$qual])) {
                return $candidates[$qual];
            }
        }
        return null;
    }

    /** Store names in Title Case ("MTAA WA AICC" → "Mtaa Wa Aicc"). */
    private function title(string $name): string
    {
        return ucwords(strtolower(preg_replace('/\s+/', ' ', trim($name))), " \t\r\n\f\v'-");
    }

    private function log(LocationProviderInterface $provider, string $status, array $report, string $startedAt, ?int $userId): void
    {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO location_sync_log (provider, version, status, report, started_at, finished_at, created_by)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?)"
            );
            $stmt->execute([
                $provider->name(),
                $provider->version(),
                $status,
                json_encode($report, JSON_UNESCAPED_UNICODE),
                $startedAt,
                $userId,
            ]);
        } catch (Throwable $e) {
            error_log('location_sync_log write failed: ' . $e->getMessage());
        }
    }
}
