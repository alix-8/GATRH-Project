<?php

namespace App\Services\Admin;

use App\Models\AuditLog;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;

class AdminDashboardService
{
    public function getPayload(): array
    {
        $users = User::query()->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) as active')->first();
        $programs = Program::query()->selectRaw('COUNT(*) as total, COALESCE(SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END), 0) as active')->first();
        return [
            'summary' => [
                'user_accounts' => (int) $users->total,
                'active_users' => (int) $users->active,
                'students' => Student::query()->active()->count(),
                'programs' => (int) $programs->total,
                'active_programs' => (int) $programs->active,
            ],
            'accounts' => $this->getAccountSummary(),
            'recent_activity' => $this->getRecentActivity(),
            'system_status' => 'Active',
        ];
    }

    private function getAccountSummary(): array
    {
        $roleLabels = [
            'chancellor' => 'Chancellor',
            'program_head' => 'Program Heads',
            'program_secretary' => 'Program Secretaries',
            'secretary' => 'Secretaries',
            'admin' => 'Administrators',
        ];

        return User::query()
            ->selectRaw('role, COUNT(*) as total')
            ->groupBy('role')
            ->get()
            ->map(fn ($row) => [
                'role' => $row->role,
                'label' => $roleLabels[$row->role] ?? str($row->role)->replace('_', ' ')->title()->toString(),
                'count' => (int) $row->total,
            ])
            ->sortBy(fn ($account) => array_search($account['role'], array_keys($roleLabels), true) ?: 0)
            ->values()
            ->toArray();
    }

    private function getRecentActivity(): array
    {
        return AuditLog::query()
            ->performedByAdministrators()
            ->with('actor:id,name')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'target' => $log->target_table,
                'target_id' => $log->target_id,
                'module' => $log->moduleLabel(),
                'result' => $log->resultLabel(),
                'performed_by' => $log->actor?->name ?? 'Unknown user',
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->toArray();
    }
}
