<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService)
    {
    }

    /**
     * Display the dashboard payload for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $filter = $request->input('range', 'this_week');
        $calendarMonth = $request->integer('month', now()->month);
        $calendarYear = $request->integer('year', now()->year);

        return response()->json(
            $this->dashboardService->getDashboardPayload($user, $filter, $calendarMonth, $calendarYear)
        );
    }
}