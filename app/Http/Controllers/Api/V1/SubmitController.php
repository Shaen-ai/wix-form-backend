<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuthHelper;
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

        [$emailFieldId, $email] = $this->extractEmailAndFieldId($data, $fields);

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

        if (! empty($formSettings['limitPerEmail']) && $email && $emailFieldId !== null) {
            $emailCount = Submission::where('form_id', $form->id)
                ->where('data_json->'.$emailFieldId, $email)
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

        $allowEdit = ! empty($formSettings['allowEditSubmission']);
        $editToken = $allowEdit ? bin2hex(random_bytes(32)) : null;

        $submission = DB::transaction(function () use ($form, $data, $wixContactId, $request, $fileIds, $editToken) {
            $submission = Submission::create([
                'form_id' => $form->id,
                'submitted_at' => now(),
                'ip_hash' => hash('sha256', $request->ip() . config('app.key')),
                'user_agent' => $request->userAgent(),
                'data_json' => $data,
                'wix_contact_id' => $wixContactId,
                'edit_token' => $editToken,
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

        if ($allowEdit && $submission->edit_token) {
            $response['edit_token'] = $submission->edit_token;
        }

        if (! empty($formSettings['redirectUrl'])) {
            $response['redirect_url'] = $formSettings['redirectUrl'];
        }

        return response()->json($response);
    }

    public function getSubmissionForEdit(Request $request, string $compId, int $submissionId): JsonResponse
    {
        $editToken = $request->query('edit_token');
        if (! $editToken || ! is_string($editToken)) {
            return response()->json(['message' => 'Edit token required'], 400);
        }

        $form = Form::where('comp_id', $compId)->where('is_active', true)->first();
        if (! $form) {
            return response()->json(['message' => 'Form not found'], 404);
        }

        $formSettings = $form->settings_json ?? [];
        if (empty($formSettings['allowEditSubmission'])) {
            return response()->json(['message' => 'Editing is not allowed for this form'], 403);
        }

        $submission = Submission::where('id', $submissionId)
            ->where('form_id', $form->id)
            ->where('edit_token', $editToken)
            ->first();

        if (! $submission) {
            return response()->json(['message' => 'Submission not found or edit token invalid'], 404);
        }

        $data = $submission->data_json ?? [];
        $files = [];
        foreach ($submission->submissionFiles as $sf) {
            if ($sf->form_field_id !== null) {
                $fid = (string) $sf->form_field_id;
                if (! isset($files[$fid])) {
                    $files[$fid] = [];
                }
                $files[$fid][] = ['file_id' => (string) $sf->id, 'name' => $sf->original_name];
            }
        }

        return response()->json([
            'data' => $data,
            'files' => $files,
        ]);
    }

    public function updateSubmission(Request $request, string $compId, int $submissionId): JsonResponse
    {
        $form = Form::where('comp_id', $compId)->where('is_active', true)->first();
        if (! $form) {
            return response()->json(['message' => 'Form not found'], 404);
        }

        $formSettings = $form->settings_json ?? [];
        if (empty($formSettings['allowEditSubmission'])) {
            return response()->json(['message' => 'Editing is not allowed for this form'], 403);
        }

        $validated = $request->validate([
            'edit_token' => 'required|string',
            'data' => 'required|array',
            'file_ids' => 'nullable|array',
            'file_ids.*' => 'string',
        ]);

        $submission = Submission::where('id', $submissionId)
            ->where('form_id', $form->id)
            ->where('edit_token', $validated['edit_token'])
            ->first();

        if (! $submission) {
            return response()->json(['message' => 'Submission not found or edit token invalid'], 404);
        }

        $fields = $form->formFields()->get()->keyBy('id');
        $data = $this->normalizeCompoundFields($validated['data'], $fields);
        $fileIds = $validated['file_ids'] ?? [];

        $submission->data_json = $data;
        $submission->save();

        if (! empty($fileIds)) {
            SubmissionFile::where('submission_id', $submission->id)->update(['submission_id' => null]);
            SubmissionFile::whereIn('id', $fileIds)
                ->whereNull('submission_id')
                ->update(['submission_id' => $submission->id]);
        }

        return response()->json([
            'message' => $formSettings['successMessage'] ?? 'Your submission has been updated.',
            'id' => $submission->id,
        ]);
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
        if (! $auth) {
            return null;
        }

        $token = AuthHelper::extractTokenFromAuthHeader($auth);
        if (! $token) {
            return null;
        }

        try {
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

    /**
     * @return array{0: ?int, 1: ?string} [emailFieldId, email] or [null, null]
     */
    private function extractEmailAndFieldId(array $data, $fields): array
    {
        foreach ($data as $fieldId => $value) {
            $field = $fields->get($fieldId);
            if (! $field || $field->type !== 'email') {
                continue;
            }
            $email = is_array($value)
                ? ($value['value'] ?? ($value['email'] ?? null))
                : (is_string($value) ? $value : null);
            if ($email && is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [(int) $fieldId, $email];
            }
        }

        return [null, null];
    }
}
