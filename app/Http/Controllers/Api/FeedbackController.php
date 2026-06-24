<?php

namespace App\Http\Controllers\Api;

use App\Enums\FeedbackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeedbackRequest;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;

class FeedbackController extends Controller
{
    public function store(StoreFeedbackRequest $request): JsonResponse
    {
        $feedback = Feedback::create([
            ...$request->validated(),
            'status' => FeedbackStatus::Open,
        ]);

        return response()->json([
            'id' => $feedback->id,
            'status' => $feedback->status,
        ], 201);
    }
}
