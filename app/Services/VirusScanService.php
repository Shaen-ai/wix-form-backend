<?php

namespace App\Services;

use App\Jobs\ScanFileJob;
use App\Models\SubmissionFile;

class VirusScanService
{
    public function queueScan(SubmissionFile $file): void
    {
        ScanFileJob::dispatch($file);
    }

    /**
     * Run ClamAV scan on a file.
     * Requires clamav/clamav or system clamscan.
     */
    public function scan(string $path): string
    {
        $clamscan = config('services.clamav.path', 'clamscan');
        $output = [];
        $exitCode = 0;
        exec(sprintf('%s --no-summary %s 2>&1', escapeshellcmd($clamscan), escapeshellarg($path)), $output, $exitCode);

        // Exit 0 = clean, 1 = infected
        if ($exitCode === 0) {
            return 'clean';
        }
        if ($exitCode === 1) {
            return 'infected';
        }
        return 'error';
    }
}
