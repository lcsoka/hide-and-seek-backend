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

#[Fillable(['name', 'email', 'password', 'avatar', 'avatar_thumb'])]
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

    /** Durable per-game outcomes (survive session pruning) — the source for stats + leaderboards. */
    public function gameResults(): HasMany
    {
        return $this->hasMany(GameResult::class);
    }

    /** Custom curses this user authored (user-generated content). */
    public function authoredCards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    /** Custom questions this user authored (user-generated content). */
    public function authoredQuestions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    /**
     * Who may reach the Filament admin panel: a registered user (has an email) who is either in
     * the FILAMENT_ADMIN_EMAILS allowlist (the can't-lock-yourself-out fallback) or has been
     * granted `is_admin` from the panel. Guests (no email) are always excluded.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->email === null) {
            return false;
        }

        return $this->is_admin === true
            || in_array(strtolower($this->email), config('game.admin_emails', []), true);
    }

    /** True for users pinned as admin via the env allowlist (read-only, can't be revoked in-panel). */
    public function isAllowlistedAdmin(): bool
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
            'is_admin' => 'boolean',
        ];
    }
}
