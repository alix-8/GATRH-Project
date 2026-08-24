<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Student;

class StudentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $students = [
            [
                'name' => 'Diana Teves',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Melissa Estrella',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Rimer Gabaleo',
                'program' => 'BSIS',
                'year_level' => 3
            ],
            [
                'name' => 'Winston Gultiano',
                'program' => 'BSIS',
                'year_level' => 3
            ]
        ];

        foreach($students as $student) {
            Student::updateOrCreate(
                ['name' => $student['name']],
                $student
            );
        }
    }
}
