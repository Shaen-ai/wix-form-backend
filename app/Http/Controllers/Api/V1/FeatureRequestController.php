<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\FeatureRequestMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FeatureRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'subject' => 'required|string|min:3|max:200',
            'description' => 'required|string|min:3|max:2000',
        ]);

        $recipient = config('mail.feature_request_recipient', 'info@nextechspires.com');

        try {
            Mail::to($recipient)->send(new FeatureRequestMail(
                $validated['email'],
                $validated['subject'],
                $validated['description']
            ));

            return response()->json(['message' => 'Feature request sent successfully']);
        } catch (\Throwable $e) {
            Log::error('Feature request email failed', [
                'error' => $e->getMessage(),
                'email' => $validated['email'],
            ]);

            return response()->json(['error' => 'Failed to send feature request'], 500);
        }
    }
}
