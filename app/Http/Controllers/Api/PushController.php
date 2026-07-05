<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PushController extends Controller
{
    /** The VAPID public key the client needs to subscribe. Null when push isn't configured. */
    public function publicKey(): JsonResponse
    {
        return response()->json(['key' => config('webpush.vapid.public_key') ?: null]);
    }

    /** Store (or refresh) the current device's push subscription for the signed-in user. */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:512'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'locale' => ['sometimes', 'in:hu,en'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $request->user()->id,
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'locale' => $data['locale'] ?? app()->getLocale(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    /** Remove this device's subscription (on logout or when the user turns notifications off). */
    public function unsubscribe(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string']]);

        PushSubscription::where('endpoint', $request->input('endpoint'))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
