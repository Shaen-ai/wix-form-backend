<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormSettings extends Model
{
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = 'form_id';

    protected $fillable = [
        'form_id', 'notification_email', 'auto_reply_enabled',
        'auto_reply_subject', 'auto_reply_body',
    ];

    protected $casts = [
        'auto_reply_enabled' => 'boolean',
    ];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }
}
