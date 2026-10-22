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
        return [
            'summary' => [
                'user_accounts' => User::query()->count(),
                'active_users' => User::query()->where('is_active', true)->count(),
                'students' => Student::query()->count(),
                'programs' => Program::query()->count(),
                'active_programs' => Program::query()->where('is_active', true)->count(),
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
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(6)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'target' => $log->target_table,
                'target_id' => $log->target_id,
                'module' => $log->moduleLabel(),
                'performed_by' => $log->actor?->name ?? 'Unknown user',
                'result' => $log->resultLabel(),
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->toArray();
    }
}
