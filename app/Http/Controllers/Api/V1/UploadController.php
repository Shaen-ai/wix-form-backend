<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\SubmissionFile;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    private const MAX_SINGLE_FILE_SIZE = 50 * 1024 * 1024; // 50 MB per file

    public function __construct(private PlanService $planService) {}

    public function init(Request $request): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');

        $validated = $request->validate([
            'form_field_id' => 'required|exists:form_fields,id',
            'filename' => 'required|string|max:255',
            'mime' => 'nullable|string|max:100',
            'size_bytes' => 'required|integer|max:' . self::MAX_SINGLE_FILE_SIZE,
        ]);

        $field = FormField::whereHas('form', fn ($q) => $q->where('instance_id', $instanceId))
            ->findOrFail($validated['form_field_id']);

        $form = $field->form;
        $maxTotalBytes = $this->planService->maxTotalFileSizeBytes($form);
        $currentUsage = SubmissionFile::whereHas('formField.form', fn ($q) => $q->where('id', $form->id))
            ->sum('size_bytes');

        if (($currentUsage + $validated['size_bytes']) > $maxTotalBytes) {
            return response()->json([
                'message' => 'File storage limit reached for your plan. Please upgrade for more storage.',
            ], 422);
        }

        if ($field->type !== 'file_upload') {
            return response()->json(['message' => 'Invalid field type'], 422);
        }

        $disk = config('filesystems.default');
        $path = sprintf('uploads/%s/%s', Str::ulid(), $validated['filename']);

        $record = SubmissionFile::create([
            'submission_id' => null,
            'form_field_id' => $field->id,
            'storage_disk' => $disk,
            'path' => $path,
            'original_name' => $validated['filename'],
            'mime' => $validated['mime'] ?? 'application/octet-stream',
            'size_bytes' => $validated['size_bytes'],
            'virus_status' => 'pending',
        ]);

        $uploadUrl = url('/api/v1/uploads/' . $record->id . '/upload');

        return response()->json([
            'file_id' => (string) $record->id,
            'upload_url' => $uploadUrl,
        ]);
    }

    public function upload(Request $request, int $id): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');

        $record = SubmissionFile::where('id', $id)
            ->whereNull('submission_id')
            ->where('virus_status', 'pending')
            ->whereHas('formField.form', fn ($q) => $q->where('instance_id', $instanceId))
            ->firstOrFail();

        $content = $request->getContent();
        if (empty($content)) {
            return response()->json(['message' => 'No file content'], 422);
        }

        if (strlen($content) > self::MAX_SINGLE_FILE_SIZE) {
            $record->delete();
            return response()->json(['message' => 'File too large'], 422);
        }

        Storage::disk($record->storage_disk)->put($record->path, $content);

        $record->update([
            'size_bytes' => strlen($content),
            'virus_status' => 'clean',
        ]);

        return response()->json(['message' => 'Upload successful', 'file_id' => (string) $record->id]);
    }

    public function complete(Request $request): JsonResponse
    {
        $instanceId = $request->attributes->get('instanceId');

        $validated = $request->validate([
            'file_id' => 'required|string',
        ]);

        $record = SubmissionFile::where('id', $validated['file_id'])
            ->whereNull('submission_id')
            ->whereHas('formField.form', fn ($q) => $q->where('instance_id', $instanceId))
            ->firstOrFail();

        if (! Storage::disk($record->storage_disk)->exists($record->path)) {
            $record->delete();
            return response()->json(['message' => 'File not found on storage'], 404);
        }

        return response()->json(['message' => 'Upload complete', 'file_id' => (string) $record->id]);
    }
}
