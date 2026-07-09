<?php

namespace App\Filament\Resources\Cities\Schemas;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('City')
                ->columns(2)
                ->schema([
                    TextInput::make('key')
                        ->required()
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->helperText('Stable slug the game uses (e.g. "budapest"). Lowercase, no spaces.'),
                    TextInput::make('name')->required()->helperText('Display name shown in the wizard.'),
                    TextInput::make('lat')->numeric()->required()->helperText('Play-area centre latitude.'),
                    TextInput::make('lng')->numeric()->required()->helperText('Play-area centre longitude.'),
                    Select::make('default_size')
                        ->label('Play size')
                        ->required()
                        ->default('medium')
                        ->options(['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'])
                        ->helperText('The play radius tied to this city — the host no longer picks it.'),
                    Toggle::make('is_active')->default(true),
                    TextInput::make('sort')->numeric()->default(0),
                ]),

            Section::make('Cover photo')
                ->schema([
                    FileUpload::make('image')
                        ->image()
                        ->disk('public')
                        ->directory('cities')
                        ->imageEditor()
                        ->helperText('Shown as the city card in the new-game wizard.'),
                ]),

            Section::make('Available transit')
                ->schema([
                    CheckboxList::make('available_modes')
                        ->label('Transit modes that exist here')
                        ->required()
                        ->columns(3)
                        ->options([
                            'metro' => 'Metró (metro)',
                            'light_rail' => 'HÉV (light rail)',
                            'tram' => 'Villamos (tram)',
                            'trolleybus' => 'Trolibusz (trolleybus)',
                            'bus' => 'Busz (bus)',
                            'rail' => 'Vonat (train)',
                        ])
                        ->helperText('Only these can be picked as hiding spots for this city.'),
                ]),
        ]);
    }
}
