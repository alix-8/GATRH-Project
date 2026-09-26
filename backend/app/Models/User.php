<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',        // 'secretary', 'program_head', 'cm_coordinator', 'chancellor', 'admin'
        'program',
        'year_level',
        'contact_number',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // ==========================================
    // ROLE DETERMINATION FUNCTIONS (to be used for access control)
    // ==========================================

    /**
     * Check if user has a specific role :D
     * Usage: $user->hasRole(['secretary', 'program_head'])
     */
    public function hasRole(string|array $roles): bool
    {
        if (is_array($roles)) {
            return in_array($this->role, $roles);
        }

        return $this->role === $roles;
    }

    /* individual role check helpers*/
    public function isSecretary(): bool
    {
        return $this->role === 'secretary';
    }

    public function isProgramHead(): bool
    {
        return $this->role === 'program_head';
    }

    public function isCmCoordinator(): bool
    {
        return $this->role === 'cm_coordinator';
    }

    public function isChancellor(): bool
    {
        return $this->role === 'chancellor';
    }

    public function programs()
    {
        return $this->belongsToMany(Program::class);
    }

    public function assignedProgramCodes(): array
    {
        $codes = $this->programs()->pluck('code')->all();

        // Keep legacy Program Head accounts scoped to their primary program
        // when their program_user pivot has not been populated yet.
        if ($codes === [] && filled($this->program)) {
            $codes[] = $this->program;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($code) => strtoupper(trim((string) $code)),
            $codes
        ))));
    }

    /**
     * Check if the user can manage a specific student's record.
     */
    public function canManageStudent(Student $student): bool
    {
        if (in_array($this->role, ['chancellor', 'cm_coordinator'])) {
            return true;
        }

        if ($this->role === 'program_head') {
            return in_array($student->program, $this->assignedProgramCodes(), true);
        }

        if ($this->role === 'secretary') {
            return $this->program === $student->program 
                && (string)$this->year_level === (string)$student->year_level;
        }

        return false;
    }

    // ==========================================
    // RELATIONSHIPS
    // ==========================================

    public function attendanceDrafts()
    {
        return $this->hasMany(AttendanceDraft::class);
    }

    public function submittedAttendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class, 'submitted_by');
    }

    public function validatedAttendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class, 'validated_by');
    }

    public function reminders()
    {
        return $this->hasMany(Reminder::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }
}
