<?php

namespace App\Filament\Resources\Cards\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Card')
                ->columns(2)
                ->schema([
                    Select::make('type')
                        ->required()
                        ->live()
                        ->default('curse')
                        ->options([
                            'curse' => 'Curse',
                            'powerup' => 'Powerup',
                            'time_bonus' => 'Time bonus',
                        ])
                        ->helperText('Curses carry consequences; powerups have a fixed mechanic; time-bonuses add minutes.'),
                    TextInput::make('count')->numeric()->default(1)->minValue(1)->required()
                        ->helperText('Copies of this card in the shuffled deck.'),
                    Toggle::make('is_active')->default(true),
                    Toggle::make('is_custom')->helperText('On for house-made cards.'),
                    TextInput::make('sort')->numeric()->default(0),

                    // Powerup-only.
                    Select::make('power')
                        ->visible(fn (Get $get) => $get('type') === 'powerup')
                        ->required(fn (Get $get) => $get('type') === 'powerup')
                        ->options([
                            'veto' => 'Veto — discard the pending question',
                            'randomize' => 'Randomize — redraw the whole hand',
                            'duplicate' => 'Duplicate — copy a card in hand',
                            'move' => 'Move — relocate the hider',
                            'discard_1_draw_2' => 'Discard 1, draw 2',
                            'discard_2_draw_3' => 'Discard 2, draw 3',
                            'draw_1_expand_1' => 'Draw 1, expand hand',
                        ]),

                ]),

            // Time-bonus-only: minutes added depend on the play size.
            Section::make('Time bonus (minutes by play size)')
                ->visible(fn (Get $get) => $get('type') === 'time_bonus')
                ->columns(3)
                ->schema([
                    TextInput::make('minutes.small')->label('Small')->numeric()->minValue(0)->suffix('min')->required(),
                    TextInput::make('minutes.medium')->label('Medium')->numeric()->minValue(0)->suffix('min')->required(),
                    TextInput::make('minutes.large')->label('Large')->numeric()->minValue(0)->suffix('min')->required(),
                ]),

            Section::make('Magyar (HU)')
                ->schema([
                    TextInput::make('name_hu')->label('Name (HU)')->required()->maxLength(255),
                    TextInput::make('cost_hu')->label('Cost (HU)')->maxLength(255)->helperText('Casting cost, e.g. "2 kártya eldobása".'),
                    Textarea::make('description_hu')->label('Effect (HU)')->required()->rows(2),
                ]),

            Section::make('English (EN)')
                ->schema([
                    TextInput::make('name_en')->label('Name (EN)')->required()->maxLength(255),
                    TextInput::make('cost_en')->label('Cost (EN)')->maxLength(255),
                    Textarea::make('description_en')->label('Effect (EN)')->required()->rows(2),
                ]),

            // Curse consequences — friendly controls backed by the `effect` JSON.
            Section::make('Consequences')
                ->description('What happens when this curse is played. Leave everything off for a social-only curse.')
                ->visible(fn (Get $get) => $get('type') === 'curse')
                ->columns(2)
                ->schema([
                    Toggle::make('effect.requires_proof')->label('Requires a photo to clear'),
                    Toggle::make('effect.blocks_asking')->label('Blocks asking until cleared'),
                    Toggle::make('effect.hider_photo')->label('Hider sends a photo when casting')
                        ->helperText('e.g. a Street View screenshot the seekers must find.'),
                    TextInput::make('effect.duration_s')->label('Auto-expire after')->numeric()->minValue(0)->suffix('seconds')
                        ->helperText('Leave empty for no time limit.'),

                    Section::make('Dice — roll to clear')
                        ->columns(3)
                        ->schema([
                            TextInput::make('effect.dice.count')->label('Dice')->numeric()->minValue(1),
                            TextInput::make('effect.dice.sides')->label('Sides')->numeric()->minValue(2),
                            TextInput::make('effect.dice.target')->label('Target (sum ≥)')->numeric()->minValue(1)
                                ->helperText('Empty = roll without a clear target.'),
                        ]),

                    Section::make('Disable question categories')
                        ->columns(3)
                        ->schema([
                            TextInput::make('effect.disable_categories.count')->label('How many')->numeric()->minValue(1),
                            Select::make('effect.disable_categories.mode')->label('Chosen by')
                                ->options(['random' => 'Random', 'choose' => 'Hider picks']),
                            Toggle::make('effect.disable_categories.rotates')->label('Rotate each question'),
                        ]),

                    TextInput::make('effect.bonus_draws.count')->label('Bonus hider draws (next N answers)')->numeric()->minValue(1)
                        ->helperText('Hider self-buff, e.g. The Overflowing Chalice.'),
                ]),
        ]);
    }
}
