<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Student;

class Escalation extends Model
{
    use HasFactory;

    protected $fillable = [
        'student_id',
        'no_response_count',
        'escalation_level',   // 'None', 'Level 1', 'Level 2', 'Level 3'
        'last_flagged_at',
    ];

    protected $casts = [
        'last_flagged_at' => 'datetime',
        'no_response_count' => 'integer',
    ];

    // Belongs to a single student
    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}