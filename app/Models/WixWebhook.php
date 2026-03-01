<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WixWebhook extends Model
{
    protected $table = 'wix_webhooks';

    protected $fillable = [
        'type',
        'instance',
        'origin_instance',
        'user_id',
        'content',
    ];

    public $timestamps = false;

    protected $casts = [
        'content' => 'array',
    ];
}
