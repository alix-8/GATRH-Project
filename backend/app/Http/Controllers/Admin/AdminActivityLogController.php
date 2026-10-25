<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date' => ['sometimes', 'nullable', 'in:today,7days,30days'],
            'module' => ['sometimes', 'nullable', 'in:users,students,programs,settings,security,attendance,system'],
            'result' => ['sometimes', 'nullable', 'in:success,failed'],
        ]);

        $administratorActivity = AuditLog::query()
            ->whereHas('actor', fn (Builder $actor) => $actor->where('role', 'admin'));
        $today = today();
        $summary = [
            'total' => (clone $administratorActivity)->count(),
            'today_date' => $today->toDateString(),
            'today' => (clone $administratorActivity)
                ->whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
                ->count(),
            'user_role_changes' => (clone $administratorActivity)
                ->where('target_table', 'users')
                ->whereNotIn('action', ['Signed in', 'Changed password'])
                ->count(),
            'security_events' => (clone $administratorActivity)
                ->whereIn('action', ['Signed in', 'Changed password'])
                ->count(),
        ];

        $activityLogs = (clone $administratorActivity)
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $this->search($query, trim($search)))
            ->when($filters['date'] ?? null, function (Builder $query, string $date) use ($today): void {
                $from = match ($date) {
                    'today' => $today->copy()->startOfDay(),
                    '7days' => $today->copy()->subDays(6)->startOfDay(),
                    '30days' => $today->copy()->subDays(29)->startOfDay(),
                };
                $query->where('created_at', '>=', $from);
            })
            ->when($filters['module'] ?? null, fn (Builder $query, string $module) => $this->filterModule($query, $module))
            ->when($filters['result'] ?? null, fn (Builder $query, string $result) => $query->where('result', $result))
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(7);

        $targetIds = $activityLogs->getCollection()->groupBy('target_table');
        $targets = [
            'users' => User::query()->whereIn('id', $targetIds->get('users', collect())->pluck('target_id'))
                ->get(['id', 'name', 'role'])->keyBy('id'),
            'students' => Student::query()->whereIn('id', $targetIds->get('students', collect())->pluck('target_id'))
                ->get(['id', 'name', 'program', 'year_level'])->keyBy('id'),
            'programs' => Program::query()->whereIn('id', $targetIds->get('programs', collect())->pluck('target_id'))
                ->get(['id', 'code', 'name'])->keyBy('id'),
        ];

        $activityLogs->getCollection()->transform(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'target_table' => $log->target_table,
            'target_id' => $log->target_id,
            'module' => $log->moduleLabel(),
            'module_key' => $log->moduleKey(),
            'affected_record' => $this->affectedRecord($log, $targets),
            'performed_by' => $log->actor?->name ?? 'Unknown user',
            'performed_by_email' => $log->actor?->email,
            'result' => $log->resultLabel(),
            'details' => $log->details,
            'created_at' => $log->created_at?->toIso8601String(),
        ]);

        return response()->json(['summary' => $summary, 'activity_log' => $activityLogs]);
    }

    private function search(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $like = "%{$search}%";
        $query->where(function (Builder $matches) use ($like): void {
            $matches->where('action', 'like', $like)
                ->orWhere('target_id', 'like', $like)
                ->orWhere('target_table', 'like', $like)
                ->orWhereHas('actor', fn (Builder $actor) => $actor
                    ->where('name', 'like', $like)->orWhere('email', 'like', $like))
                ->orWhere(fn (Builder $record) => $record->where('target_table', 'users')
                    ->whereIn('target_id', User::query()->select('id')->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)->orWhere('role', 'like', $like)))
                ->orWhere(fn (Builder $record) => $record->where('target_table', 'students')
                    ->whereIn('target_id', Student::query()->select('id')->where('name', 'like', $like)
                        ->orWhere('program', 'like', $like)))
                ->orWhere(fn (Builder $record) => $record->where('target_table', 'programs')
                    ->whereIn('target_id', Program::query()->select('id')->where('code', 'like', $like)
                        ->orWhere('name', 'like', $like)));
        });
    }

    private function filterModule(Builder $query, string $module): void
    {
        match ($module) {
            'security' => $query->whereIn('action', ['Signed in', 'Changed password']),
            'users' => $query->where('target_table', 'users')->whereNotIn('action', ['Signed in', 'Changed password']),
            'students' => $query->where('target_table', 'students'),
            'programs' => $query->where('target_table', 'programs'),
            'settings' => $query->whereIn('target_table', ['system_settings', 'gathering_schedules', 'school_year_rollovers']),
            'attendance' => $query->where('target_table', 'like', 'attendance%'),
            'system' => $query->whereNotIn('target_table', [
                'users', 'students', 'programs', 'system_settings', 'gathering_schedules', 'school_year_rollovers',
            ])->where('target_table', 'not like', 'attendance%'),
        };
    }

    private function affectedRecord(AuditLog $log, array $targets): string
    {
        $record = $targets[$log->target_table][$log->target_id] ?? null;

        if ($record instanceof User) return $record->name . ' · ' . Str::headline($record->role);
        if ($record instanceof Student) return $record->name . ' · ' . $record->program . ' · ' . Str::headline((string) $record->year_level);
        if ($record instanceof Program) return $record->code . ' · ' . $record->name;

        return match ($log->target_table) {
            'users' => "User account #{$log->target_id}",
            'students' => "Student #{$log->target_id}",
            'programs' => "Program #{$log->target_id}",
            'system_settings' => 'System settings',
            'gathering_schedules' => 'Gathering schedules',
            'school_year_rollovers' => "School-year rollover #{$log->target_id}",
            default => Str::headline($log->target_table) . " #{$log->target_id}",
        };
    }
}
