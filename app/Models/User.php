<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'avatar'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** A guest is a user with no email/password — a throwaway identity that can be promoted. */
    public function isGuest(): bool
    {
        return $this->email === null;
    }

    /** Every session this user has joined (across games) — the basis for their history/stats. */
    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    /**
     * Who may reach the Filament admin panel: only users whose email is in the
     * FILAMENT_ADMIN_EMAILS allowlist. Guests have no email, so they're excluded.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->email !== null && in_array(strtolower($this->email), config('game.admin_emails', []), true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
