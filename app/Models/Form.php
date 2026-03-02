<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Form extends Model
{
    protected $fillable = [
        'instance_id', 'comp_id', 'name', 'description',
        'settings_json', 'is_active', 'language', 'plan',
    ];

    protected $appends = ['premium_plan'];

    protected $casts = [
        'settings_json' => 'array',
        'is_active' => 'boolean',
    ];

    public function getPremiumPlanAttribute(): string
    {
        return $this->plan ?? 'basic';
    }

    public function formFields(): HasMany
    {
        return $this->hasMany(FormField::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(FormSettings::class);
    }
}
