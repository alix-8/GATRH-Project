<?php

namespace App\Observers;

use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Model;

class AdminActivityObserver
{
    public function __construct(private AuditLogService $auditLogService)
    {
    }

    public function created(Model $model): void
    {
        $this->recordActivity('Added', $model);
    }

    public function updated(Model $model): void
    {
        if ($model instanceof User && $model->wasChanged('password')) {
            $this->auditLogService->record('Changed password', $model);

            if (array_diff(array_keys($model->getChanges()), ['password', 'updated_at']) === []) {
                return;
            }
        }

        $this->recordActivity('Updated', $model);
    }

    public function deleted(Model $model): void
    {
        $this->recordActivity('Removed', $model);
    }

    private function recordActivity(string $action, Model $model): void
    {
        $subject = match (true) {
            $model instanceof User => 'user account',
            $model instanceof Student => 'student',
            $model instanceof Program => 'program',
            default => 'record',
        };

        $this->auditLogService->record("{$action} {$subject}", $model);
    }
}
