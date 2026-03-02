<?php

namespace App\Services;

use App\Models\Form;

class PlanService
{
    private const PLAN_LIMITS = [
        'basic'        => ['max_total_file_size_mb' => 200,   'monthly_submissions' => 100,  'max_fields_per_form' => 10,  'show_branding' => true],
        'light'        => ['max_total_file_size_mb' => 1024,  'monthly_submissions' => 1000, 'max_fields_per_form' => 100, 'show_branding' => false],
        'business'     => ['max_total_file_size_mb' => 3072,  'monthly_submissions' => 0,    'max_fields_per_form' => 0,   'show_branding' => false],
        'business_pro' => ['max_total_file_size_mb' => 10240, 'monthly_submissions' => 0,    'max_fields_per_form' => 0,   'show_branding' => false],
    ];

    /**
     * Map the `vendorProductId` from the Wix instance token to a plan key.
     * The vendorProductId is whatever slug you configured in the Wix Dev Center
     * for each premium plan (e.g. "light", "business", "business_pro").
     * Returns 'basic' when null/empty or unrecognised.
     */
    public function planFromVendorProductId(?string $vendorProductId): string
    {
        if (empty($vendorProductId)) {
            return 'basic';
        }
        $normalized = strtolower(str_replace([' ', '-'], '_', trim($vendorProductId)));
        return isset(self::PLAN_LIMITS[$normalized]) ? $normalized : 'basic';
    }

    public function isPaid(Form $form): bool
    {
        return ($form->plan ?? 'basic') !== 'basic';
    }

    public function getLimits(Form $form): array
    {
        return self::PLAN_LIMITS[$form->plan ?? 'basic'] ?? self::PLAN_LIMITS['basic'];
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

    /** Returns 0 to indicate unlimited fields. */
    public function maxFieldsPerForm(Form $form): int
    {
        return $this->getLimits($form)['max_fields_per_form'];
    }

    public function showBranding(Form $form): bool
    {
        return $this->getLimits($form)['show_branding'];
    }
}
