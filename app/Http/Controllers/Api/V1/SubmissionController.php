<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function index(Request $request, int $id): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $id);

        $perPage = min((int) $request->query('per_page', 25), 100);

        $submissions = Submission::where('form_id', $form->id)
            ->with('submissionFiles')
            ->orderBy('submitted_at', 'desc')
            ->paginate($perPage);

        return response()->json($submissions);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $id);

        $validated = $request->validate([
            'data' => 'required|array',
        ]);

        $fields = $form->formFields()->get()->keyBy('id');
        $data = $this->normalizeCompoundFields($validated['data'], $fields);

        $submission = Submission::create([
            'form_id' => $form->id,
            'submitted_at' => now(),
            'data_json' => $data,
        ]);

        return response()->json([
            'message' => 'Submission created.',
            'id' => $submission->id,
            'data' => $submission->load('submissionFiles'),
        ], 201);
    }

    public function update(Request $request, int $id, int $submissionId): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $id);

        $validated = $request->validate([
            'data' => 'required|array',
        ]);

        $submission = Submission::where('form_id', $form->id)->findOrFail($submissionId);
        $fields = $form->formFields()->get()->keyBy('id');
        $data = $this->normalizeCompoundFields($validated['data'], $fields);

        $submission->data_json = $data;
        $submission->save();

        return response()->json([
            'message' => 'Submission updated.',
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

    public function exportCsv(Request $request, int $id): StreamedResponse
    {
        $instanceId = $request->attributes->get('instanceId');
        $form = $this->resolveForm($instanceId, $id);
        $submissions = Submission::where('form_id', $form->id)
            ->orderBy('submitted_at', 'desc')
            ->get();

        $keys = ['id', 'submitted_at'];
        foreach ($submissions as $s) {
            foreach (array_keys($s->data_json ?? []) as $k) {
                if (! in_array($k, $keys)) {
                    $keys[] = $k;
                }
            }
        }

        return response()->streamDownload(function () use ($submissions, $keys) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $keys);
            foreach ($submissions as $s) {
                $row = [];
                foreach ($keys as $col) {
                    if ($col === 'id') {
                        $row[] = $s->id;
                    } elseif ($col === 'submitted_at') {
                        $row[] = $s->submitted_at?->toIso8601String() ?? '';
                    } else {
                        $val = ($s->data_json ?? [])[$col] ?? null;
                        $row[] = is_array($val) || is_object($val) ? json_encode($val) : (string) $val;
                    }
                }
                fputcsv($out, $row);
            }
            fclose($out);
        }, "submissions-{$id}.csv", [
            'Content-Type' => 'text/csv',
        ]);
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
}
