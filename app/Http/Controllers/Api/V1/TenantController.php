<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\PlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(private PlanService $planService) {}

    public function me(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        return response()->json([
            'tenant' => $tenant,
            'plan' => $tenant->plan,
            'plan_limits' => $this->planService->getLimits($tenant),
        ]);
    }
}
