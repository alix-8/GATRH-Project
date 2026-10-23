<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id',
        'action',
        'target_table',
        'target_id',
    ];

    // The user who executed the action
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
    
    public function scopePerformedByAdministrators($query)
    {
        return $query->whereHas('actor', fn ($actorQuery) => $actorQuery->where('role', 'admin'));
    }

    public function moduleKey(): string
    {
        if (in_array($this->action, ['Signed in', 'Changed password'], true)) {
            return 'security';
        }

        return match (true) {
            $this->target_table === 'users' => 'users',
            $this->target_table === 'students' => 'students',
            $this->target_table === 'programs' => 'programs',
            in_array($this->target_table, ['system_settings', 'gathering_schedules', 'school_year_rollovers'], true) => 'settings',
            Str::startsWith($this->target_table, 'attendance') => 'attendance',
            default => 'system',
        };
    }

    public function moduleLabel(): string
    {
        return match ($this->moduleKey()) {
            'users' => 'Users',
            'students' => 'Students',
            'programs' => 'Programs',
            'settings' => 'Settings',
            'security' => 'Security',
            'attendance' => 'Attendance',
            default => 'System',
        };
    }

    public function resultLabel(): string
    {
        // The audit_logs table currently stores successful actions only.
        return 'Success';
    }

}
