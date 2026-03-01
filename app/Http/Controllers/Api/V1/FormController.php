<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FormController extends Controller
{
    /**
     * GET /forms — list forms for the authenticated instance,
     * or read-only lookup by comp_id when unauthenticated.
     */
    public function index(Request $request): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId')
            ?? $this->resolveInstanceIdFromAuth($request);

        $compId = $request->query('compId')
            ?? $request->query('widgetInstanceId');

        if ($instanceId && $compId) {
            $form = $this->resolveForm($instanceId, $compId);

            if ($form->formFields()->count() === 0) {
                $this->seedDefaultFields($form);
            }

            $form->load('formFields');

            return response()->json(['data' => [$form]]);
        }

        if ($instanceId) {
            $forms = Form::where('instance_id', $instanceId)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json(['data' => $forms]);
        }

        $compId = $request->header('X-Wix-Comp-Id') ?? $compId;

        if (! $compId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $form = Form::where('comp_id', $compId)
            ->where('is_active', true)
            ->with('formFields')
            ->first();

        if (! $form) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => [$form]]);
    }

    /**
     * GET /form — returns a single form for the widget.
     * Reads comp_id from the X-Wix-Comp-Id header.
     */
    public function showByWidget(Request $request): JsonResponse
    {
        $compId = $request->header('X-Wix-Comp-Id');

        if (! $compId) {
            return response()->json(['message' => 'X-Wix-Comp-Id header is required'], 422);
        }

        $instanceId = $request->attributes->get('instanceId')
            ?? $this->resolveInstanceIdFromAuth($request);

        if ($instanceId) {
            $form = $this->resolveForm($instanceId, $compId);

            if ($form->formFields()->count() === 0) {
                $this->seedDefaultFields($form);
            }

            $form->load('formFields');

            return response()->json(['data' => $form]);
        }

        $form = Form::where('comp_id', $compId)
            ->where('is_active', true)
            ->with('formFields')
            ->first();

        if (! $form) {
            $form = Form::create([
                'instance_id' => null,
                'comp_id'     => $compId,
                'name'        => 'Contact Form',
                'description' => '',
                'is_active'   => true,
            ]);

            $this->seedDefaultFields($form);
            $form->load('formFields');
        }

        return response()->json(['data' => $form]);
    }

    /**
     * Try to resolve instance ID from Authorization header inline.
     * Uses the same multi-strategy approach as WixInstanceAuth middleware.
     */
    private function resolveInstanceIdFromAuth(Request $request): ?string
    {
        $auth = $request->header('Authorization');

        if (! $auth && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $auth = $value;
                    break;
                }
            }
        }

        if (! $auth || ! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        $token = substr($auth, 7);
        $key   = config('app.jwt_secret');

        $payload = null;

        if ($key) {
            try {
                $payload = (array) JWT::decode($token, new Key($key, 'HS256'));
            } catch (\Throwable $e) {
                Log::debug('[FormController] HS256 decode failed, trying fallback', [
                    'error' => $e->getMessage(),
                ]);
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

        if (! is_array($payload)) {
            return null;
        }

        $id = $payload['instanceId']
            ?? $payload['wixInstanceId']
            ?? $payload['instance_id']
            ?? null;

        if ($id) {
            return (string) $id;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data)) {
            $id = $data['instanceId'] ?? $data['wixInstanceId'] ?? $data['instance_id'] ?? null;
            if ($id) {
                return (string) $id;
            }
        }

        if (isset($payload['sub']) && is_string($payload['sub']) && $payload['sub'] !== '') {
            return $payload['sub'];
        }

        return null;
    }

    /**
     * POST /forms/ensure — idempotently ensure a form exists for the
     * authenticated instance + comp_id pair.
     */
    public function ensure(Request $request): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');

        if (! $instanceId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $compId = $request->input('compId')
            ?? $request->input('widgetInstanceId')
            ?? $request->header('X-Wix-Comp-Id');

        if (! $compId) {
            return response()->json(['message' => 'compId is required'], 422);
        }

        $form = $this->resolveForm($instanceId, $compId);

        if ($form->formFields()->count() === 0) {
            $this->seedDefaultFields($form);
        }

        $form->load('formFields');

        return response()->json(['data' => $form]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');

        $form = Form::where(function ($q) use ($instanceId) {
            $q->where('instance_id', $instanceId)
              ->orWhereNull('instance_id');
        })->findOrFail($id);

        if ($form->instance_id === null && $instanceId) {
            $form->instance_id = $instanceId;
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'settings_json' => 'nullable|array',
            'is_active' => 'boolean',
            'status' => 'sometimes|string|in:draft,published',
            'language' => 'nullable|string|max:10',
        ]);

        $form->fill($validated)->save();

        return response()->json($form);
    }

    /**
     * Find or create a form for an instance_id + comp_id pair.
     * Adopts orphaned forms (instance_id IS NULL) created by the
     * unauthenticated widget path.
     */
    private function resolveForm(string $instanceId, string $compId): Form
    {
        $form = Form::where('comp_id', $compId)->first();

        if ($form) {
            if ($form->instance_id === null) {
                $form->update(['instance_id' => $instanceId]);
            }

            return $form;
        }

        return Form::create([
            'instance_id' => $instanceId,
            'comp_id'     => $compId,
            'name'        => 'Contact Form',
            'description' => '',
            'is_active'   => true,
        ]);
    }

    private function seedDefaultFields(Form $form): void
    {
        $defaults = [
            ['type' => 'name',           'label' => 'Full Name',  'required' => true,  'placeholder' => 'Your full name'],
            ['type' => 'email',          'label' => 'Email',      'required' => true,  'placeholder' => 'you@example.com'],
            ['type' => 'phone',          'label' => 'Phone',      'required' => false, 'placeholder' => '+1 (555) 000-0000'],
            ['type' => 'address_simple', 'label' => 'Address',    'required' => false, 'placeholder' => 'Your address'],
            ['type' => 'text',           'label' => 'Subject',    'required' => false, 'placeholder' => 'What is this about?'],
            ['type' => 'textarea',       'label' => 'Message',    'required' => true,  'placeholder' => 'Write your message here...'],
        ];

        foreach ($defaults as $i => $field) {
            $form->formFields()->create(array_merge($field, ['order_index' => $i]));
        }
    }
}
