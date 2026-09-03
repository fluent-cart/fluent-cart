<?php if ( ! defined( 'ABSPATH' ) ) exit;

return [
    "AT" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'MwSt',
            ],
            [
                'rate'     => 13,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "BE" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 21,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'BTW/TVA',
            ],
            [
                'rate'     => 6,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "BG" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'DDS',
            ],
            [
                'rate'     => 9,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "HR" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 25,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'PDV',
            ],
            [
                'rate'     => 13,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "CY" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 19,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'VAT',
            ],
            [
                'rate'     => 9,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "CZ" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 21,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'DPH',
            ],
            [
                'rate'     => 15,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "DK" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 25,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'moms',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "EE" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 24,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'KM',
            ],
            [
                'rate'     => 9,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "FI" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 25.5,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'ALV',
            ],
            [
                'rate'     => 14,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "FR" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'TVA',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
            [
                'rate'     => 8.5,
                'compound' => false,
                'state'    => 'GP',
                'name'     => 'Guadeloupe Standard',
                'type'     => 'standard',
            ],
            [
                'rate'     => 2.1,
                'compound' => false,
                'state'    => 'GP',
                'name'     => 'Guadeloupe Reduced',
                'type'     => 'reduced',
            ],
            [
                'rate'     => 8.5,
                'compound' => false,
                'state'    => 'MQ',
                'name'     => 'Martinique Standard',
                'type'     => 'standard',
            ],
            [
                'rate'     => 2.1,
                'compound' => false,
                'state'    => 'MQ',
                'name'     => 'Martinique Reduced',
                'type'     => 'reduced',
            ],
            [
                'rate'     => 8.5,
                'compound' => false,
                'state'    => 'RE',
                'name'     => 'Réunion Standard',
                'type'     => 'standard',
            ],
            [
                'rate'     => 2.1,
                'compound' => false,
                'state'    => 'RE',
                'name'     => 'Réunion Reduced',
                'type'     => 'reduced',
            ],
        ],
    ],
    "DE" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 19,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'MwSt',
            ],
            [
                'rate'     => 7,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "GR" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 24,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'FPA',
            ],
            [
                'rate'     => 13,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "HU" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 27,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'AFA',
            ],
            [
                'rate'     => 18,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "IE" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 23,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'VAT',
            ],
            [
                'rate'     => 13.5,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "IT" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 22,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'IVA',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "LV" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 21,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'PVN',
            ],
            [
                'rate'     => 12,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "LT" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 21,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'PVM',
            ],
            [
                'rate'     => 9,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "LU" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 17,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'TVA',
            ],
            [
                'rate'     => 8,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "MT" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 18,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'VAT',
            ],
            [
                'rate'     => 7,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "NL" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 21,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'BTW',
            ],
            [
                'rate'     => 9,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "PL" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 23,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'VAT',
            ],
            [
                'rate'     => 8,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "PT" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 23,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'IVA',
            ],
            [
                'rate'     => 13,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
            [
                'rate'     => 23,
                'compound' => false,
                'state'    => 'PT-Mainland',
                'name'     => 'Portugal Mainland Standard',
                'type'     => 'standard',
            ],
            [
                'rate'     => 6,
                'compound' => false,
                'state'    => 'PT-Mainland',
                'name'     => 'Portugal Mainland Reduced',
                'type'     => 'reduced',
            ],
            [
                'rate'     => 22,
                'compound' => false,
                'state'    => 'PT-Madeira',
                'name'     => 'Madeira Standard',
                'type'     => 'standard',
            ],
            [
                'rate'     => 4,
                'compound' => false,
                'state'    => 'PT-Madeira',
                'name'     => 'Madeira Reduced',
                'type'     => 'reduced',
            ],
            [
                'rate'     => 16,
                'compound' => false,
                'state'    => 'PT-Azores',
                'name'     => 'Azores Standard',
                'type'     => 'standard',
            ],
            [
                'rate'     => 4,
                'compound' => false,
                'state'    => 'PT-Azores',
                'name'     => 'Azores Reduced',
                'type'     => 'reduced',
            ],
        ],
    ],
    "RO" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 21,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'TVA',
            ],
            [
                'rate'     => 9,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "SK" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 23,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'DPH',
            ],
            [
                'rate'     => 19,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "SI" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 22,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'DDV',
            ],
            [
                'rate'     => 9.5,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "ES" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 21,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'IVA',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
            [
                'rate'     => 21,
                'compound' => false,
                'state'    => 'ES-Mainland',
                'name'     => 'Spain Mainland Standard',
                'type'     => 'standard',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'state'    => 'ES-Mainland',
                'name'     => 'Spain Mainland Reduced',
                'type'     => 'reduced',
            ],
            [
                'rate'     => 7,
                'compound' => false,
                'state'    => 'ES-Canary',
                'name'     => 'Canary Islands IGIC Standard',
                'type'     => 'standard',
            ],
            [
                'rate'     => 3,
                'compound' => false,
                'state'    => 'ES-Canary',
                'name'     => 'Canary Islands IGIC Reduced',
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'state'    => 'ES-Canary',
                'name'     => 'Canary Islands IGIC Zero',
                'type'     => 'zero',
            ],
            [
                'rate'     => 4,
                'compound' => false,
                'state'    => 'ES-CeutaMelilla',
                'name'     => 'Ceuta & Melilla IPSI General',
                'type'     => 'standard',
            ],
        ],
    ],
    "SE" => [
        "group" => "EU",
        "tax"   => [
            [
                'rate'     => 25,
                'compound' => false,
                'type'     => 'standard',
                'name'     => 'moms',
            ],
            [
                'rate'     => 12,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "GB" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 5,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "XI" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 5,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "NO" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 25,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 15,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "CH" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 8.1,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 2.6,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "IS" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 24,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 11,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "LI" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 8.1,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 2.6,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "MC" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "AD" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 4.5,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 1,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "AL" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 6,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "BA" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 17,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "MK" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 18,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 5,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "RS" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "ME" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 21,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 7,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "TR" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "UA" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 7,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "RU" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "BY" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "MD" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 12,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "SM" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 22,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 10,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "VA" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "GI" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "JE" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 5,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "GG" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "IM" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 20,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 5,
                'compound' => false,
                'type'     => 'reduced',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "FO" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 25,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "SJ" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'standard',
            ],
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "AX" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
    "BV" => [
        "group" => "REST",
        "tax"   => [
            [
                'rate'     => 0,
                'compound' => false,
                'type'     => 'zero',
            ],
        ],
    ],
];
