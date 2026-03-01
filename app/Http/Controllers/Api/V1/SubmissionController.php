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
            ->orderBy('submitted_at', 'desc')
            ->paginate($perPage);

        return response()->json($submissions);
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
