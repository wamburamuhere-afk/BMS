<?php
/**
 * Provider for the vendored Tanzania locations dataset (data/locations/tz/*.csv).
 *
 * Source: https://github.com/Kalebu/mtaa (MIT), itself derived from
 * https://github.com/HackEAC/tanzania-locations-db — community-standardized
 * from the NBS census frame. One CSV per mainland region, flat rows:
 *   region,regioncode,district,districtcode,ward,wardcode,street,places
 *
 * Mainland-only (26 regions). Zanzibar's 5 regions have no rows here and
 * stay ward-less until a fuller dataset (e.g. official NBS) is dropped in —
 * the sync report calls this out.
 */
require_once __DIR__ . '/LocationProviderInterface.php';

class MtaaCsvProvider implements LocationProviderInterface
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? dirname(__DIR__, 3) . '/data/locations/tz';
    }

    public function name(): string
    {
        return 'mtaa-csv';
    }

    public function version(): string
    {
        // Fingerprint the dataset so the sync log records exactly what was imported.
        $files = glob($this->dir . '/*.csv') ?: [];
        $sig = '';
        foreach ($files as $f) {
            $sig .= basename($f) . ':' . filesize($f) . ';';
        }
        return 'mtaa-csv@' . substr(md5($sig), 0, 12) . ' (' . count($files) . ' files)';
    }

    public function rows(): \Generator
    {
        $files = glob($this->dir . '/*.csv') ?: [];
        if (!$files) {
            throw new RuntimeException("MtaaCsvProvider: no CSV files found in {$this->dir}");
        }
        foreach ($files as $file) {
            $h = fopen($file, 'r');
            if (!$h) {
                throw new RuntimeException("MtaaCsvProvider: cannot open $file");
            }
            fgetcsv($h); // header row
            while (($row = fgetcsv($h)) !== false) {
                if (count($row) < 7) {
                    continue; // malformed line
                }
                [$region, , $district, , $ward, $wardCode, $street] = $row;
                if (trim((string)$region) === '' || trim((string)$district) === '' || trim((string)$ward) === '') {
                    continue;
                }
                yield [
                    'region'    => $region,
                    'district'  => $district,
                    'ward'      => $ward,
                    'ward_code' => trim((string)$wardCode) !== '' ? trim((string)$wardCode) : null,
                    'street'    => trim((string)$street) !== '' ? $street : null,
                ];
            }
            fclose($h);
        }
    }

    public function districtAliases(): array
    {
        return [
            // Old/alternate dataset names → the official frame's base name.
            'ARUMERU'      => 'MERU',
            'KIGOMA CBD'   => 'KIGOMA UJIJI CBD',
            'MPANDA CBD'   => 'MPANDA TOWN CBD',
            'WANGINGO MBE' => 'WANGINGOMBE',
        ];
    }
}
