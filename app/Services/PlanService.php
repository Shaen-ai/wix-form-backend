<?php

namespace App\Services;

use App\Models\Tenant;

class PlanService
{
    private const PLAN_LIMITS = [
        'free'         => ['max_total_file_size_mb' => 200,  'monthly_submissions' => 100,   'show_branding' => true],
        'light'        => ['max_total_file_size_mb' => 1024, 'monthly_submissions' => 1000,  'show_branding' => false],
        'business'     => ['max_total_file_size_mb' => 5120, 'monthly_submissions' => 10000, 'show_branding' => false],
        'business_pro' => ['max_total_file_size_mb' => 0,    'monthly_submissions' => 0,     'show_branding' => false],
    ];

    public function isPaid(Tenant $tenant): bool
    {
        return $tenant->plan !== 'free';
    }

    public function getLimits(Tenant $tenant): array
    {
        return self::PLAN_LIMITS[$tenant->plan] ?? self::PLAN_LIMITS['free'];
    }

    public function maxTotalFileSizeBytes(Tenant $tenant): int
    {
        $mb = $this->getLimits($tenant)['max_total_file_size_mb'];
        return $mb === 0 ? PHP_INT_MAX : $mb * 1024 * 1024;
    }

    public function monthlySubmissionLimit(Tenant $tenant): int
    {
        return $this->getLimits($tenant)['monthly_submissions'];
    }

    public function showBranding(Tenant $tenant): bool
    {
        return $this->getLimits($tenant)['show_branding'];
    }
}
