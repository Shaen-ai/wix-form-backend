<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormSettingsController extends Controller
{
    public function show(Request $request, int $formId): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $formId);

        $settings = FormSettings::find($form->id);
        return response()->json($settings ?? [
            'form_id' => $form->id,
            'notification_email' => null,
            'auto_reply_enabled' => false,
            'auto_reply_subject' => null,
            'auto_reply_body' => null,
            'recaptcha_enabled' => true,
            'recaptcha_mode' => null,
        ]);
    }

    public function update(Request $request, int $formId): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $formId);

        $validated = $request->validate([
            'notification_email' => 'nullable|email',
            'auto_reply_enabled' => 'boolean',
            'auto_reply_subject' => 'nullable|string',
            'auto_reply_body' => 'nullable|string',
            'recaptcha_enabled' => 'boolean',
            'recaptcha_mode' => 'nullable|string|in:v2_checkbox,v2_invisible,v3',
        ]);

        $settings = FormSettings::updateOrCreate(
            ['form_id' => $form->id],
            $validated
        );

        return response()->json($settings);
    }

    private function resolveForm(?string $instanceId, int $formId): Form
    {
        $form = Form::where(function ($q) use ($instanceId) {
            $q->where('instance_id', $instanceId)
              ->orWhereNull('instance_id');
        })->findOrFail($formId);

        if ($form->instance_id === null && $instanceId) {
            $form->update(['instance_id' => $instanceId]);
        }

        return $form;
    }
}
