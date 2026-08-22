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
            ],
            [
                'name' => 'Rommel Alba',
                'email' => 'cm.coordinator@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'cm_coordinator',
                'program' => null,
                'year_level' => null,
            ],
            [
                'name' => 'Jerreck Navalta',
                'email' => 'head.bsis@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'program_head',
                'program' => 'BSIS',
                'year_level' => null,
            ],
            [
                'name' => 'Mel Magdaraog',
                'email' => 'sec.bsis3@laverdad.edu.ph',
                'password' => Hash::make('Password123!'),
                'role' => 'secretary',
                'program' => 'BSIS',
                'year_level' => '3',
            ],
        ];

        foreach ($users as $userData) {
            User::updateOrCreate(['email' => $userData['email']], $userData);
        }
    }
}
