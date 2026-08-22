<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Escalation;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'program',
        'year_level',
    ];

    // A student has many attendance records over time
    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    // Each student has exactly one escalation tracking row
    public function escalation()
    {
        return $this->hasOne(Escalation::class);
    }
}
