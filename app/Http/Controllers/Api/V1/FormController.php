<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormSettings;
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
        $tokenInfo  = $this->resolveTokenInfoFromRequest($request);
        $instanceId = $tokenInfo['instanceId'];

        $compId = $request->query('compId')
            ?? $request->query('widgetInstanceId');

        if ($instanceId && $compId) {
            $form = $this->resolveForm($instanceId, $compId);

            $this->syncPlanFromRequest($form, $request);

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

        // Single call: resolves instanceId, vendorProductId, and the raw Token Info payload.
        // Result is cached in request attributes so nothing is called twice.
        $tokenInfo  = $this->resolveTokenInfoFromRequest($request);
        $instanceId = $tokenInfo['instanceId'] ?? null;

        if ($instanceId) {
            $form = $this->resolveForm($instanceId, $compId);

            $vendorProductId = $tokenInfo['vendorProductId'] ?? null;
            $plan            = $this->planService->planFromVendorProductId($vendorProductId);

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
                    'instance_token'   => $tokenInfo['instanceToken'] ?? null,
                    'decoded_instance' => $tokenInfo['raw'] ?? null,
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
     * Resolve the full token info for this request in one step.
     *
     * Strategy:
     *   1. Return cached attributes set by WixInstanceAuth middleware (when present).
     *   2. POST the raw token to https://www.wixapis.com/oauth2/token-info (Wix OAuth tokens).
     *   3. Fall back to local JWT decode for dev/legacy tokens signed with our own secret.
     *
     * Result is cached in request attributes so subsequent calls within the same
     * request pay zero extra cost.
     *
     * @return array{instanceId: ?string, vendorProductId: ?string, instanceToken: ?string, raw: array}
     */
    private function resolveTokenInfoFromRequest(Request $request): array
    {
        // Already resolved by WixInstanceAuth middleware — just repackage.
        if ($request->attributes->has('instanceId')) {
            return [
                'instanceId'      => $request->attributes->get('instanceId'),
                'vendorProductId' => $request->attributes->get('vendorProductId'),
                'instanceToken'   => $request->attributes->get('instanceToken'),
                'raw'             => $request->attributes->get('tokenInfoRaw', []),
            ];
        }

        $empty = ['instanceId' => null, 'vendorProductId' => null, 'instanceToken' => null, 'raw' => []];

        $auth = $this->resolveAuthHeader($request);
        if (! $auth) {
            return $empty;
        }

        $token = AuthHelper::extractTokenFromAuthHeader($auth);
        if (! $token) {
            return $empty;
        }

        // ── Strategy 1: Wix Token Info API ──────────────────────────────────────
        // Handles all Wix OAuth / instance tokens (the authoritative source).
        $tokenInfo = app(WixTokenInfoService::class)->getTokenInfo($token);
        if ($tokenInfo) {
            $result = [
                'instanceId'      => $tokenInfo['instanceId'],
                'vendorProductId' => $tokenInfo['vendorProductId'],
                'instanceToken'   => $token,
                'raw'             => $tokenInfo['raw'],
            ];
            $request->attributes->set('instanceId',      $result['instanceId']);
            $request->attributes->set('vendorProductId', $result['vendorProductId']);
            $request->attributes->set('instanceToken',   $result['instanceToken']);
            $request->attributes->set('tokenInfoRaw',    $result['raw']);
            return $result;
        }

        // ── Strategy 2: local decode (dev tokens / classic Wix instance tokens) ─
        $payload = $this->decodeTokenPayloadLocally($token);
        if (! $payload) {
            return $empty;
        }

        $instanceId = $payload['instanceId'] ?? $payload['wixInstanceId'] ?? $payload['instance_id'] ?? null;
        $data       = $payload['data'] ?? null;
        if (is_string($data)) {
            $data = json_decode($data, true);
        }
        if (! $instanceId && is_array($data)) {
            $instanceId = $data['instanceId'] ?? $data['wixInstanceId'] ?? $data['instance_id'] ?? null;
        }
        if (! $instanceId && isset($payload['sub']) && $payload['sub'] !== '') {
            $instanceId = $payload['sub'];
        }

        $vendorProductId = $payload['vendorProductId'] ?? $payload['vendor_product_id'] ?? null;
        if (! $vendorProductId && is_array($data)) {
            $vendorProductId = $data['vendorProductId'] ?? $data['vendor_product_id'] ?? null;
        }

        $result = [
            'instanceId'      => $instanceId ? (string) $instanceId : null,
            'vendorProductId' => $vendorProductId ? (string) $vendorProductId : null,
            'instanceToken'   => $token,
            'raw'             => $payload,
        ];
        $request->attributes->set('instanceId',      $result['instanceId']);
        $request->attributes->set('vendorProductId', $result['vendorProductId']);
        $request->attributes->set('instanceToken',   $result['instanceToken']);
        return $result;
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

        if (array_key_exists('settings_json', $validated)
            && is_array($validated['settings_json'])
            && array_key_exists('userConfirmationEmail', $validated['settings_json'])) {
            FormSettings::updateOrCreate(
                ['form_id' => $form->id],
                ['auto_reply_enabled' => (bool) $validated['settings_json']['userConfirmationEmail']]
            );
        }

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
     * Decode the JWT / classic Wix instance token payload locally (no signature verification).
     * Returns the payload array on success, null if the token format is unrecognised
     * or if the payload does not contain an instanceId claim (i.e. it's the JWT header).
     */
    private function decodeTokenPayloadLocally(string $token): ?array
    {
        $key   = config('app.jwt_secret');
        $parts = explode('.', $token);

        // Strategy 1: HS256 JWT with our own secret
        if ($key && count($parts) === 3) {
            try {
                return (array) JWT::decode($token, new Key($key, 'HS256'));
            } catch (\Throwable) {
                // fall through
            }
        }

        // Strategy 2: classic Wix instance token — sig.payload (2 parts)
        if (count($parts) === 2) {
            $decoded = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            if (is_array($decoded) && isset($decoded['instanceId'])) {
                return $decoded;
            }
        }

        // Strategy 3: standard/non-standard JWT — try each middle segment for a valid payload
        if (count($parts) >= 3) {
            foreach (range(1, min(4, count($parts) - 1)) as $idx) {
                if (! isset($parts[$idx])) {
                    continue;
                }
                $decoded = json_decode(base64_decode(strtr($parts[$idx], '-_', '+/')), true);
                // Only accept segments that look like a real payload (contain instanceId)
                if (is_array($decoded) && (
                    isset($decoded['instanceId']) || isset($decoded['wixInstanceId']) || isset($decoded['instance_id'])
                )) {
                    return $decoded;
                }
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
