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

        $activityLogs->getCollection()->transform(function (AuditLog $log) use ($targets): array {
            $auditDetails = $this->auditDetails($log->details);

            return [
                'id' => $log->id,
                'action' => $log->action,
                'target_table' => $log->target_table,
                'target_id' => $log->target_id,
                'module' => $log->moduleLabel(),
                'module_key' => $log->moduleKey(),
                'affected_record' => $this->affectedRecord($log, $targets, $auditDetails),
                'performed_by' => $log->actor?->name ?? 'Unknown user',
                'performed_by_email' => $log->actor?->email,
                'result' => $log->resultLabel(),
                'details' => $auditDetails ? null : $log->details,
                'snapshot' => $this->presentSnapshot($auditDetails),
                'changes' => $this->presentChanges($auditDetails),
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        });

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

    private function affectedRecord(AuditLog $log, array $targets, ?array $auditDetails = null): string
    {
        $record = $targets[$log->target_table][$log->target_id] ?? null;

        if ($record instanceof User) return $record->name . ' · ' . Str::headline($record->role);
        if ($record instanceof Student) return $record->name . ' · ' . $record->program . ' · ' . Str::headline((string) $record->year_level);
        if ($record instanceof Program) return $record->code . ' · ' . $record->name;

        $snapshot = $auditDetails['snapshot'] ?? [];
        $entity = $auditDetails['entity'] ?? null;
        if ($entity === 'user') {
            return ($snapshot['name'] ?? 'User account') . ' · ' . Str::headline((string) ($snapshot['role'] ?? ''));
        }
        if ($entity === 'student') {
            return ($snapshot['name'] ?? 'Student') . ' · ' . ($snapshot['program'] ?? '—') . ' · ' . $this->yearLabel($snapshot['year_level'] ?? null);
        }
        if ($entity === 'program') {
            return ($snapshot['code'] ?? 'Program') . ' · ' . ($snapshot['name'] ?? '—');
        }

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

    private function auditDetails(?string $details): ?array
    {
        if (! $details) {
            return null;
        }

        $decoded = json_decode($details, true);
        if (! is_array($decoded) || ($decoded['audit_snapshot'] ?? false) !== true) {
            return null;
        }

        return $decoded;
    }

    private function presentSnapshot(?array $auditDetails): array
    {
        if (! $auditDetails) {
            return [];
        }

        $snapshot = $auditDetails['snapshot'] ?? [];
        return collect($this->attributesForEntity($auditDetails['entity'] ?? ''))
            ->filter(fn (string $attribute) => array_key_exists($attribute, $snapshot))
            ->map(fn (string $attribute) => [
                'label' => $this->attributeLabel($attribute),
                'value' => $this->displayValue($attribute, $snapshot[$attribute]),
            ])
            ->values()
            ->all();
    }

    private function presentChanges(?array $auditDetails): array
    {
        if (! $auditDetails) {
            return [];
        }

        return collect($auditDetails['changes'] ?? [])
            ->map(fn (array $change, string $attribute) => [
                'label' => $this->attributeLabel($attribute),
                'from' => $this->displayValue($attribute, $change['from'] ?? null),
                'to' => $this->displayValue($attribute, $change['to'] ?? null),
            ])
            ->values()
            ->all();
    }

    private function attributesForEntity(string $entity): array
    {
        return match ($entity) {
            'user' => ['name', 'email', 'role', 'program', 'year_level', 'is_active'],
            'student' => ['name', 'program', 'year_level', 'is_active', 'inactive_reason'],
            'program' => ['code', 'name', 'is_active', 'max_year_level'],
            default => [],
        };
    }

    private function attributeLabel(string $attribute): string
    {
        return match ($attribute) {
            'is_active' => 'Status',
            'year_level' => 'Year level',
            'max_year_level' => 'Maximum year level',
            'inactive_reason' => 'Inactive reason',
            default => Str::headline($attribute),
        };
    }

    private function displayValue(string $attribute, mixed $value): string
    {
        if ($attribute === 'is_active') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Active' : 'Inactive';
        }
        if (in_array($attribute, ['year_level', 'max_year_level'], true)) {
            return $value === null || $value === '' ? 'Not assigned' : "Year {$value}";
        }
        if ($attribute === 'role') {
            return $value ? Str::headline((string) $value) : 'Not assigned';
        }
        if ($attribute === 'inactive_reason') {
            return $value ? Str::headline((string) $value) : '—';
        }

        return $value === null || $value === '' ? 'Not assigned' : (string) $value;
    }

    private function yearLabel(mixed $yearLevel): string
    {
        return $yearLevel === null || $yearLevel === '' ? 'Not assigned' : "Year {$yearLevel}";
    }
}
