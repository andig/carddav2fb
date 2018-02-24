<?php

$config = [
    'phonebook' => [
        'id'           => 0,
        'name'         => 'Telefonbuch',
		'forcedupload' => 2,                   // 3 = CardDAV contacts overwrite phonebook on Fritz!Box
    ],                                         // 2 = like 3, but newer entries will send as VCF via eMail (-> reply)
                                               // 1 = like 2, but vCards are only downloaded if they are newer than the phonebook
	
    'server' => [
        [
            'url'      => 'https://...',
            'user'     => '',
            'password' => '',
            ],                                 /* define as many as you need
        [
            'url'      => '',
            'user'     => '',
            'password' => '',
            ],                                 */
    ],

	'fritzbox' => [
        'url'      => 'fritz.box',
        'user'     => 'dslf-config',
        'password' => '',
    ],

    'reply' => [
	    'url'      => 'smtp...',
		'port'     => 587,                     // alternativ 465
		'secure'   => 'tls',                   // alternativ 'ssl'
        'user'     => '',                      // your sender
        'password' => '',
		'receiver' => '',
		'debug'    => 2,                       // 0 = off (for production use)
	],	                                       // 1 = client messages
	    									   // 2 = client and server messages

    'filters' => [
        'include' => [                         // if empty include all by default
        ],

        'exclude' => [
            'categories' => [
			    'A',
				'B',
				'C',
            ],
		    'groups'     => [
			],
        ],
    ],

    'conversions' => [
        'vip' => [
            'categories' => ['VIP'
            ],
        ],
        'realName' => [
            '{lastname}, {prefix} {nickname}',
            '{lastname}, {prefix} {firstname}',
            '{lastname}, {nickname}',
            '{lastname}, {firstname}',
            '{organization}',
            '{fullname}'
        ],
		
        'phoneTypes' => [
            'WORK'    => 'work',
            'HOME'    => 'home',
            'CELL'    => 'mobile',
            'MAIN'    => 'work',
            'default' => 'work',
            'other'   => 'work'
        ],
		
        'emailTypes' => [
            'WORK' => 'work',
            'HOME' => 'home'
        ],
		
        'phoneReplaceCharacters' => [  // are processed consecutively. Order decides!
            '+491'  => '01',           // domestic numbers without country code 
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
            '('     => '',             // delete separator
            ')'     => '',
            '/'     => '',
            '-'     => '',
            '+'     => '00'            // normalize foreign numbers
        ]
    ]
];
