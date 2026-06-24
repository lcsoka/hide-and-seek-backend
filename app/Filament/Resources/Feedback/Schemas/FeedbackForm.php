<?php

namespace App\Filament\Resources\Feedback\Schemas;

use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FeedbackForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Triage')
                    ->schema([
                        Select::make('status')
                            ->options(FeedbackStatus::class)
                            ->required(),
                    ]),

                Section::make('Submission')
                    ->description('Submitted via the public API — read-only.')
                    ->columns(2)
                    ->schema([
                        Select::make('type')->options(FeedbackType::class)->disabled(),
                        TextInput::make('contact')->disabled()->placeholder('—'),
                        TextInput::make('subject')->disabled()->columnSpanFull()->placeholder('—'),
                        Textarea::make('message')->disabled()->rows(5)->columnSpanFull(),
                        Select::make('session_id')->relationship('session', 'join_code')->disabled()->placeholder('—'),
                        Select::make('player_id')->relationship('player', 'display_name')->disabled()->placeholder('—'),
                        Textarea::make('context')
                            ->disabled()
                            ->rows(4)
                            ->columnSpanFull()
                            ->formatStateUsing(fn ($state) => filled($state)
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                                : null),
                    ]),
            ]);
    }
}
