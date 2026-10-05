<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Program;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            // Chancellor
            [
                'name' => 'Sharene Labung',
                'email' => 'chancellor@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'chancellor',
                'program' => null,
                'year_level' => null,
                'contact_number' => '09000000000'
            ],

            // CM Coordinator
            [
                'name' => 'Rommel Alba',
                'email' => 'cm.coordinator@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'cm_coordinator',
                'program' => null,
                'year_level' => null,
                'contact_number' => '09123456789'
            ],

            // Admin
            [
                'name' => 'System Admin Test',
                'email' => 'admin@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'admin',
                'program' => null,
                'year_level' => null,
                'contact_number' => '09123456781'
            ],

            //Program Heads
            [
                'name' => 'Jerreck Navalta',
                'email' => 'bsis.head@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'program_head',
                'program' => 'BSIS',
                'programs' => ['BSIS', 'ACT'],
                'year_level' => null,
                'contact_number' => '09111111111'
            ],
            [
                'name' => 'Pedro Santos',
                'email' => 'bssw.head@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'program_head',
                'program' => 'BSSW',
                'programs' => ['BSSW'],
                'year_level' => null,
                'contact_number' => '09111111222'
            ],

            
            // Program Secretaries
            [
                'name' => 'Jamela Fernandez',
                'email' => 'program.secretary@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'program_secretary',
                'program' => 'BSIS',
                'programs' => ['BSIS'],
                'year_level' => null,
                'contact_number' => '09123456780'
            ],

            // Secretaries
            [
                'name' => 'Mel Magdaraog',
                'email' => 'sec.bsis3@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => 'BSIS',
                'year_level' => '3',
                'contact_number' => '09222222222'
            ],
            [
                'name' => 'Alexandrian Bon',
                'email' => 'alexandrian.bon@student.laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => 'BSIS',
                'year_level' => '1',
                'contact_number' => '09444444444'
            ],
            [
                'name' => 'Winston Gultiano',
                'email' => 'winston.gultiano@student.laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => "BSIS",
                'year_level' => "2",
                'contact_number' => '09555555555'
            ],
            [
                'name' => 'Melissa Estrella',
                'email' => 'melissa.estrella@student.laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => "BSIS",
                'year_level' => "4",
                'contact_number' => '09666666666'
            ],
            [
                'name' => 'John Doe',
                'email' => 'sec.bssw1@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => 'BSSW',
                'year_level' => '1',
                'contact_number' => '09777777777'
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'sec.bsa3@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => 'BSA',
                'year_level' => '1',
                'contact_number' => '09888888888'
            ],
        ];

        foreach ($users as $userData) {
            $programs = $userData['programs'] ?? (isset($userData['program']) ? [$userData['program']] : []);
            unset($userData['programs']);

            $user = User::firstOrCreate(['email' => $userData['email']], $userData);

            foreach ($programs as $programCode) {
                $program = Program::firstOrCreate([
                    'code' => strtoupper(trim($programCode)),
                ]);

                $user->programs()->syncWithoutDetaching($program->id);
            }
        }
    }
}
