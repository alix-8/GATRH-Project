<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogFailedAdminActivity
{
    public function __construct(private AuditLogService $auditLogService)
    {
    }

    public function handle(Request $request, \Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();
        $actor = $request->user();

        if ($status < 400 || ! $actor || $actor->role !== 'admin') {
            return $response;
        }

        [$action, $targetTable, $targetId] = $this->describeRequest($request);
        $payload = json_decode((string) $response->getContent(), true);
        $reason = is_array($payload) && is_string($payload['message'] ?? null)
            ? $payload['message']
            : Response::$statusTexts[$status] ?? 'The request failed.';
        $details = Str::limit("HTTP {$status}: " . trim(strip_tags($reason)), 500, '…');

        try {
            $this->auditLogService->recordFailure($action, $targetTable, $targetId, $details, $actor);
        } catch (Throwable) {
            // Preserve the original API error if audit storage is unavailable.
        }

        return $response;
    }

    /** @return array{string, string, string} */
    private function describeRequest(Request $request): array
    {
        $segments = $request->segments();
        if (($segments[0] ?? null) === 'api') {
            array_shift($segments);
        }
        $resource = $segments[1] ?? 'admin';
        $method = strtoupper($request->method());
        $targetTable = match ($resource) {
            'users' => 'users',
            'students' => 'students',
            'programs' => 'programs',
            'settings' => 'system_settings',
            default => 'admin',
        };
        if (str_contains($request->path(), 'school-year-rollover')) {
            $targetTable = 'school_year_rollovers';
        }
        $entity = match ($resource) {
            'users' => 'user account',
            'students' => 'student',
            'programs' => 'program',
            default => str_replace('-', ' ', $resource),
        };

        $action = match (true) {
            str_contains($request->path(), 'school-year-rollover') => str_contains($request->path(), '/undo')
                ? 'Undo academic year rollover'
                : ($method === 'POST' && str_ends_with($request->path(), '/preview')
                    ? 'Preview academic year rollover'
                    : 'Run academic year rollover'),
            $resource === 'students' && ($segments[2] ?? null) === 'import' => 'Import student records',
            $resource === 'settings' => 'Update system settings',
            $resource === 'activity-log' => 'View activity history',
            $resource === 'dashboard' => 'View admin dashboard',
            default => match ($method) {
                'POST' => 'Create ' . $entity,
                'PATCH', 'PUT' => 'Update ' . $entity,
                'DELETE' => 'Deactivate ' . $entity,
                default => 'Access admin ' . str_replace('-', ' ', $resource),
            },
        };

        $targetId = (string) ($segments[2] ?? '0');
        if (in_array($targetId, ['import', 'school-year-rollover', 'general', 'activity-log', 'dashboard', 'head-options'], true)) {
            $targetId = '0';
        }

        return [$action, $targetTable, $targetId];
    }
}
