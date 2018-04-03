<?php

$config = [
    
    'script' => [
        'cache' => '/media/[YOURUSBSTICK]/carddav2fb/cache',        // your stick, drive or share designated for caching 
                                                                    // at you Raspberry, on your NAS or ...
    ], 

    'server' => [
        [
            'url' => 'https://...',
            'user' => '',
            'password' => '',
            ],								/* add as many as you need
        [
            'url' => 'https://...',
            'user' => '',
            'password' => '',
            ],								*/
    ],

    'fritzbox' => [
        'url'      => 'fritz.box',
        'user'     => '[USER]',                                     // e.g. 'dslf-config' AVM standard user for usual login
        'password' => '[PASSWORD]',
        'fonpix'   => '/[YOURUSBSTICK]/FRITZ/fonpix',               // the additional usb memory at the Fritz!Box
    ],

    'phonebook' => [
        'id'           => 0,               // only '0' can store quick dial and vanity numbers as well as images 
        'name'         => 'Telefonbuch',
        'imagepath'    => 'file:///var/InternerSpeicher/[YOURUSBSTICK]/FRITZ/fonpix/', // mandatory if you use the -i option
    ],

    'filters' => [
        'include' => [                                           // if empty include all by default
        ],
        'exclude' => [
            'category' => [
                'a', 'b'
            ],
            'group' => [
                'c', 'd'
            ],
        ],
    ],

    'conversions' => [        
        
        'vip' => [
            'categories' => ['VIP'                                  // the category / categories, which should be marked as VIP
            ],
        ],
        
        'realName' => [                                             // are processed consecutively. Order decides!
            '{lastname}, {prefix} {nickname}',
            '{lastname}, {prefix} {firstname}',
            '{lastname}, {nickname}',
            '{lastname}, {firstname}',
            '{organization}',
            '{fullname}'
        ],

        'phoneTypes' => [                                           // you mustn´t define 'fax'!
            'WORK'    => 'work',                                    // this conversion is set fix in code!
            'HOME'    => 'home',
            'CELL'    => 'mobile',
            'MAIN'    => 'work',
            'FAX'     => 'fax',
            'default' => 'work',
        ],

        'emailTypes' => [
            'WORK' => 'work',
            'HOME' => 'home'
        ],
        
        'phoneReplaceCharacters' => [                               // are processed consecutively. Order decides!
            '+491'  => '01',                                        // domestic numbers without country code
            '+492'  => '02',
            '+493'  => '03',
            '+494'  => '04',
            '+495'  => '05',
            '+496'  => '06',
            '+497'  => '07',
            '+498'  => '08',
            '+499'  => '09',
            '+49 1' => '01',
            '+49 2' => '02',
            '+49 3' => '03',
            '+49 4' => '04',
            '+49 5' => '05',
            '+49 6' => '06',
            '+49 7' => '07',
            '+49 8' => '08',
            '+49 9' => '09',
            '+49'   => '',
            '('     => '',                                          // delete separators
            ')'     => '',
            '/'     => '',
            '-'     => '',
            '+'     => '00'                                         // normalize foreign numbers
        ]
    ]
];