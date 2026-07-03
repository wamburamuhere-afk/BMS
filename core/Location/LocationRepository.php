<?php
/**
 * Read side of the location engine.
 *
 * Every form, API, or report that needs location dropdowns calls this —
 * never raw SQL against the location tables. All lookups read the LOCAL
 * reference tables (countries → regions → districts → wards → villages),
 * which are populated/refreshed by LocationSyncService from an
 * authoritative provider. Forms never depend on an external API at
 * request time.
 */
class LocationRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * All countries (East African community list), Tanzania first.
     * @return array<int, array{id:int, name:string, code:string}>
     */
    public function countries(?string $q = null): array
    {
        // has_regions drives the UI mode per country: 1 = defined dropdowns
        // exist locally (select mode), 0 = free-text entry.
        $sql = "SELECT c.country_id AS id, c.country_name AS name, c.country_code AS code,
                       EXISTS(SELECT 1 FROM regions r WHERE r.country_id = c.country_id AND r.is_active = 1) AS has_regions
                FROM countries c";
        $params = [];
        if ($q !== null && $q !== '') {
            $sql .= " WHERE c.country_name LIKE ?";
            $params[] = "%$q%";
        }
        $sql .= " ORDER BY (c.country_name = 'Tanzania') DESC, c.country_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{id:int, name:string, code:string}> */
    public function regionsOf(int $countryId, ?string $q = null): array
    {
        $sql = "SELECT region_id AS id, region_name AS name, region_code AS code
                FROM regions WHERE country_id = ? AND is_active = 1";
        $params = [$countryId];
        if ($q !== null && $q !== '') {
            $sql .= " AND region_name LIKE ?";
            $params[] = "%$q%";
        }
        $sql .= " ORDER BY region_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{id:int, name:string, code:string}> */
    public function districtsOf(int $regionId, ?string $q = null): array
    {
        $sql = "SELECT district_id AS id, district_name AS name, district_code AS code
                FROM districts WHERE region_id = ? AND is_active = 1";
        $params = [$regionId];
        if ($q !== null && $q !== '') {
            $sql .= " AND district_name LIKE ?";
            $params[] = "%$q%";
        }
        $sql .= " ORDER BY district_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{id:int, name:string, code:?string}> */
    public function wardsOf(int $districtId, ?string $q = null): array
    {
        $sql = "SELECT ward_id AS id, ward_name AS name, ward_code AS code
                FROM wards WHERE district_id = ? AND is_active = 1";
        $params = [$districtId];
        if ($q !== null && $q !== '') {
            $sql .= " AND ward_name LIKE ?";
            $params[] = "%$q%";
        }
        $sql .= " ORDER BY ward_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{id:int, name:string}> */
    public function villagesOf(int $wardId, ?string $q = null): array
    {
        $sql = "SELECT village_id AS id, village_name AS name
                FROM villages WHERE ward_id = ? AND is_active = 1";
        $params = [$wardId];
        if ($q !== null && $q !== '') {
            $sql .= " AND village_name LIKE ?";
            $params[] = "%$q%";
        }
        $sql .= " ORDER BY village_name";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findCountryByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT country_id AS id, country_name AS name, country_code AS code
             FROM countries WHERE country_name = ? LIMIT 1"
        );
        $stmt->execute([trim($name)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** True when a country has defined subdivisions (drives select vs free-text mode). */
    public function countryHasRegions(int $countryId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM regions WHERE country_id = ? AND is_active = 1"
        );
        $stmt->execute([$countryId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    // ── Single-row parent lookups used by LocationService::validateChain() ──

    public function regionParent(int $regionId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT country_id FROM regions WHERE region_id = ?");
        $stmt->execute([$regionId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    public function districtParent(int $districtId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT region_id FROM districts WHERE district_id = ?");
        $stmt->execute([$districtId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int)$v;
    }

    public function wardParent(int $wardId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT district_id FROM wards WHERE ward_id = ?");
        $stmt->execute([$wardId]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? null : (int)$v;
    }

    public function villageParent(int $villageId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT ward_id FROM villages WHERE village_id = ?");
        $stmt->execute([$villageId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int)$v;
    }
}
