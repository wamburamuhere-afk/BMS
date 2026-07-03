<?php
/**
 * Seed the full official Tanzania frame (countries → 31 regions → 177
 * districts) on any database where it is missing or partial.
 *
 * Some production sites (bms, bejus) were seeded with only a partial
 * regions/districts frame, so the location engine's dataset sync could only
 * match a fraction of the wards/streets there. This migration completes the
 * frame by NAME (idempotent — existing rows are left untouched), then
 * re-runs the location sync so the newly matchable wards/villages import.
 *
 * Data exported from the reference frame (NBS-derived) on 2026-07-03.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

require_once __DIR__ . '/../roots.php';
global $pdo;

echo "Starting migration: seed full Tanzania location frame...\n";

$frame = array (
  'countries' => 
  array (
    0 => 
    array (
      'country_name' => 'Tanzania',
      'country_code' => 'TZ',
      'phone_code' => '+255',
      'currency_code' => 'TZS',
      'currency_symbol' => 'TSh',
      'is_active' => 1,
    ),
    1 => 
    array (
      'country_name' => 'Kenya',
      'country_code' => 'KE',
      'phone_code' => '+254',
      'currency_code' => 'KES',
      'currency_symbol' => 'KSh',
      'is_active' => 0,
    ),
    2 => 
    array (
      'country_name' => 'Uganda',
      'country_code' => 'UG',
      'phone_code' => '+256',
      'currency_code' => 'UGX',
      'currency_symbol' => 'USh',
      'is_active' => 0,
    ),
    3 => 
    array (
      'country_name' => 'Rwanda',
      'country_code' => 'RW',
      'phone_code' => '+250',
      'currency_code' => 'RWF',
      'currency_symbol' => 'FRw',
      'is_active' => 0,
    ),
    4 => 
    array (
      'country_name' => 'Burundi',
      'country_code' => 'BI',
      'phone_code' => '+257',
      'currency_code' => 'BIF',
      'currency_symbol' => 'FBu',
      'is_active' => 0,
    ),
    5 => 
    array (
      'country_name' => 'South Sudan',
      'country_code' => 'SS',
      'phone_code' => '+211',
      'currency_code' => 'SSP',
      'currency_symbol' => '£',
      'is_active' => 0,
    ),
    6 => 
    array (
      'country_name' => 'Democratic Republic of Congo',
      'country_code' => 'CD',
      'phone_code' => '+243',
      'currency_code' => 'CDF',
      'currency_symbol' => 'FC',
      'is_active' => 0,
    ),
    7 => 
    array (
      'country_name' => 'Zambia',
      'country_code' => 'ZM',
      'phone_code' => '+260',
      'currency_code' => 'ZMW',
      'currency_symbol' => 'ZK',
      'is_active' => 0,
    ),
    8 => 
    array (
      'country_name' => 'Malawi',
      'country_code' => 'MW',
      'phone_code' => '+265',
      'currency_code' => 'MWK',
      'currency_symbol' => 'MK',
      'is_active' => 0,
    ),
    9 => 
    array (
      'country_name' => 'Mozambique',
      'country_code' => 'MZ',
      'phone_code' => '+258',
      'currency_code' => 'MZN',
      'currency_symbol' => 'MT',
      'is_active' => 0,
    ),
  ),
  'regions' => 
  array (
    0 => 
    array (
      'region_name' => 'Arusha',
      'region_code' => 'TZ-01',
    ),
    1 => 
    array (
      'region_name' => 'Dar es Salaam',
      'region_code' => 'TZ-02',
    ),
    2 => 
    array (
      'region_name' => 'Dodoma',
      'region_code' => 'TZ-03',
    ),
    3 => 
    array (
      'region_name' => 'Geita',
      'region_code' => 'TZ-27',
    ),
    4 => 
    array (
      'region_name' => 'Iringa',
      'region_code' => 'TZ-04',
    ),
    5 => 
    array (
      'region_name' => 'Kagera',
      'region_code' => 'TZ-05',
    ),
    6 => 
    array (
      'region_name' => 'Katavi',
      'region_code' => 'TZ-28',
    ),
    7 => 
    array (
      'region_name' => 'Kigoma',
      'region_code' => 'TZ-08',
    ),
    8 => 
    array (
      'region_name' => 'Kilimanjaro',
      'region_code' => 'TZ-09',
    ),
    9 => 
    array (
      'region_name' => 'Lindi',
      'region_code' => 'TZ-12',
    ),
    10 => 
    array (
      'region_name' => 'Manyara',
      'region_code' => 'TZ-26',
    ),
    11 => 
    array (
      'region_name' => 'Mara',
      'region_code' => 'TZ-13',
    ),
    12 => 
    array (
      'region_name' => 'Mbeya',
      'region_code' => 'TZ-14',
    ),
    13 => 
    array (
      'region_name' => 'Mjini Magharibi',
      'region_code' => 'TZ-15',
    ),
    14 => 
    array (
      'region_name' => 'Morogoro',
      'region_code' => 'TZ-16',
    ),
    15 => 
    array (
      'region_name' => 'Mtwara',
      'region_code' => 'TZ-17',
    ),
    16 => 
    array (
      'region_name' => 'Mwanza',
      'region_code' => 'TZ-18',
    ),
    17 => 
    array (
      'region_name' => 'Njombe',
      'region_code' => 'TZ-29',
    ),
    18 => 
    array (
      'region_name' => 'Pemba North',
      'region_code' => 'TZ-06',
    ),
    19 => 
    array (
      'region_name' => 'Pemba South',
      'region_code' => 'TZ-10',
    ),
    20 => 
    array (
      'region_name' => 'Pwani',
      'region_code' => 'TZ-19',
    ),
    21 => 
    array (
      'region_name' => 'Rukwa',
      'region_code' => 'TZ-20',
    ),
    22 => 
    array (
      'region_name' => 'Ruvuma',
      'region_code' => 'TZ-21',
    ),
    23 => 
    array (
      'region_name' => 'Shinyanga',
      'region_code' => 'TZ-22',
    ),
    24 => 
    array (
      'region_name' => 'Simiyu',
      'region_code' => 'TZ-30',
    ),
    25 => 
    array (
      'region_name' => 'Singida',
      'region_code' => 'TZ-23',
    ),
    26 => 
    array (
      'region_name' => 'Songwe',
      'region_code' => 'TZ-31',
    ),
    27 => 
    array (
      'region_name' => 'Tabora',
      'region_code' => 'TZ-24',
    ),
    28 => 
    array (
      'region_name' => 'Tanga',
      'region_code' => 'TZ-25',
    ),
    29 => 
    array (
      'region_name' => 'Zanzibar North',
      'region_code' => 'TZ-07',
    ),
    30 => 
    array (
      'region_name' => 'Zanzibar South',
      'region_code' => 'TZ-11',
    ),
  ),
  'districts' => 
  array (
    0 => 
    array (
      'district_name' => 'Arusha City',
      'district_code' => 'TZ-01',
      'region_name' => 'Arusha',
    ),
    1 => 
    array (
      'district_name' => 'Arusha District',
      'district_code' => 'TZ-02',
      'region_name' => 'Arusha',
    ),
    2 => 
    array (
      'district_name' => 'Karatu District',
      'district_code' => 'TZ-03',
      'region_name' => 'Arusha',
    ),
    3 => 
    array (
      'district_name' => 'Longido District',
      'district_code' => 'TZ-04',
      'region_name' => 'Arusha',
    ),
    4 => 
    array (
      'district_name' => 'Meru District',
      'district_code' => 'TZ-05',
      'region_name' => 'Arusha',
    ),
    5 => 
    array (
      'district_name' => 'Monduli District',
      'district_code' => 'TZ-06',
      'region_name' => 'Arusha',
    ),
    6 => 
    array (
      'district_name' => 'Ngorongoro District',
      'district_code' => 'TZ-07',
      'region_name' => 'Arusha',
    ),
    7 => 
    array (
      'district_name' => 'Ilala District',
      'district_code' => 'TZ-11',
      'region_name' => 'Dar es Salaam',
    ),
    8 => 
    array (
      'district_name' => 'Kigamboni District',
      'district_code' => 'TZ-15',
      'region_name' => 'Dar es Salaam',
    ),
    9 => 
    array (
      'district_name' => 'Kinondoni District',
      'district_code' => 'TZ-12',
      'region_name' => 'Dar es Salaam',
    ),
    10 => 
    array (
      'district_name' => 'Temeke District',
      'district_code' => 'TZ-13',
      'region_name' => 'Dar es Salaam',
    ),
    11 => 
    array (
      'district_name' => 'Ubungo District',
      'district_code' => 'TZ-14',
      'region_name' => 'Dar es Salaam',
    ),
    12 => 
    array (
      'district_name' => 'Bahi District',
      'district_code' => 'TZ-22',
      'region_name' => 'Dodoma',
    ),
    13 => 
    array (
      'district_name' => 'Chamwino District',
      'district_code' => 'TZ-23',
      'region_name' => 'Dodoma',
    ),
    14 => 
    array (
      'district_name' => 'Chemba District',
      'district_code' => 'TZ-24',
      'region_name' => 'Dodoma',
    ),
    15 => 
    array (
      'district_name' => 'Dodoma City',
      'district_code' => 'TZ-21',
      'region_name' => 'Dodoma',
    ),
    16 => 
    array (
      'district_name' => 'Kondoa District',
      'district_code' => 'TZ-25',
      'region_name' => 'Dodoma',
    ),
    17 => 
    array (
      'district_name' => 'Kongwa District',
      'district_code' => 'TZ-26',
      'region_name' => 'Dodoma',
    ),
    18 => 
    array (
      'district_name' => 'Mpwapwa District',
      'district_code' => 'TZ-27',
      'region_name' => 'Dodoma',
    ),
    19 => 
    array (
      'district_name' => 'Bukombe District',
      'district_code' => 'TZ-31',
      'region_name' => 'Geita',
    ),
    20 => 
    array (
      'district_name' => 'Chato District',
      'district_code' => 'TZ-32',
      'region_name' => 'Geita',
    ),
    21 => 
    array (
      'district_name' => 'Geita District',
      'district_code' => 'TZ-34',
      'region_name' => 'Geita',
    ),
    22 => 
    array (
      'district_name' => 'Geita Town',
      'district_code' => 'TZ-33',
      'region_name' => 'Geita',
    ),
    23 => 
    array (
      'district_name' => 'Mbogwe District',
      'district_code' => 'TZ-35',
      'region_name' => 'Geita',
    ),
    24 => 
    array (
      'district_name' => 'Nyang\'hwale District',
      'district_code' => 'TZ-36',
      'region_name' => 'Geita',
    ),
    25 => 
    array (
      'district_name' => 'Iringa District',
      'district_code' => 'TZ-41',
      'region_name' => 'Iringa',
    ),
    26 => 
    array (
      'district_name' => 'Iringa Municipal',
      'district_code' => 'TZ-42',
      'region_name' => 'Iringa',
    ),
    27 => 
    array (
      'district_name' => 'Kilolo District',
      'district_code' => 'TZ-43',
      'region_name' => 'Iringa',
    ),
    28 => 
    array (
      'district_name' => 'Mafinga Town',
      'district_code' => 'TZ-44',
      'region_name' => 'Iringa',
    ),
    29 => 
    array (
      'district_name' => 'Mufindi District',
      'district_code' => 'TZ-45',
      'region_name' => 'Iringa',
    ),
    30 => 
    array (
      'district_name' => 'Biharamulo District',
      'district_code' => 'TZ-51',
      'region_name' => 'Kagera',
    ),
    31 => 
    array (
      'district_name' => 'Bukoba District',
      'district_code' => 'TZ-52',
      'region_name' => 'Kagera',
    ),
    32 => 
    array (
      'district_name' => 'Bukoba Municipal',
      'district_code' => 'TZ-53',
      'region_name' => 'Kagera',
    ),
    33 => 
    array (
      'district_name' => 'Karagwe District',
      'district_code' => 'TZ-54',
      'region_name' => 'Kagera',
    ),
    34 => 
    array (
      'district_name' => 'Kyerwa District',
      'district_code' => 'TZ-55',
      'region_name' => 'Kagera',
    ),
    35 => 
    array (
      'district_name' => 'Missenyi District',
      'district_code' => 'TZ-56',
      'region_name' => 'Kagera',
    ),
    36 => 
    array (
      'district_name' => 'Muleba District',
      'district_code' => 'TZ-57',
      'region_name' => 'Kagera',
    ),
    37 => 
    array (
      'district_name' => 'Ngara District',
      'district_code' => 'TZ-58',
      'region_name' => 'Kagera',
    ),
    38 => 
    array (
      'district_name' => 'Mlele District',
      'district_code' => 'TZ-61',
      'region_name' => 'Katavi',
    ),
    39 => 
    array (
      'district_name' => 'Mpanda District',
      'district_code' => 'TZ-62',
      'region_name' => 'Katavi',
    ),
    40 => 
    array (
      'district_name' => 'Mpanda Town',
      'district_code' => 'TZ-63',
      'region_name' => 'Katavi',
    ),
    41 => 
    array (
      'district_name' => 'Tanganyika District',
      'district_code' => 'TZ-64',
      'region_name' => 'Katavi',
    ),
    42 => 
    array (
      'district_name' => 'Buhigwe District',
      'district_code' => 'TZ-71',
      'region_name' => 'Kigoma',
    ),
    43 => 
    array (
      'district_name' => 'Kakonko District',
      'district_code' => 'TZ-72',
      'region_name' => 'Kigoma',
    ),
    44 => 
    array (
      'district_name' => 'Kasulu District',
      'district_code' => 'TZ-73',
      'region_name' => 'Kigoma',
    ),
    45 => 
    array (
      'district_name' => 'Kasulu Town',
      'district_code' => 'TZ-74',
      'region_name' => 'Kigoma',
    ),
    46 => 
    array (
      'district_name' => 'Kibondo District',
      'district_code' => 'TZ-75',
      'region_name' => 'Kigoma',
    ),
    47 => 
    array (
      'district_name' => 'Kigoma District',
      'district_code' => 'TZ-76',
      'region_name' => 'Kigoma',
    ),
    48 => 
    array (
      'district_name' => 'Kigoma-Ujiji Municipal',
      'district_code' => 'TZ-77',
      'region_name' => 'Kigoma',
    ),
    49 => 
    array (
      'district_name' => 'Uvinza District',
      'district_code' => 'TZ-78',
      'region_name' => 'Kigoma',
    ),
    50 => 
    array (
      'district_name' => 'Hai District',
      'district_code' => 'TZ-81',
      'region_name' => 'Kilimanjaro',
    ),
    51 => 
    array (
      'district_name' => 'Moshi District',
      'district_code' => 'TZ-82',
      'region_name' => 'Kilimanjaro',
    ),
    52 => 
    array (
      'district_name' => 'Moshi Municipal',
      'district_code' => 'TZ-83',
      'region_name' => 'Kilimanjaro',
    ),
    53 => 
    array (
      'district_name' => 'Mwanga District',
      'district_code' => 'TZ-84',
      'region_name' => 'Kilimanjaro',
    ),
    54 => 
    array (
      'district_name' => 'Rombo District',
      'district_code' => 'TZ-85',
      'region_name' => 'Kilimanjaro',
    ),
    55 => 
    array (
      'district_name' => 'Same District',
      'district_code' => 'TZ-86',
      'region_name' => 'Kilimanjaro',
    ),
    56 => 
    array (
      'district_name' => 'Siha District',
      'district_code' => 'TZ-87',
      'region_name' => 'Kilimanjaro',
    ),
    57 => 
    array (
      'district_name' => 'Kilwa District',
      'district_code' => 'TZ-91',
      'region_name' => 'Lindi',
    ),
    58 => 
    array (
      'district_name' => 'Lindi District',
      'district_code' => 'TZ-92',
      'region_name' => 'Lindi',
    ),
    59 => 
    array (
      'district_name' => 'Lindi Municipal',
      'district_code' => 'TZ-93',
      'region_name' => 'Lindi',
    ),
    60 => 
    array (
      'district_name' => 'Liwale District',
      'district_code' => 'TZ-94',
      'region_name' => 'Lindi',
    ),
    61 => 
    array (
      'district_name' => 'Nachingwea District',
      'district_code' => 'TZ-95',
      'region_name' => 'Lindi',
    ),
    62 => 
    array (
      'district_name' => 'Ruangwa District',
      'district_code' => 'TZ-96',
      'region_name' => 'Lindi',
    ),
    63 => 
    array (
      'district_name' => 'Babati District',
      'district_code' => 'TZ-101',
      'region_name' => 'Manyara',
    ),
    64 => 
    array (
      'district_name' => 'Babati Town',
      'district_code' => 'TZ-102',
      'region_name' => 'Manyara',
    ),
    65 => 
    array (
      'district_name' => 'Hanang\' District',
      'district_code' => 'TZ-103',
      'region_name' => 'Manyara',
    ),
    66 => 
    array (
      'district_name' => 'Kiteto District',
      'district_code' => 'TZ-104',
      'region_name' => 'Manyara',
    ),
    67 => 
    array (
      'district_name' => 'Mbulu District',
      'district_code' => 'TZ-105',
      'region_name' => 'Manyara',
    ),
    68 => 
    array (
      'district_name' => 'Simanjiro District',
      'district_code' => 'TZ-106',
      'region_name' => 'Manyara',
    ),
    69 => 
    array (
      'district_name' => 'Bunda District',
      'district_code' => 'TZ-111',
      'region_name' => 'Mara',
    ),
    70 => 
    array (
      'district_name' => 'Butiama District',
      'district_code' => 'TZ-112',
      'region_name' => 'Mara',
    ),
    71 => 
    array (
      'district_name' => 'Musoma District',
      'district_code' => 'TZ-113',
      'region_name' => 'Mara',
    ),
    72 => 
    array (
      'district_name' => 'Musoma Municipal',
      'district_code' => 'TZ-114',
      'region_name' => 'Mara',
    ),
    73 => 
    array (
      'district_name' => 'Rorya District',
      'district_code' => 'TZ-115',
      'region_name' => 'Mara',
    ),
    74 => 
    array (
      'district_name' => 'Serengeti District',
      'district_code' => 'TZ-116',
      'region_name' => 'Mara',
    ),
    75 => 
    array (
      'district_name' => 'Tarime District',
      'district_code' => 'TZ-117',
      'region_name' => 'Mara',
    ),
    76 => 
    array (
      'district_name' => 'Chunya District',
      'district_code' => 'TZ-121',
      'region_name' => 'Mbeya',
    ),
    77 => 
    array (
      'district_name' => 'Kyela District',
      'district_code' => 'TZ-122',
      'region_name' => 'Mbeya',
    ),
    78 => 
    array (
      'district_name' => 'Mbarali District',
      'district_code' => 'TZ-123',
      'region_name' => 'Mbeya',
    ),
    79 => 
    array (
      'district_name' => 'Mbeya City',
      'district_code' => 'TZ-124',
      'region_name' => 'Mbeya',
    ),
    80 => 
    array (
      'district_name' => 'Mbeya District',
      'district_code' => 'TZ-125',
      'region_name' => 'Mbeya',
    ),
    81 => 
    array (
      'district_name' => 'Rungwe District',
      'district_code' => 'TZ-126',
      'region_name' => 'Mbeya',
    ),
    82 => 
    array (
      'district_name' => 'Magharibi "A" District',
      'district_code' => 'TZ-131',
      'region_name' => 'Mjini Magharibi',
    ),
    83 => 
    array (
      'district_name' => 'Magharibi "B" District',
      'district_code' => 'TZ-132',
      'region_name' => 'Mjini Magharibi',
    ),
    84 => 
    array (
      'district_name' => 'Gairo District',
      'district_code' => 'TZ-141',
      'region_name' => 'Morogoro',
    ),
    85 => 
    array (
      'district_name' => 'Ifakara Town',
      'district_code' => 'TZ-149',
      'region_name' => 'Morogoro',
    ),
    86 => 
    array (
      'district_name' => 'Kilombero District',
      'district_code' => 'TZ-142',
      'region_name' => 'Morogoro',
    ),
    87 => 
    array (
      'district_name' => 'Kilosa District',
      'district_code' => 'TZ-143',
      'region_name' => 'Morogoro',
    ),
    88 => 
    array (
      'district_name' => 'Malinyi District',
      'district_code' => 'TZ-148',
      'region_name' => 'Morogoro',
    ),
    89 => 
    array (
      'district_name' => 'Morogoro District',
      'district_code' => 'TZ-144',
      'region_name' => 'Morogoro',
    ),
    90 => 
    array (
      'district_name' => 'Morogoro Municipal',
      'district_code' => 'TZ-145',
      'region_name' => 'Morogoro',
    ),
    91 => 
    array (
      'district_name' => 'Mvomero District',
      'district_code' => 'TZ-146',
      'region_name' => 'Morogoro',
    ),
    92 => 
    array (
      'district_name' => 'Ulanga District',
      'district_code' => 'TZ-147',
      'region_name' => 'Morogoro',
    ),
    93 => 
    array (
      'district_name' => 'Masasi District',
      'district_code' => 'TZ-151',
      'region_name' => 'Mtwara',
    ),
    94 => 
    array (
      'district_name' => 'Masasi Town',
      'district_code' => 'TZ-152',
      'region_name' => 'Mtwara',
    ),
    95 => 
    array (
      'district_name' => 'Mtwara District',
      'district_code' => 'TZ-153',
      'region_name' => 'Mtwara',
    ),
    96 => 
    array (
      'district_name' => 'Mtwara Municipal',
      'district_code' => 'TZ-154',
      'region_name' => 'Mtwara',
    ),
    97 => 
    array (
      'district_name' => 'Nanyumbu District',
      'district_code' => 'TZ-155',
      'region_name' => 'Mtwara',
    ),
    98 => 
    array (
      'district_name' => 'Newala District',
      'district_code' => 'TZ-156',
      'region_name' => 'Mtwara',
    ),
    99 => 
    array (
      'district_name' => 'Tandahimba District',
      'district_code' => 'TZ-157',
      'region_name' => 'Mtwara',
    ),
    100 => 
    array (
      'district_name' => 'Ilemela District',
      'district_code' => 'TZ-161',
      'region_name' => 'Mwanza',
    ),
    101 => 
    array (
      'district_name' => 'Kwimba District',
      'district_code' => 'TZ-162',
      'region_name' => 'Mwanza',
    ),
    102 => 
    array (
      'district_name' => 'Magu District',
      'district_code' => 'TZ-163',
      'region_name' => 'Mwanza',
    ),
    103 => 
    array (
      'district_name' => 'Misungwi District',
      'district_code' => 'TZ-164',
      'region_name' => 'Mwanza',
    ),
    104 => 
    array (
      'district_name' => 'Nyamagana District',
      'district_code' => 'TZ-165',
      'region_name' => 'Mwanza',
    ),
    105 => 
    array (
      'district_name' => 'Sengerema District',
      'district_code' => 'TZ-166',
      'region_name' => 'Mwanza',
    ),
    106 => 
    array (
      'district_name' => 'Ukerewe District',
      'district_code' => 'TZ-167',
      'region_name' => 'Mwanza',
    ),
    107 => 
    array (
      'district_name' => 'Ludewa District',
      'district_code' => 'TZ-171',
      'region_name' => 'Njombe',
    ),
    108 => 
    array (
      'district_name' => 'Makambako Town',
      'district_code' => 'TZ-172',
      'region_name' => 'Njombe',
    ),
    109 => 
    array (
      'district_name' => 'Makete District',
      'district_code' => 'TZ-173',
      'region_name' => 'Njombe',
    ),
    110 => 
    array (
      'district_name' => 'Njombe District',
      'district_code' => 'TZ-174',
      'region_name' => 'Njombe',
    ),
    111 => 
    array (
      'district_name' => 'Njombe Town',
      'district_code' => 'TZ-175',
      'region_name' => 'Njombe',
    ),
    112 => 
    array (
      'district_name' => 'Wanging\'ombe District',
      'district_code' => 'TZ-176',
      'region_name' => 'Njombe',
    ),
    113 => 
    array (
      'district_name' => 'Micheweni District',
      'district_code' => 'TZ-181',
      'region_name' => 'Pemba North',
    ),
    114 => 
    array (
      'district_name' => 'Wete District',
      'district_code' => 'TZ-182',
      'region_name' => 'Pemba North',
    ),
    115 => 
    array (
      'district_name' => 'Chake Chake District',
      'district_code' => 'TZ-191',
      'region_name' => 'Pemba South',
    ),
    116 => 
    array (
      'district_name' => 'Mkoani District',
      'district_code' => 'TZ-192',
      'region_name' => 'Pemba South',
    ),
    117 => 
    array (
      'district_name' => 'Bagamoyo District',
      'district_code' => 'TZ-201',
      'region_name' => 'Pwani',
    ),
    118 => 
    array (
      'district_name' => 'Kibaha District',
      'district_code' => 'TZ-202',
      'region_name' => 'Pwani',
    ),
    119 => 
    array (
      'district_name' => 'Kibaha Town',
      'district_code' => 'TZ-203',
      'region_name' => 'Pwani',
    ),
    120 => 
    array (
      'district_name' => 'Kibiti District',
      'district_code' => 'TZ-208',
      'region_name' => 'Pwani',
    ),
    121 => 
    array (
      'district_name' => 'Kisarawe District',
      'district_code' => 'TZ-204',
      'region_name' => 'Pwani',
    ),
    122 => 
    array (
      'district_name' => 'Mafia District',
      'district_code' => 'TZ-205',
      'region_name' => 'Pwani',
    ),
    123 => 
    array (
      'district_name' => 'Mkuranga District',
      'district_code' => 'TZ-206',
      'region_name' => 'Pwani',
    ),
    124 => 
    array (
      'district_name' => 'Rufiji District',
      'district_code' => 'TZ-207',
      'region_name' => 'Pwani',
    ),
    125 => 
    array (
      'district_name' => 'Kalambo District',
      'district_code' => 'TZ-211',
      'region_name' => 'Rukwa',
    ),
    126 => 
    array (
      'district_name' => 'Nkasi District',
      'district_code' => 'TZ-212',
      'region_name' => 'Rukwa',
    ),
    127 => 
    array (
      'district_name' => 'Sumbawanga District',
      'district_code' => 'TZ-213',
      'region_name' => 'Rukwa',
    ),
    128 => 
    array (
      'district_name' => 'Sumbawanga Municipal',
      'district_code' => 'TZ-214',
      'region_name' => 'Rukwa',
    ),
    129 => 
    array (
      'district_name' => 'Mbinga District',
      'district_code' => 'TZ-221',
      'region_name' => 'Ruvuma',
    ),
    130 => 
    array (
      'district_name' => 'Namtumbo District',
      'district_code' => 'TZ-225',
      'region_name' => 'Ruvuma',
    ),
    131 => 
    array (
      'district_name' => 'Nyasa District',
      'district_code' => 'TZ-226',
      'region_name' => 'Ruvuma',
    ),
    132 => 
    array (
      'district_name' => 'Songea District',
      'district_code' => 'TZ-222',
      'region_name' => 'Ruvuma',
    ),
    133 => 
    array (
      'district_name' => 'Songea Municipal',
      'district_code' => 'TZ-223',
      'region_name' => 'Ruvuma',
    ),
    134 => 
    array (
      'district_name' => 'Tunduru District',
      'district_code' => 'TZ-224',
      'region_name' => 'Ruvuma',
    ),
    135 => 
    array (
      'district_name' => 'Kahama District',
      'district_code' => 'TZ-231',
      'region_name' => 'Shinyanga',
    ),
    136 => 
    array (
      'district_name' => 'Kahama Town',
      'district_code' => 'TZ-232',
      'region_name' => 'Shinyanga',
    ),
    137 => 
    array (
      'district_name' => 'Kishapu District',
      'district_code' => 'TZ-233',
      'region_name' => 'Shinyanga',
    ),
    138 => 
    array (
      'district_name' => 'Shinyanga District',
      'district_code' => 'TZ-234',
      'region_name' => 'Shinyanga',
    ),
    139 => 
    array (
      'district_name' => 'Shinyanga Municipal',
      'district_code' => 'TZ-235',
      'region_name' => 'Shinyanga',
    ),
    140 => 
    array (
      'district_name' => 'Bariadi District',
      'district_code' => 'TZ-241',
      'region_name' => 'Simiyu',
    ),
    141 => 
    array (
      'district_name' => 'Busega District',
      'district_code' => 'TZ-242',
      'region_name' => 'Simiyu',
    ),
    142 => 
    array (
      'district_name' => 'Itilima District',
      'district_code' => 'TZ-243',
      'region_name' => 'Simiyu',
    ),
    143 => 
    array (
      'district_name' => 'Maswa District',
      'district_code' => 'TZ-244',
      'region_name' => 'Simiyu',
    ),
    144 => 
    array (
      'district_name' => 'Meatu District',
      'district_code' => 'TZ-245',
      'region_name' => 'Simiyu',
    ),
    145 => 
    array (
      'district_name' => 'Ikungi District',
      'district_code' => 'TZ-251',
      'region_name' => 'Singida',
    ),
    146 => 
    array (
      'district_name' => 'Iramba District',
      'district_code' => 'TZ-252',
      'region_name' => 'Singida',
    ),
    147 => 
    array (
      'district_name' => 'Manyoni District',
      'district_code' => 'TZ-253',
      'region_name' => 'Singida',
    ),
    148 => 
    array (
      'district_name' => 'Mkalama District',
      'district_code' => 'TZ-254',
      'region_name' => 'Singida',
    ),
    149 => 
    array (
      'district_name' => 'Singida District',
      'district_code' => 'TZ-255',
      'region_name' => 'Singida',
    ),
    150 => 
    array (
      'district_name' => 'Singida Municipal',
      'district_code' => 'TZ-256',
      'region_name' => 'Singida',
    ),
    151 => 
    array (
      'district_name' => 'Ileje District',
      'district_code' => 'TZ-261',
      'region_name' => 'Songwe',
    ),
    152 => 
    array (
      'district_name' => 'Mbozi District',
      'district_code' => 'TZ-262',
      'region_name' => 'Songwe',
    ),
    153 => 
    array (
      'district_name' => 'Momba District',
      'district_code' => 'TZ-263',
      'region_name' => 'Songwe',
    ),
    154 => 
    array (
      'district_name' => 'Songwe District',
      'district_code' => 'TZ-264',
      'region_name' => 'Songwe',
    ),
    155 => 
    array (
      'district_name' => 'Igunga District',
      'district_code' => 'TZ-271',
      'region_name' => 'Tabora',
    ),
    156 => 
    array (
      'district_name' => 'Kaliua District',
      'district_code' => 'TZ-272',
      'region_name' => 'Tabora',
    ),
    157 => 
    array (
      'district_name' => 'Nzega District',
      'district_code' => 'TZ-273',
      'region_name' => 'Tabora',
    ),
    158 => 
    array (
      'district_name' => 'Sikonge District',
      'district_code' => 'TZ-274',
      'region_name' => 'Tabora',
    ),
    159 => 
    array (
      'district_name' => 'Tabora District',
      'district_code' => 'TZ-275',
      'region_name' => 'Tabora',
    ),
    160 => 
    array (
      'district_name' => 'Tabora Municipal',
      'district_code' => 'TZ-276',
      'region_name' => 'Tabora',
    ),
    161 => 
    array (
      'district_name' => 'Urambo District',
      'district_code' => 'TZ-277',
      'region_name' => 'Tabora',
    ),
    162 => 
    array (
      'district_name' => 'Uyui District',
      'district_code' => 'TZ-278',
      'region_name' => 'Tabora',
    ),
    163 => 
    array (
      'district_name' => 'Handeni District',
      'district_code' => 'TZ-281',
      'region_name' => 'Tanga',
    ),
    164 => 
    array (
      'district_name' => 'Handeni Town',
      'district_code' => 'TZ-282',
      'region_name' => 'Tanga',
    ),
    165 => 
    array (
      'district_name' => 'Kilindi District',
      'district_code' => 'TZ-283',
      'region_name' => 'Tanga',
    ),
    166 => 
    array (
      'district_name' => 'Korogwe District',
      'district_code' => 'TZ-284',
      'region_name' => 'Tanga',
    ),
    167 => 
    array (
      'district_name' => 'Korogwe Town',
      'district_code' => 'TZ-285',
      'region_name' => 'Tanga',
    ),
    168 => 
    array (
      'district_name' => 'Lushoto District',
      'district_code' => 'TZ-286',
      'region_name' => 'Tanga',
    ),
    169 => 
    array (
      'district_name' => 'Mkinga District',
      'district_code' => 'TZ-287',
      'region_name' => 'Tanga',
    ),
    170 => 
    array (
      'district_name' => 'Muheza District',
      'district_code' => 'TZ-288',
      'region_name' => 'Tanga',
    ),
    171 => 
    array (
      'district_name' => 'Pangani District',
      'district_code' => 'TZ-289',
      'region_name' => 'Tanga',
    ),
    172 => 
    array (
      'district_name' => 'Tanga City',
      'district_code' => 'TZ-290',
      'region_name' => 'Tanga',
    ),
    173 => 
    array (
      'district_name' => 'Kaskazini "A" District',
      'district_code' => 'TZ-301',
      'region_name' => 'Zanzibar North',
    ),
    174 => 
    array (
      'district_name' => 'Kaskazini "B" District',
      'district_code' => 'TZ-302',
      'region_name' => 'Zanzibar North',
    ),
    175 => 
    array (
      'district_name' => 'Kati District',
      'district_code' => 'TZ-311',
      'region_name' => 'Zanzibar South',
    ),
    176 => 
    array (
      'district_name' => 'Kusini District',
      'district_code' => 'TZ-312',
      'region_name' => 'Zanzibar South',
    ),
  ),
);

// Normalize a name the same way the sync engine does, so spelling variants
// on partially-seeded sites ("Dar-es-salaam", "DAR ES SALAAM") match the
// frame's canonical row instead of producing a duplicate.
$norm = static function (string $name): string {
    $s = strtoupper(trim($name));
    $s = str_replace(['-', '’', "'", '"', '`'], [' ', '', '', '', ''], $s);
    return preg_replace('/\s+/', ' ', $s);
};

try {
    // ── 1. Countries (East African list, Tanzania active) ───────────────
    $existing = [];
    foreach ($pdo->query("SELECT country_id, country_name FROM countries") as $row) {
        $existing[$norm($row['country_name'])] = (int)$row['country_id'];
    }
    $insCountry = $pdo->prepare(
        "INSERT INTO countries (country_name, country_code, phone_code, currency_code, currency_symbol, is_active)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $added = 0;
    foreach ($frame['countries'] as $c) {
        if (!isset($existing[$norm($c['country_name'])])) {
            $insCountry->execute([$c['country_name'], $c['country_code'], $c['phone_code'], $c['currency_code'], $c['currency_symbol'], $c['is_active']]);
            $existing[$norm($c['country_name'])] = (int)$pdo->lastInsertId();
            $added++;
        }
    }
    echo "  + countries added: $added\n";

    $tzId = $existing[$norm('Tanzania')] ?? 0;
    if (!$tzId) { throw new RuntimeException('Tanzania missing from countries after seed'); }

    // ── 2. Regions (normalized name, under Tanzania) ─────────────────────
    $existing = [];
    foreach ($pdo->query("SELECT region_id, region_name FROM regions WHERE country_id = " . (int)$tzId) as $row) {
        $existing[$norm($row['region_name'])] = (int)$row['region_id'];
    }
    $insRegion = $pdo->prepare("INSERT INTO regions (region_name, region_code, country_id, is_active) VALUES (?, ?, ?, 1)");
    $added = 0; $regionIds = [];
    foreach ($frame['regions'] as $r) {
        $key = $norm($r['region_name']);
        if (!isset($existing[$key])) {
            $insRegion->execute([$r['region_name'], $r['region_code'], $tzId]);
            $existing[$key] = (int)$pdo->lastInsertId();
            $added++;
        }
        $regionIds[$r['region_name']] = $existing[$key];
    }
    echo "  + regions added: $added (total mapped: " . count($regionIds) . ")\n";

    // ── 3. Districts (normalized name, under their region) ───────────────
    $existing = [];
    foreach ($pdo->query("SELECT district_id, district_name, region_id FROM districts") as $row) {
        $existing[$row['region_id'] . '|' . $norm($row['district_name'])] = (int)$row['district_id'];
    }
    $insDistrict = $pdo->prepare("INSERT INTO districts (district_name, district_code, region_id, is_active) VALUES (?, ?, ?, 1)");
    $added = 0; $skippedNoRegion = 0;
    foreach ($frame['districts'] as $d) {
        $rid = $regionIds[$d['region_name']] ?? null;
        if (!$rid) { $skippedNoRegion++; continue; }
        if (!isset($existing[$rid . '|' . $norm($d['district_name'])])) {
            $insDistrict->execute([$d['district_name'], $d['district_code'], $rid]);
            $added++;
        }
    }
    echo "  + districts added: $added" . ($skippedNoRegion ? " (skipped $skippedNoRegion with unknown region)" : '') . "\n";

    // ── 4. Re-run the location sync so newly matchable wards import ─────
    require_once __DIR__ . '/../core/Location/bootstrap.php';
    echo "  … re-running location sync against the completed frame…\n";
    $sync = new LocationSyncService($pdo);
    $report = $sync->sync(new MtaaCsvProvider());
    echo "    wards inserted:      {$report['wards_inserted']} (existing: {$report['wards_existing']})\n";
    echo "    villages inserted:   {$report['villages_inserted']} (existing: {$report['villages_existing']})\n";
    if ($report['districts_unmatched']) {
        echo "    unmatched districts: " . count($report['districts_unmatched']) . "\n";
    }

    echo "\nMigration complete.\n";
} catch (Throwable $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}