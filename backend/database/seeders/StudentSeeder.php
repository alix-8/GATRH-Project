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
                'name' => 'Teves, Diana ',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Estrella, Melissa ',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Gabaleo, Rimer ',
                'program' => 'BSIS',
                'year_level' => 3
            ],
            [
                'name' => 'Gultiano, Winston ',
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
