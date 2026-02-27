<?php

namespace App\Console\Commands;

use App\Models\Submission;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneSubmissions extends Command
{
    protected $signature = 'submissions:prune {--days=100}';

    protected $description = 'Delete submissions older than N days and remove associated files';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);
        $count = 0;

        Submission::with('submissionFiles')
            ->where('submitted_at', '<', $cutoff)
            ->chunkById(100, function ($submissions) use (&$count) {
                foreach ($submissions as $submission) {
                    foreach ($submission->submissionFiles as $file) {
                        $file->deleteFromStorage();
                    }
                    $submission->delete();
                    $count++;
                }
            });

        $this->info("Pruned {$count} submissions older than {$days} days.");
        return 0;
    }
}
