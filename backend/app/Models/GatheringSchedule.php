<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GatheringSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'gathering_type',
        'days',
    ];

    protected $casts = [
        'days' => 'array',
    ];
}
