<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $fillable = ['wix_site_id', 'wix_instance_id', 'plan'];

    protected $casts = [
        'plan' => 'string',
    ];

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(TenantSettings::class);
    }
}
