<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormField extends Model
{
    protected $table = 'form_fields';

    protected $fillable = [
        'form_id', 'type', 'label', 'help_text', 'placeholder',
        'required', 'options_json', 'validation_json', 'logic_json',
        'order_index', 'is_hidden_label', 'default_value', 'width',
        'is_hidden', 'page_index',
    ];

    protected $casts = [
        'required' => 'boolean',
        'options_json' => 'array',
        'validation_json' => 'array',
        'logic_json' => 'array',
        'is_hidden_label' => 'boolean',
        'is_hidden' => 'boolean',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function submissionFiles(): HasMany
    {
        return $this->hasMany(SubmissionFile::class);
    }
}
