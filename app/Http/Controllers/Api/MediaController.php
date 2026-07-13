<?php

namespace App\Http\Controllers\Api;

use App\Game\GameEngine;
use App\Http\Controllers\Controller;
use App\Models\Session;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MediaController extends Controller
{
    public function __construct(private readonly GameEngine $engine) {}

    /**
     * Upload an in-game photo OR video (photo-question answer, curse proof — e.g. the Bird Guide's
     * bird footage). Stored on the public disk under the session; returns the public URL the client
     * references from a follow-up action. Videos are larger, so the size cap is generous — the
     * server's php.ini `upload_max_filesize` / `post_max_size` must allow it too.
     */
    public function store(Request $request, Session $session): JsonResponse
    {
        // Only players in this session may upload to it.
        $this->engine->playerFor($session, $request->user());

        $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,gif,mp4,mov,m4v,webm,3gp', 'max:81920'],
        ]);

        $path = $request->file('image')->store("media/{$session->id}", 'public');

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }
}
