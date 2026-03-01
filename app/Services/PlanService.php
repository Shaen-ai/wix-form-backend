<?php

namespace App\Services;

use App\Models\Form;

class PlanService
{
    private const PLAN_LIMITS = [
        'free'         => ['max_total_file_size_mb' => 200,  'monthly_submissions' => 100,   'show_branding' => true],
        'light'        => ['max_total_file_size_mb' => 1024, 'monthly_submissions' => 1000,  'show_branding' => false],
        'business'     => ['max_total_file_size_mb' => 5120, 'monthly_submissions' => 10000, 'show_branding' => false],
        'business_pro' => ['max_total_file_size_mb' => 0,    'monthly_submissions' => 0,     'show_branding' => false],
    ];

    public function isPaid(Form $form): bool
    {
        return ($form->plan ?? 'free') !== 'free';
    }

    public function getLimits(Form $form): array
    {
        return self::PLAN_LIMITS[$form->plan ?? 'free'] ?? self::PLAN_LIMITS['free'];
    }

    public function maxTotalFileSizeBytes(Form $form): int
    {
        $mb = $this->getLimits($form)['max_total_file_size_mb'];
        return $mb === 0 ? PHP_INT_MAX : $mb * 1024 * 1024;
    }

    public function monthlySubmissionLimit(Form $form): int
    {
        return $this->getLimits($form)['monthly_submissions'];
    }

    public function showBranding(Form $form): bool
    {
        return $this->getLimits($form)['show_branding'];
    }
}
