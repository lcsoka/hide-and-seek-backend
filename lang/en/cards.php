<?php

return [
    'powerups' => [
        'veto' => [
            'name' => 'Veto',
            'description' => 'Refuse to answer the seekers’ current question. It is discarded with no answer.',
        ],
        'duplicate' => [
            'name' => 'Duplicate',
            'description' => 'Make a copy of another card in your hand.',
        ],
        'move' => [
            'name' => 'Move',
            'description' => 'Relocate to a new valid hiding spot.',
        ],
        'randomize' => [
            'name' => 'Randomize',
            'description' => 'Discard your hand and draw the same number of new cards.',
        ],
        'discard' => [
            'name' => 'Discard',
            'description' => 'Discard this card to draw a fresh one.',
        ],
    ],
    'time_bonus' => [
        'name' => '+:minutes min time bonus',
        'description' => 'Adds :minutes minutes to your run time. Keep it in your hand to bank the time.',
    ],
];
