<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',        // 'secretary', 'program_head', 'cm_coordinator', 'chancellor'
        'program',     
        'year_level',   // for spiritual secretaries
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // Attendance records submitted by this user (Secretary)
    public function submittedAttendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class, 'submitted_by');
    }

    // Attendance records validated by this user (Program Head/Coordinator)
    public function validatedAttendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class, 'validated_by');
    }

    // Actions logged by this user
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }
}