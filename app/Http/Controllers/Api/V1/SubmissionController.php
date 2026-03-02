<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Submission;
use App\Services\PlanService;
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

        $overLimitSet = $this->getOverLimitIdSet($form);

        $submissions->getCollection()->transform(function (Submission $sub) use ($overLimitSet) {
            if (isset($overLimitSet[$sub->id])) {
                $sub->data_json = array_fill_keys(array_keys($sub->data_json ?? []), '*******');
                $sub->is_over_limit = true;
            } else {
                $sub->is_over_limit = false;
            }
            return $sub;
        });

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

        $overLimitSet = $this->getOverLimitIdSet($form);

        $keys = ['id', 'submitted_at'];
        foreach ($submissions as $s) {
            foreach (array_keys($s->data_json ?? []) as $k) {
                if (! in_array($k, $keys)) {
                    $keys[] = $k;
                }
            }
        }

        return response()->streamDownload(function () use ($submissions, $keys, $overLimitSet) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $keys);
            foreach ($submissions as $s) {
                $isOverLimit = isset($overLimitSet[$s->id]);
                $row = [];
                foreach ($keys as $col) {
                    if ($col === 'id') {
                        $row[] = $s->id;
                    } elseif ($col === 'submitted_at') {
                        $row[] = $s->submitted_at?->toIso8601String() ?? '';
                    } else {
                        if ($isOverLimit) {
                            $row[] = '*******';
                        } else {
                            $val = ($s->data_json ?? [])[$col] ?? null;
                            $row[] = is_array($val) || is_object($val) ? json_encode($val) : (string) $val;
                        }
                    }
                }
                fputcsv($out, $row);
            }
            fclose($out);
        }, "submissions-{$id}.csv", [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Returns a set (array keyed by submission ID) of submission IDs that exceed
     * the plan's monthly limit for the current month.
     * The oldest `limit` submissions of the current month are considered "within limit";
     * any beyond that are over-limit and should have their data masked.
     */
    private function getOverLimitIdSet(Form $form): array
    {
        $planService = app(PlanService::class);
        $monthlyLimit = $planService->monthlySubmissionLimit($form);

        if ($monthlyLimit === 0) {
            return [];
        }

        $thisMonthIds = Submission::where('form_id', $form->id)
            ->whereYear('submitted_at', now()->year)
            ->whereMonth('submitted_at', now()->month)
            ->orderBy('submitted_at', 'asc')
            ->pluck('id')
            ->all();

        if (count($thisMonthIds) <= $monthlyLimit) {
            return [];
        }

        $overLimitIds = array_slice($thisMonthIds, $monthlyLimit);

        return array_flip($overLimitIds);
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
