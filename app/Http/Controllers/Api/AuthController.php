<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuestAuthRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Issue a guest identity + Sanctum token. Guests are Users with no
     * email/password (registration comes later).
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
}
