<?php

namespace Database\Seeders;
    
use App\Models\Program;
use Illuminate\Database\Seeder;

class ProgramSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $programs = [
            ['code' => 'ACT', 'name' => 'Associate in Computer Technology', 'max_year_level' => 2],
            ['code' => 'BSIS', 'name' => 'Bachelor of Science in Information Systems'],
            ['code' => 'BSSW', 'name' => 'Bachelor of Science in Social Work'],
            ['code' => 'BSA', 'name' => 'Bachelor of Science in Accountancy'],
            ['code' => 'BSAIS', 'name' => 'Bachelor of Science Accountancy Information System'],
            ['code' => 'BAB', 'name' => 'Bachelor of Arts in Broadcasting'],
        ];

        foreach ($programs as $programData) {
            $program = Program::firstOrCreate(
                ['code' => $programData['code']],
                ['name' => $programData['name']]
            );

            if (blank($program->name)) {
                $program->update(['name' => $programData['name']]);
            }
        }
    }
}
