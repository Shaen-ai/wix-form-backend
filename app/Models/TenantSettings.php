<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSettings extends Model
{
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = 'tenant_id';

    protected $fillable = [
        'tenant_id', 'notification_email', 'auto_reply_enabled',
        'auto_reply_subject', 'auto_reply_body',
        'recaptcha_enabled', 'recaptcha_mode',
    ];

    protected $casts = [
        'auto_reply_enabled' => 'boolean',
        'recaptcha_enabled' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
