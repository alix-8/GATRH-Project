<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'gathering_type',
        'day',
        'raw_text',
        'parsed_data',
        'total_detected',
        'matched_count',
        'status',
    ];

    protected $casts = [
        'day'           => 'date',
        'parsed_data'   => 'array',
        'unparsed_data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeInReportingRange($query, array $range)
    {
        return $query->whereBetween('day', $range);
    }
}
