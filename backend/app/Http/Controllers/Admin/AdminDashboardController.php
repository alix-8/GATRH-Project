<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminDashboardService;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __construct(private AdminDashboardService $dashboardService)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->dashboardService->getPayload());
    }
}
