<?php

$config = [
	// phonebook
	'phonebook' => [
		'id' => 0,
		'name' => 'Telefonbuch'
	],

    // or server
	'server' => [
		0 => [
			'url' => 'https://...',
			'user' => '',
			'password' => '',
			],
		1 => [
			'url' => 'https://...',
			'user' => '',
			'password' => '',
			],
		],

	// or fritzbox
	'fritzbox' => [
		'url' => 'http://fritz.box',
		'user' => '',
		'password' => '',
	],

    'filters' => [
        'include' => [
            // if empty include all by default
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
			'category' => [
				'vip1'
			],
			'group' => [
				'PERS'
			],
		],
		'realName' => [
			'{lastname}, {firstname}',
			'{fullname}',
			'{organization}'
		],
		'phoneTypes' => [
			'WORK' => 'work',
			'HOME' => 'home',
			'CELL' => 'mobile'
		],
		'emailTypes' => [
			'WORK' => 'work',
			'HOME' => 'home'
		],
		'phoneReplaceCharacters' => [
			'+49' => '',  //Router steht default in DE; '0049' könnte auch Teil einer Rufnummer sein
            '('   => '',
			')'   => '',
			'/'   => '',
			'-'   => ' '
		]
	]
];
