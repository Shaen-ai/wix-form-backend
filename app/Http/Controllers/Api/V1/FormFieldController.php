<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormField;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FormFieldController extends Controller
{
    private const VALID_FIELD_TYPES = [
        'text', 'textarea', 'email', 'phone', 'number', 'url',
        'date', 'time', 'datetime', 'select', 'multi_select',
        'checkbox', 'radio', 'toggle', 'file_upload', 'signature',
        'rating', 'slider', 'color', 'hidden', 'heading', 'divider',
        'name', 'address_simple', 'dropdown', 'checkboxes', 'multiselect',
        'consent_checkbox', 'header_text', 'paragraph_text', 'spacer',
        'section', 'page_break',
    ];

    public function index(Request $request, int $id): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $id);
        $fields = $form->formFields()->orderBy('order_index')->get();
        return response()->json(['data' => $fields]);
    }

    private const FIELD_SCHEMA = <<<'SCHEMA'
Each field must have:
- "type": one of: text, textarea, email, phone, number, url, dropdown, radio, checkboxes, multiselect, date, time, rating, slider, consent_checkbox, name, address_simple, file_upload, signature, header_text, paragraph_text, divider, spacer
- "label": string
- "required": boolean
- "placeholder": string or null
- "options_json": null, OR for dropdown/radio/checkboxes/multiselect: {"choices": ["Option 1", "Option 2"]}. For slider: {"min": 0, "max": 100, "step": 1}. For rating: {"max": 5}
- "order_index": integer starting at 0

Use "name" type for full name fields, "email" for emails, "phone" for phone numbers, "address_simple" for addresses.
Use "file_upload" for any file/document upload fields (CV, resume, attachments, images, documents, etc.).
Use "signature" for signature capture fields.
SCHEMA;

    private const AI_SYSTEM_PROMPT = <<<'PROMPT'
You are a form builder. Given a description, return a JSON object with a "fields" array.
%FIELD_SCHEMA%
Return ONLY the JSON object with a "fields" key. No explanation, no markdown.
PROMPT;

    private const AI_EDIT_SYSTEM_PROMPT = <<<'PROMPT'
You are a form configuration assistant. You receive the current form state (settings + fields) as JSON and an edit instruction.
Apply the requested changes and return a JSON object with two keys:

"form": an object with any form-level properties to update. Only include properties that changed. Possible properties:
- "name": string (form name)
- "description": string or null
- "language": language code string or null (e.g. "en", "es", "fr", "de", "pt", "it", "nl", "ru", "zh", "ja", "ko", "ar", "he", "hi", "tr", "pl", "sv", "da", "fi", "no")
- "status": "draft" or "published"
- "is_active": boolean
- "settings_json": object with any of these optional properties:
  - "showFormName": boolean
  - "showDescription": boolean
  - "showFieldLabels": boolean
  - "buttonText": string (submit button text)
  - "successMessage": string (thank-you message after submission)
  - "redirectUrl": string or null (redirect after submission)
  - "multiStep": boolean (multi-step/multi-page form)
  - "progressBar": boolean (show progress bar in multi-step)
  - "disableSubmitUntilValid": boolean
  - "preventDuplicates": boolean
  - "allowEditSubmission": boolean
  - "limitTotalSubmissions": number or null
  - "limitPerEmail": number or null
  - "adminNotificationEmail": string or null
  - "userConfirmationEmail": boolean (send confirmation email to user)
  - "accessMode": "public" or "members_only"
  - "honeypotEnabled": boolean (spam protection)

"fields": the FULL updated fields array (include ALL fields, not just changed ones).
%FIELD_SCHEMA%

If the instruction only affects form settings, return "fields" unchanged.
If the instruction only affects fields, return "form" as an empty object {}.
Return ONLY the JSON object with "form" and "fields" keys. No explanation, no markdown.
PROMPT;

    private function getSystemPrompt(string $template): string
    {
        return str_replace('%FIELD_SCHEMA%', self::FIELD_SCHEMA, $template);
    }

    public function generate(Request $request, int $id): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $id);

        if ($form->formFields()->count() > 0) {
            return response()->json(['error' => 'Form already has fields. AI generation is only available for new forms.'], 422);
        }

        $request->validate([
            'prompt' => 'required|string|min:3|max:500',
        ]);

        $apiKey = config('services.openai.key');
        if (empty($apiKey)) {
            Log::error('OpenAI API key not configured');
            return response()->json(['error' => 'AI service not configured'], 503);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt(self::AI_SYSTEM_PROMPT)],
                    ['role' => 'user', 'content' => $request->input('prompt')],
                ],
                'temperature' => 0.3,
                'max_tokens' => 2000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->failed()) {
                Log::warning('OpenAI API call failed', ['status' => $response->status()]);
                return response()->json(['error' => 'AI service unavailable'], 502);
            }

            $content = $response->json('choices.0.message.content');
            $parsed = json_decode($content, true);

            if (!$parsed) {
                return response()->json(['error' => 'Failed to parse AI response'], 502);
            }

            $rawFields = $parsed['fields'] ?? (array_is_list($parsed) ? $parsed : []);

            if (empty($rawFields)) {
                return response()->json(['error' => 'AI did not generate any fields'], 502);
            }

            $sanitized = $this->sanitizeAiFields($rawFields);

            return response()->json(['data' => $sanitized]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('OpenAI API timeout', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'AI service timed out. Please try again.'], 504);
        }
    }

    public function editWithAi(Request $request, int $id): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $id);

        $request->validate([
            'prompt' => 'required|string|min:3|max:500',
        ]);

        $apiKey = config('services.openai.key');
        if (empty($apiKey)) {
            Log::error('OpenAI API key not configured');
            return response()->json(['error' => 'AI service not configured'], 503);
        }

        $existingFields = $form->formFields()->orderBy('order_index')->get();

        $currentState = json_encode([
            'name' => $form->name,
            'description' => $form->description,
            'language' => $form->language,
            'status' => $form->status,
            'is_active' => $form->is_active,
            'settings_json' => $form->settings_json ?? (object) [],
            'fields' => $existingFields->map(fn ($f) => [
                'type' => $f->type,
                'label' => $f->label,
                'required' => $f->required,
                'placeholder' => $f->placeholder,
                'options_json' => $f->options_json,
                'order_index' => $f->order_index,
            ])->values(),
        ]);

        $userMessage = "Current form state:\n{$currentState}\n\nEdit instruction: {$request->input('prompt')}";

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.model', 'gpt-4o-mini'),
                'messages' => [
                    ['role' => 'system', 'content' => $this->getSystemPrompt(self::AI_EDIT_SYSTEM_PROMPT)],
                    ['role' => 'user', 'content' => $userMessage],
                ],
                'temperature' => 0.3,
                'max_tokens' => 3000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if ($response->failed()) {
                Log::warning('OpenAI API call failed (edit)', ['status' => $response->status()]);
                return response()->json(['error' => 'AI service unavailable'], 502);
            }

            $content = $response->json('choices.0.message.content');
            $parsed = json_decode($content, true);

            if (!$parsed || !is_array($parsed)) {
                return response()->json(['error' => 'Failed to parse AI response'], 502);
            }

            $formUpdates = $parsed['form'] ?? [];
            $rawFields = $parsed['fields'] ?? [];

            if (!empty($formUpdates) && is_array($formUpdates)) {
                $allowed = ['name', 'description', 'language', 'status', 'is_active'];
                $formData = array_intersect_key($formUpdates, array_flip($allowed));

                if (isset($formUpdates['settings_json']) && is_array($formUpdates['settings_json'])) {
                    $formData['settings_json'] = array_merge(
                        $form->settings_json ?? [],
                        $formUpdates['settings_json']
                    );
                }

                if (!empty($formData)) {
                    $form->update($formData);
                }
            }

            $sanitizedFields = [];
            if (!empty($rawFields) && is_array($rawFields)) {
                $sanitizedFields = $this->sanitizeAiFields($rawFields);

                DB::transaction(function () use ($form, $sanitizedFields) {
                    $form->formFields()->delete();
                    foreach ($sanitizedFields as $i => $fd) {
                        $form->formFields()->create(array_merge($fd, ['order_index' => $i]));
                    }
                });
            }

            $form->refresh();
            $updatedFields = $form->formFields()->orderBy('order_index')->get();

            return response()->json([
                'form' => $form,
                'fields' => $updatedFields,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::warning('OpenAI API timeout (edit)', ['message' => $e->getMessage()]);
            return response()->json(['error' => 'AI service timed out. Please try again.'], 504);
        }
    }

    private function resolveForm(?string $instanceId, int $id): Form
    {
        $form = Form::where(function ($q) use ($instanceId) {
            $q->where('instance_id', $instanceId)
              ->orWhereNull('instance_id');
        })->findOrFail($id);

        if ($form->instance_id === null && $instanceId) {
            $form->update(['instance_id' => $instanceId]);
        }

        return $form;
    }

    private function sanitizeAiFields(array $rawFields): array
    {
        $sanitized = [];
        foreach ($rawFields as $i => $f) {
            if (!is_array($f)) {
                continue;
            }
            $type = $f['type'] ?? 'text';
            if (!in_array($type, self::VALID_FIELD_TYPES, true)) {
                $type = 'text';
            }

            $optionsJson = null;
            if (isset($f['options_json']) && is_array($f['options_json'])) {
                $optionsJson = $f['options_json'];
            }

            $sanitized[] = [
                'type' => $type,
                'label' => mb_substr((string) ($f['label'] ?? 'Field ' . ($i + 1)), 0, 255),
                'required' => (bool) ($f['required'] ?? false),
                'placeholder' => isset($f['placeholder']) && $f['placeholder'] !== null
                    ? mb_substr((string) $f['placeholder'], 0, 255)
                    : null,
                'options_json' => $optionsJson,
                'order_index' => $i,
            ];
        }

        return $sanitized;
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $id);

        $validated = $request->validate([
            'fields' => 'required|array',
            'fields.*' => 'array',
            'fields.*.type' => 'required|string|in:' . implode(',', self::VALID_FIELD_TYPES),
            'fields.*.label' => 'required|string|max:255',
            'fields.*.help_text' => 'nullable|string|max:500',
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.required' => 'boolean',
            'fields.*.options_json' => 'nullable|array',
            'fields.*.validation_json' => 'nullable|array',
            'fields.*.logic_json' => 'nullable|array',
            'fields.*.order_index' => 'nullable|integer|min:0',
            'fields.*.is_hidden_label' => 'boolean',
            'fields.*.default_value' => 'nullable|string|max:2000',
            'fields.*.width' => 'nullable|string|in:100,50',
            'fields.*.is_hidden' => 'boolean',
            'fields.*.page_index' => 'nullable|integer|min:0',
        ]);
        $fieldsData = $validated['fields'];

        $fields = DB::transaction(function () use ($form, $fieldsData) {
            $incomingIds = collect($fieldsData)->pluck('id')->filter()->all();

            $form->formFields()->whereNotIn('id', $incomingIds)->delete();

            foreach ($fieldsData as $i => $fd) {
                $attributes = [
                    'form_id' => $form->id,
                    'type' => $fd['type'],
                    'label' => $fd['label'],
                    'help_text' => $fd['help_text'] ?? null,
                    'placeholder' => $fd['placeholder'] ?? null,
                    'required' => $fd['required'] ?? false,
                    'options_json' => $fd['options_json'] ?? null,
                    'validation_json' => $fd['validation_json'] ?? null,
                    'logic_json' => $fd['logic_json'] ?? null,
                    'order_index' => $fd['order_index'] ?? $i,
                    'is_hidden_label' => $fd['is_hidden_label'] ?? false,
                    'default_value' => $fd['default_value'] ?? null,
                    'width' => $fd['width'] ?? '100',
                    'is_hidden' => $fd['is_hidden'] ?? false,
                    'page_index' => $fd['page_index'] ?? 0,
                ];

                if (! empty($fd['id'])) {
                    FormField::where('id', $fd['id'])
                        ->where('form_id', $form->id)
                        ->update($attributes);
                } else {
                    FormField::create($attributes);
                }
            }

            return $form->formFields()->orderBy('order_index')->get();
        });

        return response()->json(['data' => $fields]);
    }
}
