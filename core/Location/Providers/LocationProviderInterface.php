<?php
/**
 * Contract every location data provider must fulfil.
 *
 * A provider is an adapter over one authoritative dataset/API
 * (NBS census frame, UN OCHA COD-AB, GeoNames, the mtaa CSV dump, …).
 * The sync engine (LocationSyncService) only ever talks to this
 * interface, so swapping or adding data sources never touches the
 * rest of the system — new administrative areas arrive by re-running
 * a sync against a newer dataset, not by editing source code.
 */
interface LocationProviderInterface
{
    /** Short machine name of the provider, e.g. 'mtaa-csv'. */
    public function name(): string;

    /** Dataset version tag recorded in the sync log, e.g. 'mtaa-csv@2026-07'. */
    public function version(): string;

    /**
     * Stream the dataset one row at a time (memory-safe for large dumps).
     *
     * Each yielded row is an associative array:
     *   ['region' => string, 'district' => string,
     *    'ward' => string, 'ward_code' => string|null, 'street' => string|null]
     *
     * Names are raw as published by the source; the sync engine
     * normalizes and matches them against the official local tables.
     */
    public function rows(): \Generator;

    /**
     * Dataset-specific district name corrections, applied BEFORE the
     * generic matcher runs. Maps a normalized dataset name to the
     * normalized name it should be treated as, e.g.
     *   ['ARUMERU' => 'MERU', 'KIGOMA CBD' => 'KIGOMA UJIJI CBD']
     */
    public function districtAliases(): array;
}
