<?php
/**
 * Business rules of the location engine.
 *
 * - Which entry mode a country gets (defined dropdowns vs free text) is
 *   DATA-driven: a country whose regions exist locally is 'select' mode.
 *   Today that's Tanzania only; the day Kenyan counties are imported,
 *   Kenya flips to 'select' automatically — no code change.
 * - Hierarchy integrity: a submitted chain (region → district → ward →
 *   village) must be parent-consistent; a ward id that doesn't belong to
 *   the chosen district is rejected server-side regardless of what the
 *   UI sent.
 */
require_once __DIR__ . '/LocationRepository.php';

class LocationService
{
    public const MODE_SELECT   = 'select';
    public const MODE_FREETEXT = 'freetext';

    private LocationRepository $repo;

    public function __construct(LocationRepository $repo)
    {
        $this->repo = $repo;
    }

    public function repository(): LocationRepository
    {
        return $this->repo;
    }

    /** Entry mode for a country id: defined dropdowns or free-text fields. */
    public function modeForCountry(int $countryId): string
    {
        return $this->repo->countryHasRegions($countryId)
            ? self::MODE_SELECT
            : self::MODE_FREETEXT;
    }

    /** Convenience: same decision from a country name (as party tables store names). */
    public function modeForCountryName(string $countryName): string
    {
        $country = $this->repo->findCountryByName($countryName);
        if (!$country) {
            return self::MODE_FREETEXT; // unknown country → free text
        }
        return $this->modeForCountry((int)$country['id']);
    }

    /**
     * Validate that a selected chain is internally consistent.
     * Pass null for levels not selected; each given level's parent must
     * match the level above it.
     *
     * @throws InvalidArgumentException on any inconsistency.
     */
    public function validateChain(
        ?int $countryId,
        ?int $regionId = null,
        ?int $districtId = null,
        ?int $wardId = null,
        ?int $villageId = null
    ): bool {
        if ($regionId !== null) {
            $parent = $this->repo->regionParent($regionId);
            if ($parent === null) {
                throw new InvalidArgumentException("Unknown region #$regionId");
            }
            if ($countryId !== null && $parent !== $countryId) {
                throw new InvalidArgumentException("Region #$regionId does not belong to country #$countryId");
            }
        }
        if ($districtId !== null) {
            $parent = $this->repo->districtParent($districtId);
            if ($parent === null) {
                throw new InvalidArgumentException("Unknown district #$districtId");
            }
            if ($regionId !== null && $parent !== $regionId) {
                throw new InvalidArgumentException("District #$districtId does not belong to region #$regionId");
            }
        }
        if ($wardId !== null) {
            $parent = $this->repo->wardParent($wardId);
            if ($parent === null) {
                throw new InvalidArgumentException("Unknown or legacy ward #$wardId");
            }
            if ($districtId !== null && $parent !== $districtId) {
                throw new InvalidArgumentException("Ward #$wardId does not belong to district #$districtId");
            }
        }
        if ($villageId !== null) {
            $parent = $this->repo->villageParent($villageId);
            if ($parent === null) {
                throw new InvalidArgumentException("Unknown village #$villageId");
            }
            if ($wardId !== null && $parent !== $wardId) {
                throw new InvalidArgumentException("Village #$villageId does not belong to ward #$wardId");
            }
        }
        return true;
    }
}
