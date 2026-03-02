<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Services\PlanService;
use App\Services\WixTokenInfoService;
use App\Support\AuthHelper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FormController extends Controller
{
    public function __construct(private readonly PlanService $planService) {}

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
            $authPresent = (bool) $this->resolveAuthHeader($request);
            Log::warning('[FormController] GET /forms 401: no instanceId and no compId', [
                'auth_present' => $authPresent,
                'hint'         => $authPresent
                    ? 'Token may be invalid, expired, or Authorization header stripped by proxy. Try adding ?compId=... or ensure X-Wix-Comp-Id header.'
                    : 'Provide Authorization header (or X-Authorization) and/or compId query param or X-Wix-Comp-Id header.',
            ]);

            return response()->json([
                'message' => 'Unauthorized',
                'hint'    => $authPresent
                    ? 'Token validation failed. Ensure token is valid and not expired. Alternatively provide compId or X-Wix-Comp-Id.'
                    : 'Provide Authorization (or X-Authorization) and/or compId.',
            ], 401);
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

            // Sync plan from token — vendorProductId may be in middleware attributes
            // or must be extracted directly (this route is not behind WixInstanceAuth).
            $vendorProductId = $request->attributes->get('vendorProductId')
                ?? $this->extractVendorProductIdFromAuth($request);

            $plan = $this->planService->planFromVendorProductId($vendorProductId);
            if ($form->plan !== $plan) {
                $form->update(['plan' => $plan]);
                Log::debug('[FormController] showByWidget: synced plan', [
                    'form_id'         => $form->id,
                    'vendorProductId' => $vendorProductId,
                    'plan'            => $plan,
                ]);
            }

            if ($form->formFields()->count() === 0) {
                $this->seedDefaultFields($form);
            }

            $form->load('formFields');

            return response()->json([
                'data' => $form,
                'meta' => [
                    'instance_id'      => $instanceId,
                    'instance_token'   => $request->attributes->get('instanceToken'),
                    'decoded_instance' => $this->resolveTokenPayloadFromAuth($request),
                ],
            ]);
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

        $resolvedInstanceId = $form->instance_id ?? null;

        return response()->json([
            'data' => $form,
            'meta' => [
                'instance_id'    => $resolvedInstanceId,
                'instance_token' => $request->attributes->get('instanceToken'),
            ],
        ]);
    }

    /**
     * Resolve auth token from various headers (Authorization is often stripped by proxies).
     */
    private function resolveAuthHeader(Request $request): ?string
    {
        $auth = $request->header('Authorization')
            ?? $request->header('X-Authorization')
            ?? $request->header('X-Instance-Token');

        if (! $auth && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (! $auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (! $auth && isset($_SERVER['HTTP_X_AUTHORIZATION'])) {
            $auth = $_SERVER['HTTP_X_AUTHORIZATION'];
        }
        if (! $auth && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (in_array(strtolower($name), ['authorization', 'x-authorization', 'x-instance-token'], true)) {
                    $auth = $value;
                    break;
                }
            }
        }

        return $auth ?: null;
    }

    /**
     * Try to resolve instance ID from Authorization header inline.
     * Uses the same multi-strategy approach as WixInstanceAuth middleware:
     * 1. Wix Token Info API (validates Wix OAuth tokens)
     * 2. Local JWT decode with app secret
     * 3. Decode payload without verification (Wix SDK tokens)
     */
    private function resolveInstanceIdFromAuth(Request $request): ?string
    {
        $auth = $this->resolveAuthHeader($request);

        if (! $auth) {
            return null;
        }

        $token = AuthHelper::extractTokenFromAuthHeader($auth);
        if (! $token) {
            return null;
        }

        // Primary: Wix Token Info API (validates Wix OAuth/instance tokens)
        $tokenInfo = app(WixTokenInfoService::class)->getTokenInfo($token);
        if ($tokenInfo) {
            return $tokenInfo['instanceId'];
        }

        // Fallback: local decode strategies
        $key = config('app.jwt_secret');
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
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (is_array($data)) {
            $id = $data['instanceId'] ?? $data['wixInstanceId'] ?? $data['instance_id'] ?? null;
            if ($id) {
                return (string) $id;
            }
        }

        $context = $payload['context'] ?? null;
        if (is_array($context)) {
            $id = $context['instanceId'] ?? $context['appInstanceId'] ?? $context['wixInstanceId'] ?? null;
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
        $this->syncPlanFromRequest($form, $request);

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

        $this->syncPlanFromRequest($form, $request);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'settings_json' => 'nullable|array',
            'is_active' => 'boolean',
            'language' => 'nullable|string|max:10',
        ]);

        $form->fill($validated)->save();

        return response()->json($form);
    }

    /**
     * Sync form->plan from the vendorProductId in the Wix instance token.
     * Only writes to DB when the plan actually changed.
     */
    private function syncPlanFromRequest(Form $form, Request $request): void
    {
        $vendorProductId = $request->attributes->get('vendorProductId');
        $plan = $this->planService->planFromVendorProductId($vendorProductId);
        if ($form->plan !== $plan) {
            $form->update(['plan' => $plan]);
            Log::debug('[FormController] Synced plan from token', [
                'form_id'           => $form->id,
                'vendorProductId'   => $vendorProductId,
                'plan'              => $plan,
            ]);
        }
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

    /**
     * Decode the raw token from the request and return the full payload array.
     * Used for debugging plan detection and exposing decoded claims to the client.
     */
    private function resolveTokenPayloadFromAuth(Request $request): ?array
    {
        $auth = $this->resolveAuthHeader($request);
        if (! $auth) {
            return null;
        }

        $token = AuthHelper::extractTokenFromAuthHeader($auth);
        if (! $token) {
            return null;
        }

        $parts = explode('.', $token);

        // Classic Wix instance token: sig.payload (2 parts)
        if (count($parts) === 2) {
            $decoded = json_decode(
                base64_decode(strtr($parts[1], '-_', '+/')),
                true,
            );
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Standard JWT: header.payload.signature (3+ parts)
        if (count($parts) >= 3) {
            // Try each middle segment (some Wix tokens have non-standard part counts)
            foreach (range(1, min(4, count($parts) - 1)) as $idx) {
                if (! isset($parts[$idx])) {
                    continue;
                }
                $decoded = json_decode(
                    base64_decode(strtr($parts[$idx], '-_', '+/')),
                    true,
                );
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return null;
    }

    /**
     * Extract the vendorProductId directly from the raw token payload.
     * Needed when this route is not behind WixInstanceAuth middleware.
     */
    private function extractVendorProductIdFromAuth(Request $request): ?string
    {
        $payload = $this->resolveTokenPayloadFromAuth($request);
        if (! $payload) {
            return null;
        }

        $id = $payload['vendorProductId'] ?? $payload['vendor_product_id'] ?? null;
        if ($id !== null && $id !== '') {
            return (string) $id;
        }

        $data = $payload['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (is_array($data)) {
            $id = $data['vendorProductId'] ?? $data['vendor_product_id'] ?? null;
            if ($id !== null && $id !== '') {
                return (string) $id;
            }
        }

        return null;
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
