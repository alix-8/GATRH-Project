<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class);
    }

    public function programHeads()
    {
        return $this->belongsToMany(User::class)
            ->where('role', 'program_head');
    }

    public function students()
    {
        return $this->hasMany(Student::class, 'program', 'code');
    }

}
