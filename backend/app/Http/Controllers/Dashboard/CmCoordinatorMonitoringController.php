<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\CmCoordinatorMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CmCoordinatorMonitoringController extends Controller
{
    public function __construct(private CmCoordinatorMonitoringService $monitoringService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'week' => ['nullable', 'string', 'regex:/^(all|[1-5])$/'],
            'gathering_type' => ['nullable', 'in:all,pm,ws,tg'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->monitoringService->getPayload($filters));
    }
}
