<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Sharene Labung',
                'email' => 'chancellor@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'chancellor',
                'program' => null,
                'year_level' => null,
                'contact_number' => '09000000000'
            ],
            [
                'name' => 'Rommel Alba',
                'email' => 'cm.coordinator@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'cm_coordinator',
                'program' => null,
                'year_level' => null,
                'contact_number' => '09123456789'
            ],
            [
                'name' => 'Jerreck Navalta',
                'email' => 'program.head@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'program_head',
                'program' => 'BSIS',
                'year_level' => null,
                'contact_number' => '09111111111'
            ],
            [
                'name' => 'Mel Magdaraog',
                'email' => 'sec.bsis3@student.laverdad.edu.ph',
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
                'name' => 'Maria Rosario',
                'email' => 'sec.bsis2@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => "BSIS",
                'year_level' => "2",
                'contact_number' => '09555555555'
            ],
            [
                'name' => 'Juan Dela Cruz',
                'email' => 'sec.bsis4@laverdad.edu.ph',
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
                'email' => 'sec.bsa3@student.laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => 'BSA',
                'year_level' => '1',
                'contact_number' => '09888888888'
            ],
        ];

        foreach ($users as $userData) {
            // User::updateOrCreate(['email' => $userData['email']], $userData);
            User::firstOrCreate(['email' => $userData['email']], $userData);
        }
    }
}
