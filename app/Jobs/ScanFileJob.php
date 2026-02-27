<?php

namespace App\Jobs;

use App\Models\SubmissionFile;
use App\Services\VirusScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ScanFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private SubmissionFile $file
    ) {}

    public function handle(VirusScanService $scanner): void
    {
        $fullPath = Storage::disk($this->file->storage_disk)->path($this->file->path);
        $status = $scanner->scan($fullPath);
        $this->file->update(['virus_status' => $status]);
    }
}
