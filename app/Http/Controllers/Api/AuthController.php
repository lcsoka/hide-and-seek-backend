<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuestAuthRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\GameResult;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Issue a guest identity + Sanctum token. Guests are Users with no email/password;
     * they can later register (promoting this same user row, keeping all their history).
     */
    public function guest(GuestAuthRequest $request): JsonResponse
    {
        $name = $request->input('display_name') ?: 'Guest '.strtoupper(Str::random(4));

        $user = User::create(['name' => $name]);
        $token = $user->createToken('guest')->plainTextToken;

        return response()->json([
            'token' => $token,
            'display_name' => $user->name,
            'user_id' => $user->id,
        ], 201);
    }

    /**
     * Register the CURRENT guest in place: set an email + password on their existing user row,
     * so every game/player they already have stays linked. Accounts are optional — playing as a
     * guest never requires this. The existing token keeps working (same user).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->isGuest()) {
            return response()->json(['message' => 'This account is already registered.'], 409);
        }

        $user->update([
            'email' => Str::lower($request->string('email')),
            'password' => $request->input('password'), // 'hashed' cast hashes it
            'name' => $request->input('name') ?: $user->name,
        ]);

        return response()->json($this->profile($user->refresh()), 201);
    }

    /** Log a returning registered user in (email + password) → a fresh Sanctum token. */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', Str::lower($request->string('email')))->first();
        if ($user === null || $user->password === null || ! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
        ] + $this->profile($user));
    }

    /**
     * Email a password-reset link (to the SPA's /reset-password page). Always responds 200 so we
     * don't reveal whether an email is registered.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink(['email' => Str::lower($request->string('email'))]);

        return response()->json(['message' => __('passwords.sent')]);
    }

    /** Reset the password using the emailed token, then revoke all of the user's tokens. */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        $status = Password::reset(
            [
                'email' => Str::lower($request->string('email')),
                'password' => $request->input('password'),
                'token' => $request->input('token'),
            ],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save(); // 'hashed' cast hashes it
                $user->tokens()->delete(); // sign the user out everywhere after a reset
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        return response()->json(['message' => __($status)]);
    }

    /** Revoke the token used for this request. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request->user()));
    }

    /** The user's aggregate stats + recent games (durable across session pruning). */
    public function stats(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $agg = GameResult::where('user_id', $userId)
            ->selectRaw('COUNT(*) games, COALESCE(SUM(won), 0) wins, COALESCE(SUM(hide_time_s), 0) total_hide, COALESCE(MAX(hide_time_s), 0) best_hide')
            ->first();

        $recent = GameResult::where('user_id', $userId)->orderByDesc('played_at')->limit(10)
            ->get(['hide_time_s', 'won', 'players_count', 'played_at']);

        return response()->json([
            'games_played' => (int) $agg->games,
            'wins' => (int) $agg->wins,
            'total_hide_time_s' => (int) $agg->total_hide,
            'best_hide_time_s' => (int) $agg->best_hide,
            'recent' => $recent->map(fn (GameResult $r) => [
                'hide_time_s' => $r->hide_time_s,
                'won' => $r->won,
                'players' => $r->players_count,
                'at' => $r->played_at?->timestamp,
            ])->all(),
        ]);
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        if ($request->filled('name')) {
            $user->update(['name' => $request->input('name')]);
        }

        return response()->json($this->profile($user->refresh()));
    }

    /** Upload/replace the profile avatar; returns the updated profile with the new URL. */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $path = $request->file('image')->store('avatars', 'public');
        $user->update(['avatar' => Storage::disk('public')->url($path)]);

        return response()->json($this->profile($user->refresh()));
    }

    /**
     * GDPR erasure — permanently delete the authenticated user's account and personal data.
     * A registered account must re-confirm its password. Game participation is anonymised (the
     * FK nulls players.user_id on delete and the alias is scrubbed) so co-players' history stays
     * intact; the user's stats + custom curses/questions cascade-delete, tokens are revoked, and
     * the avatar file is removed. This is a hard delete — nothing is recoverable.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->password !== null) {
            $request->validate(['password' => ['required', 'string']]);
            if (! Hash::check($request->input('password'), $user->password)) {
                throw ValidationException::withMessages(['password' => __('auth.password')]);
            }
        }

        $user->players()->update(['display_name' => __('Deleted player')]);

        if ($user->avatar && str_contains($user->avatar, '/storage/')) {
            Storage::disk('public')->delete(Str::after($user->avatar, '/storage/'));
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'Account deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'is_guest' => $user->isGuest(),
        ];
    }
}
