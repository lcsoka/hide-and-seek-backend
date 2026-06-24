<?php

namespace App\Filament\Resources\Questions\Schemas;

use App\Enums\QuestionCategory;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QuestionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        Select::make('category')
                            ->options(QuestionCategory::class)
                            ->required(),
                        TextInput::make('reward_draw')->numeric()->default(0)->label('Cards drawn'),
                        TextInput::make('reward_keep')->numeric()->default(0)->label('Cards kept'),
                    ]),

                Section::make('Magyar (HU)')
                    ->schema([
                        TextInput::make('title_hu')->label('Title (HU)')->required()->maxLength(255),
                        Textarea::make('prompt_hu')->label('Prompt (HU)')->required()->rows(3),
                    ]),

                Section::make('English (EN)')
                    ->schema([
                        TextInput::make('title_en')->label('Title (EN)')->required()->maxLength(255),
                        Textarea::make('prompt_en')->label('Prompt (EN)')->required()->rows(3),
                    ]),

                Section::make('Options & flags')
                    ->columns(3)
                    ->schema([
                        Toggle::make('is_active')->default(true),
                        Toggle::make('is_custom')->helperText('On for house-made questions.'),
                        TextInput::make('sort')->numeric()->default(0),
                        Textarea::make('parameters')
                            ->rows(4)
                            ->columnSpanFull()
                            ->helperText('Optional JSON, e.g. distance/radius options.')
                            ->rules(['nullable', 'json'])
                            ->formatStateUsing(fn ($state) => filled($state)
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                : null)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? json_decode($state, true) : null),
                    ]),
            ]);
    }
}
