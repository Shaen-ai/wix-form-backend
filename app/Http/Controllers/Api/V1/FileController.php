<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SubmissionFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    /**
     * Public download via signed URL (for admin notification emails).
     * Validates signature; no Wix auth required.
     */
    public function downloadPublic(Request $request, int $id): StreamedResponse
    {
        $file = SubmissionFile::findOrFail($id);

        if ($file->virus_status === 'infected') {
            abort(403, 'File unavailable');
        }

        $stream = Storage::disk($file->storage_disk)->readStream($file->path);
        if (! $stream) {
            abort(404, 'File not found');
        }

        return response()->streamDownload(
            function () use ($stream) {
                try {
                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
            $file->original_name,
            [
                'Content-Type' => $file->mime ?? 'application/octet-stream',
            ]
        );
    }

    public function download(Request $request, int $id): StreamedResponse
    {
        $instanceId = $request->attributes->get('instanceId');

        $file = SubmissionFile::whereHas('submission.form', fn ($q) => $q->where('instance_id', $instanceId))
            ->findOrFail($id);

        if ($file->virus_status === 'infected') {
            abort(403, 'File unavailable');
        }

        $stream = Storage::disk($file->storage_disk)->readStream($file->path);
        if (! $stream) {
            abort(404, 'File not found');
        }

        return response()->streamDownload(
            function () use ($stream) {
                try {
                    fpassthru($stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            },
            $file->original_name,
            [
                'Content-Type' => $file->mime ?? 'application/octet-stream',
            ]
        );
    }
}
