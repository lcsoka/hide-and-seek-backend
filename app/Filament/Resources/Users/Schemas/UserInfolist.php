<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    ImageEntry::make('avatar')
                        ->circular()
                        ->defaultImageUrl(fn (User $record): string => 'https://ui-avatars.com/api/?background=e11d48&color=fff&name='.urlencode($record->name ?? '?')),
                    TextEntry::make('name')->weight('bold'),
                    TextEntry::make('email')->placeholder('— (guest)')->copyable(),
                    TextEntry::make('kind')
                        ->label('Type')
                        ->state(fn (User $record): string => $record->email ? 'Registered' : 'Guest')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Registered' ? 'success' : 'gray'),
                    TextEntry::make('admin')
                        ->state(fn (User $record): string => $record->isAllowlistedAdmin() ? 'Admin (env)' : ($record->is_admin ? 'Admin' : 'Player'))
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Player' ? 'gray' : ($state === 'Admin (env)' ? 'warning' : 'info')),
                    TextEntry::make('created_at')->label('Joined')->dateTime()->since(),
                ]),
        ]);
    }
}
