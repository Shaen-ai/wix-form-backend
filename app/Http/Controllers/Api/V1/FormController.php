<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Tenant;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FormController extends Controller
{
    /**
     * GET /forms — works with OR without auth.
     *
     * Authenticated (settings panel / widget with token):
     *   tenant-scoped, creates form via firstOrCreate if widgetInstanceId given.
     *
     * Unauthenticated (widget in editor):
     *   read-only lookup by widgetInstanceId; requires X-Wix-Comp-Id header.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant')
            ?? $this->resolveTenantFromAuth($request);

        $widgetInstanceId = $request->query('widgetInstanceId');

        if ($tenant && $widgetInstanceId) {
            $form = $this->resolveFormForTenant($tenant, $widgetInstanceId);

            if ($form->formFields()->count() === 0) {
                $this->seedDefaultFields($form);
            }

            $form->load('formFields');
            $form->setAttribute('plan', $tenant->plan ?? 'free');

            return response()->json(['data' => [$form]]);
        }

        if ($tenant) {
            $forms = Form::where('tenant_id', $tenant->id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json(['data' => $forms]);
        }

        // No auth — allow read-only lookup with X-Wix-Comp-Id
        $compId = $request->header('X-Wix-Comp-Id');

        if (! $widgetInstanceId || ! $compId) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $form = Form::where('widget_instance_id', $widgetInstanceId)
            ->where('is_active', true)
            ->with('formFields')
            ->first();

        if (! $form) {
            return response()->json(['data' => []]);
        }

        $form->setAttribute('plan', $form->tenant?->plan ?? 'free');

        return response()->json(['data' => [$form]]);
    }

    /**
     * GET /form — returns a single form for the widget.
     *
     * Reads widgetInstanceId from the X-Wix-Comp-Id header.
     * If authenticated and the form doesn't exist, creates it with defaults.
     * If unauthenticated, performs a read-only lookup.
     */
    public function showByWidget(Request $request): JsonResponse
    {
        $widgetInstanceId = $request->header('X-Wix-Comp-Id');

        if (! $widgetInstanceId) {
            return response()->json(['message' => 'X-Wix-Comp-Id header is required'], 422);
        }

        $tenant = $request->attributes->get('tenant')
            ?? $this->resolveTenantFromAuth($request);

        if ($tenant) {
            $form = $this->resolveFormForTenant($tenant, $widgetInstanceId);

            if ($form->formFields()->count() === 0) {
                $this->seedDefaultFields($form);
            }

            $form->load('formFields');
            $form->setAttribute('plan', $tenant->plan ?? 'free');

            return response()->json(['data' => $form]);
        }

        $form = Form::where('widget_instance_id', $widgetInstanceId)
            ->where('is_active', true)
            ->with('formFields')
            ->first();

        if (! $form) {
            $form = Form::create([
                'tenant_id'          => null,
                'widget_instance_id' => $widgetInstanceId,
                'name'               => 'Contact Form',
                'description'        => '',
                'is_active'          => true,
            ]);

            $this->seedDefaultFields($form);
            $form->load('formFields');
        }

        $form->setAttribute('plan', $form->tenant?->plan ?? 'free');

        return response()->json(['data' => $form]);
    }

    /**
     * Try to resolve tenant from Authorization header inline (so the route
     * can live outside the WixInstanceAuth middleware while still supporting
     * authenticated callers like the settings panel).
     */
    private function resolveTenantFromAuth(Request $request): ?Tenant
    {
        $auth = $request->header('Authorization');
        if (! $auth || ! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        $token = substr($auth, 7);

        try {
            $key = config('app.jwt_secret');

            if (empty($key)) {
                if (app()->environment('production')) {
                    return null;
                }
                $parts = explode('.', $token);
                $payload = isset($parts[1])
                    ? (json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true) ?? [])
                    : [];
            } else {
                $payload = (array) JWT::decode($token, new Key($key, 'HS256'));
            }
        } catch (\Throwable $e) {
            Log::debug('[FormController] Inline JWT decode failed', ['error' => $e->getMessage()]);
            return null;
        }

        $wixSiteId = $payload['wixSiteId'] ?? $payload['siteId'] ?? null;
        $wixInstanceId = $payload['wixInstanceId'] ?? $payload['instanceId'] ?? null;

        if (! $wixSiteId) {
            return null;
        }

        return Tenant::updateOrCreate(
            ['wix_site_id' => $wixSiteId],
            ['plan' => app()->environment('local') ? 'premium' : 'free', 'wix_instance_id' => $wixInstanceId]
        );
    }

    /**
     * POST /forms/ensure — idempotently ensure a form exists for the
     * authenticated tenant + widgetInstanceId pair.  Returns the form
     * (with fields) whether it was just created or already existed.
     */
    public function ensure(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $widgetInstanceId = $request->input('widgetInstanceId')
            ?? $request->header('X-Wix-Comp-Id');

        if (! $widgetInstanceId) {
            return response()->json(['message' => 'widgetInstanceId is required'], 422);
        }

        $form = $this->resolveFormForTenant($tenant, $widgetInstanceId);

        if ($form->formFields()->count() === 0) {
            $this->seedDefaultFields($form);
        }

        $form->load('formFields');
        $form->setAttribute('plan', $tenant->plan ?? 'free');

        return response()->json(['data' => $form]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $form = Form::where('tenant_id', $tenant->id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'settings_json' => 'nullable|array',
            'is_active' => 'boolean',
            'status' => 'sometimes|string|in:draft,published',
            'language' => 'nullable|string|max:10',
        ]);

        $form->update($validated);
        return response()->json($form);
    }

    /**
     * Find or create a form for a tenant + widgetInstanceId pair.
     * Adopts orphaned forms (tenant_id IS NULL) created by the
     * unauthenticated widget path before the settings panel was opened.
     */
    private function resolveFormForTenant(Tenant $tenant, string $widgetInstanceId): Form
    {
        $form = Form::where('widget_instance_id', $widgetInstanceId)->first();

        if ($form) {
            if ($form->tenant_id === null) {
                $form->update(['tenant_id' => $tenant->id]);
            }

            return $form;
        }

        return Form::create([
            'tenant_id'          => $tenant->id,
            'widget_instance_id' => $widgetInstanceId,
            'name'               => 'Contact Form',
            'description'        => '',
            'is_active'          => true,
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
