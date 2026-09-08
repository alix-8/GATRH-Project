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
                'email' => 'sharenelabung@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'chancellor',
                'program' => null,
                'year_level' => null,
                'contact_number' => '09205202527'
            ],
            [
                'name' => 'Rommel Alba',
                'email' => 'rommelalba@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'cm_coordinator',
                'program' => null,
                'year_level' => null,
                'contact_number' => '09205542527'
            ],
            [
                'name' => 'Jerreck Navalta',
                'email' => 'jerreckreynaldnavalta@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'program_head',
                'program' => 'BSIS',
                'year_level' => null,
                'contact_number' => '09123456789'
            ],
            [
                'name' => 'Mel Magdaraog',
                'email' => 'mel.magdaraog@student.laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => 'BSIS',
                'year_level' => '3',
                'contact_number' => '09205212345'
            ],
            [
                'name' => 'Alexandrian Bon',
                'email' => 'alexandrian.bon@student.laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => 'BSIS',
                'year_level' => '3',
                'contact_number' => '096307303968'
            ]
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(['email' => $userData['email']], $userData);
        }
    }
}
