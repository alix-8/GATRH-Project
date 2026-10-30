<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    use HasFactory;

    public const DEFAULT_SYSTEM_NAME = 'Gathering Attendance Tracking and Reporting Hub';

    protected $fillable = [
        'system_name',
        'institution_name',
        'academic_year',
        'timezone',
        'date_format',
        'updated_by',
    ];
}
