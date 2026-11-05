<?php

namespace App\Observers;

use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;

class AdminActivityObserver
{
    private const TRACKED_ATTRIBUTES = [
        User::class => ['name', 'email', 'role', 'program', 'year_level', 'is_active'],
        Student::class => ['name', 'program', 'year_level', 'is_active', 'inactive_reason'],
        Program::class => ['code', 'name', 'is_active', 'max_year_level'],
    ];

    public function __construct(private AuditLogService $auditLogService)
    {
    }

    public function created(Model $model): void
    {
        $this->recordActivity('Added', $model);
    }

    public function updated(Model $model): void
    {
        $this->recordActivity('Updated', $model, $this->changedAttributes($model));
    }

    public function deleted(Model $model): void
    {
        $this->recordActivity('Removed', $model);
    }

    private function recordActivity(string $action, Model $model, array $changes = []): void
    {
        if ($action === 'Updated' && $changes === []) {
            return;
        }

        $subject = match (true) {
            $model instanceof User => 'user account',
            $model instanceof Student => 'student',
            $model instanceof Program => 'program',
            default => 'record',
        };

        $details = json_encode([
            'audit_snapshot' => true,
            'entity' => $this->entity($model),
            'snapshot' => $this->snapshot($model),
            'changes' => $changes,
        ]);

        $this->auditLogService->record("{$action} {$subject}", $model, details: $details ?: null);
    }

    private function entity(Model $model): string
    {
        return match (true) {
            $model instanceof User => 'user',
            $model instanceof Student => 'student',
            $model instanceof Program => 'program',
            default => 'record',
        };
    }

    private function trackedAttributes(Model $model): array
    {
        return self::TRACKED_ATTRIBUTES[$model::class] ?? [];
    }

    private function snapshot(Model $model): array
    {
        return collect($this->trackedAttributes($model))
            ->mapWithKeys(fn (string $attribute) => [$attribute => $model->getAttribute($attribute)])
            ->all();
    }

    private function changedAttributes(Model $model): array
    {
        $changes = [];
        foreach ($this->trackedAttributes($model) as $attribute) {
            if (! $model->wasChanged($attribute)) {
                continue;
            }

            $changes[$attribute] = [
                'from' => $model->getOriginal($attribute),
                'to' => $model->getAttribute($attribute),
            ];
        }

        return $changes;
    }
}
