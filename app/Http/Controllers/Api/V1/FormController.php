<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FormController extends Controller
{
    public function ensure(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $widgetInstanceId = $request->input('widgetInstanceId');
        if (empty($widgetInstanceId)) {
            return response()->json(['message' => 'widgetInstanceId required'], 422);
        }

        $form = Form::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'widget_instance_id' => $widgetInstanceId,
            ],
            [
                'name' => 'Contact Form',
                'description' => '',
                'is_active' => true,
            ]
        );

        if ($form->wasRecentlyCreated && $form->formFields()->count() === 0) {
            $this->seedDefaultFields($form);
        }

        return response()->json($form);
    }

    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if ($widgetInstanceId = $request->query('widgetInstanceId')) {
            $form = Form::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'widget_instance_id' => $widgetInstanceId,
                ],
                [
                    'name' => 'Contact Form',
                    'description' => '',
                    'is_active' => true,
                ]
            );

            if ($form->wasRecentlyCreated && $form->formFields()->count() === 0) {
                $this->seedDefaultFields($form);
            }

            $form->load('formFields');

            return response()->json(['data' => [$form]]);
        }

        $forms = Form::where('tenant_id', $tenant->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $forms]);
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
