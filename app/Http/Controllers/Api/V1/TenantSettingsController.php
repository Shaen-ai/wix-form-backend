<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $settings = TenantSettings::find($tenant->id);
        return response()->json($settings ?? [
            'tenant_id' => $tenant->id,
            'notification_email' => null,
            'auto_reply_enabled' => false,
            'auto_reply_subject' => null,
            'auto_reply_body' => null,
            'recaptcha_enabled' => true,
            'recaptcha_mode' => null,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $validated = $request->validate([
            'notification_email' => 'nullable|email',
            'auto_reply_enabled' => 'boolean',
            'auto_reply_subject' => 'nullable|string',
            'auto_reply_body' => 'nullable|string',
            'recaptcha_enabled' => 'boolean',
            'recaptcha_mode' => 'nullable|string|in:v2_checkbox,v2_invisible,v3',
        ]);

        $settings = TenantSettings::updateOrCreate(
            ['tenant_id' => $tenant->id],
            $validated
        );

        return response()->json($settings);
    }
}
