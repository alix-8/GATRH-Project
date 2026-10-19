<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;

class AdminActivityLogController extends Controller
{
    public function index(): JsonResponse
    {
        $activityLogs = AuditLog::query()
            ->with('actor:id,name')
            ->latest()
            ->paginate(25)
            ->through(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'target_table' => $log->target_table,
                'target_id' => $log->target_id,
                'performed_by' => $log->actor?->name ?? 'Unknown user',
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['activity_log' => $activityLogs]);
    }
}
