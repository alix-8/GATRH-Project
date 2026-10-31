<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogService
{
    public function recordSecurityEvent(string $action, User $actor, string $result = 'success', ?string $details = null): void
    {
        if ($actor->role !== 'admin') {
            return;
        }

        try {
            $this->recordAction($action, 'users', (string) $actor->id, $actor, $result, $details);
        } catch (\Throwable $exception) {
            // Audit-storage failures must not change the authentication response.
            report($exception);
        }
    }

    public function record(string $action, Model $subject, ?User $actor = null): void
    {
        $this->recordAction($action, $subject->getTable(), (string) $subject->getKey(), $actor);
    }

    public function recordAction(
        string $action,
        string $targetTable,
        string $targetId,
        ?User $actor = null,
        string $result = 'success',
        ?string $details = null
    ): void {
        $actor ??= Auth::user();

        // Seeders and system jobs may change models without a signed-in user.
        if (! $actor) {
            return;
        }

        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'target_table' => $targetTable,
            'target_id' => $targetId,
            'result' => $result,
            'details' => $details,
        ]);
    }

    public function recordFailure(
        string $action,
        string $targetTable,
        string $targetId,
        string $details,
        ?User $actor = null
    ): void {
        $this->recordAction($action, $targetTable, $targetId, $actor, 'failed', $details);
    }
}
