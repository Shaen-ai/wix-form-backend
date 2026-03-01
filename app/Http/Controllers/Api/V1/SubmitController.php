<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Services\MailService;
use App\Services\PlanService;
use App\Services\RecaptchaService;
use App\Services\WixContactsService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubmitController extends Controller
{
    public function __construct(
        private RecaptchaService $recaptcha,
        private WixContactsService $wixContacts,
        private MailService $mail,
        private PlanService $planService,
    ) {}

    public function submit(Request $request, string $compId): JsonResponse
    {
        $form = Form::where('comp_id', $compId)->where('is_active', true)->first();
        if (! $form) {
            return response()->json(['message' => 'Form not found'], 404);
        }

        if ($form->status === 'draft') {
            return response()->json(['message' => 'This form is not yet published.'], 403);
        }

        $formSettings = $form->settings_json ?? [];

        $validated = $request->validate([
            'data' => 'required|array',
            'recaptcha_token' => 'nullable|string',
            'file_ids' => 'nullable|array',
            'file_ids.*' => 'string',
            '_hp_field' => 'nullable|string',
        ]);

        if (! empty($formSettings['honeypotEnabled']) && ! empty($validated['_hp_field'])) {
            return response()->json(['message' => 'Thank you! Your submission has been received.', 'id' => 0]);
        }

        if (($formSettings['accessMode'] ?? 'public') === 'members_only') {
            $memberId = $this->extractMemberId($request);
            if (! $memberId) {
                return response()->json(['message' => 'This form is available to members only. Please log in to submit.'], 403);
            }
        }

        $settings = $form->settings;
        if ($settings?->recaptcha_enabled) {
            $token = $validated['recaptcha_token'] ?? '';
            if (! $this->recaptcha->verify($token, $request->ip())) {
                return response()->json(['message' => 'reCAPTCHA verification failed'], 422);
            }
        }

        $data = $validated['data'];
        $fileIds = $validated['file_ids'] ?? [];

        $fields = $form->formFields()->get()->keyBy('id');
        $data = $this->normalizeCompoundFields($data, $fields);

        $email = $this->extractEmail($data, $fields);

        $monthlyLimit = $this->planService->monthlySubmissionLimit($form);
        if ($monthlyLimit > 0) {
            $monthlyCount = Submission::where('form_id', $form->id)
                ->where('submitted_at', '>=', now()->startOfMonth())
                ->count();
            if ($monthlyCount >= $monthlyLimit) {
                return response()->json([
                    'message' => 'Monthly submission limit reached. Please upgrade your plan for more submissions.',
                ], 422);
            }
        }

        if (! empty($formSettings['limitTotalSubmissions'])) {
            $totalCount = Submission::where('form_id', $form->id)->count();
            if ($totalCount >= (int) $formSettings['limitTotalSubmissions']) {
                return response()->json(['message' => 'This form is no longer accepting submissions.'], 422);
            }
        }

        if (! empty($formSettings['limitPerEmail']) && $email) {
            $emailCount = Submission::where('form_id', $form->id)
                ->whereJsonContains('data_json', $email)
                ->count();
            if ($emailCount >= (int) $formSettings['limitPerEmail']) {
                return response()->json(['message' => 'You have reached the submission limit for this email.'], 422);
            }
        }

        if (! empty($formSettings['preventDuplicates'])) {
            $ipHash = hash('sha256', $request->ip() . config('app.key'));
            $duplicate = Submission::where('form_id', $form->id)
                ->where('ip_hash', $ipHash)
                ->where('submitted_at', '>=', now()->subMinutes(5))
                ->exists();
            if ($duplicate) {
                return response()->json(['message' => 'Duplicate submission detected. Please wait before submitting again.'], 422);
            }
        }

        $wixContactId = null;
        if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $wixContactId = $this->wixContacts->upsertContact($email, $data);
        }

        $submission = DB::transaction(function () use ($form, $data, $wixContactId, $request, $fileIds) {
            $submission = Submission::create([
                'form_id' => $form->id,
                'submitted_at' => now(),
                'ip_hash' => hash('sha256', $request->ip() . config('app.key')),
                'user_agent' => $request->userAgent(),
                'data_json' => $data,
                'wix_contact_id' => $wixContactId,
            ]);

            if (! empty($fileIds)) {
                SubmissionFile::whereIn('id', $fileIds)
                    ->whereNull('submission_id')
                    ->update(['submission_id' => $submission->id]);
            }

            return $submission;
        });

        try {
            $this->mail->sendNotification($submission, $form);
            $this->mail->sendAutoReply($submission, $form);
        } catch (\Throwable $e) {
            Log::error('Failed to send submission email', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }

        $response = [
            'message' => $formSettings['successMessage'] ?? 'Thank you! Your submission has been received.',
            'id' => $submission->id,
        ];

        if (! empty($formSettings['redirectUrl'])) {
            $response['redirect_url'] = $formSettings['redirectUrl'];
        }

        return response()->json($response);
    }

    private function normalizeCompoundFields(array $data, $fields): array
    {
        foreach ($data as $fieldId => $value) {
            $field = $fields->get($fieldId);
            if (! $field || ! is_array($value)) {
                continue;
            }

            if ($field->type === 'name') {
                $first = trim($value['first'] ?? '');
                $last = trim($value['last'] ?? '');
                $parts = array_filter([$first, $last]);
                $data[$fieldId] = array_merge($value, ['value' => implode(' ', $parts)]);
            }

            if ($field->type === 'address_simple') {
                $parts = array_filter([
                    $value['street'] ?? '',
                    $value['street2'] ?? '',
                    $value['city'] ?? '',
                    $value['state'] ?? '',
                    $value['zip'] ?? '',
                    $value['country'] ?? '',
                ], fn ($v) => trim($v) !== '');
                $data[$fieldId] = array_merge($value, ['value' => implode(', ', $parts)]);
            }
        }

        return $data;
    }

    private function extractMemberId(Request $request): ?string
    {
        $auth = $request->header('Authorization');
        if (! $auth || ! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        try {
            $token = substr($auth, 7);
            $key = config('app.jwt_secret');
            $payload = null;

            if ($key) {
                try {
                    $payload = (array) JWT::decode($token, new Key($key, 'HS256'));
                } catch (\Throwable) {
                    // fall through to payload decode
                }
            }

            if ($payload === null) {
                $parts = explode('.', $token);
                if (isset($parts[1])) {
                    $decoded = json_decode(
                        base64_decode(strtr($parts[1], '-_', '+/')),
                        true,
                    );
                    if (is_array($decoded)) {
                        $payload = $decoded;
                    }
                }
            }

            return is_array($payload) ? ($payload['memberId'] ?? null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractEmail(array $data, $fields): ?string
    {
        foreach ($data as $fieldId => $value) {
            $field = $fields->get($fieldId);
            if ($field && $field->type === 'email' && is_string($value)) {
                return $value;
            }
        }

        return null;
    }
}
