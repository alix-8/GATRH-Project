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
                'year_level' => '3',
                'contact_number' => '09333333333'
            ]
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(['email' => $userData['email']], $userData);
        }
    }
}
