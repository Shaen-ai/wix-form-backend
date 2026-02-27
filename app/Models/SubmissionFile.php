<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class SubmissionFile extends Model
{
    protected $fillable = [
        'submission_id', 'form_field_id', 'storage_disk', 'path',
        'original_name', 'mime', 'size_bytes', 'virus_status',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function formField(): BelongsTo
    {
        return $this->belongsTo(FormField::class);
    }

    public function deleteFromStorage(): void
    {
        Storage::disk($this->storage_disk)->delete($this->path);
    }
}
