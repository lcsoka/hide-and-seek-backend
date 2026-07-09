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
        'discard_1_draw_2' => [
            'name' => 'Discard 1, Draw 2',
            'description' => 'Discard one other card from your hand, then draw two new ones.',
        ],
        'discard_2_draw_3' => [
            'name' => 'Discard 2, Draw 3',
            'description' => 'Discard two other cards from your hand, then draw three new ones.',
        ],
        'draw_1_expand_1' => [
            'name' => 'Draw 1, Expand 1',
            'description' => 'Draw a new card and expand your hand.',
        ],
    ],
    'time_bonus' => [
        'name' => '+:minutes min time bonus',
        'description' => 'Adds :minutes minutes to your run time. Keep it in your hand to bank the time.',
    ],
];
