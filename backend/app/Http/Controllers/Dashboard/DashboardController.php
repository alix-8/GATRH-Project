<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService)
    {
    }

    /**
     * Display the dashboard payload tailored to the user's role.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Validate filter inputs supported by both UI views
        $validated = $request->validate([
            'date' => ['nullable', 'date'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'week' => ['nullable', 'string'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'attendance_period' => ['nullable', 'in:week,month'],
        ]);

        $filters = [
            'date' => $validated['date'] ?? now()->toDateString(),
            'program_id' => $validated['program_id'] ?? null,
            'week' => $validated['week'] ?? 'all',
            'month' => $validated['month'] ?? now()->month,
            'year' => $validated['year'] ?? now()->year,
            'attendance_period' => $validated['attendance_period'] ?? 'week',
        ];

        // Direct request handling based on user role
        if ($user->hasRole(['chancellor', 'cm_coordinator', 'admin'])) {
            $data = $this->dashboardService->getChancellorDashboardPayload($user, $filters);
        } elseif ($user->hasRole('program_head')) {
            $data = $this->dashboardService->getProgramHeadDashboardPayload($user, $filters);
        } else {
            // Default payload for other roles (e.g., Secretary)
            $data = $this->dashboardService->getGeneralDashboardPayload($user, $filters);
        }

        return response()->json($data);
    }
}
