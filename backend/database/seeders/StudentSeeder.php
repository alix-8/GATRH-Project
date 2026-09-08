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
            // BSIS - Year 1
            [
                'name' => 'Abrigo, Marielle',
                'program' => 'BSIS',
                'year_level' => 1,
            ],
            [
                'name' => 'Concepcion, Jhon Carlo',
                'program' => 'BSIS',
                'year_level' => 1,
            ],
            [
                'name' => 'Dizon, Kaye',
                'program' => 'BSIS',
                'year_level' => 1,
            ],
            [
                'name' => 'Espino, Ranier',
                'program' => 'BSIS',
                'year_level' => 1,
            ],
            [
                'name' => 'Fontanilla, Trisha',
                'program' => 'BSIS',
                'year_level' => 1,
            ],

            // BSIS - Year 2
            [
                'name' => 'Gatchalian, Miguel',
                'program' => 'BSIS',
                'year_level' => 2,
            ],
            [
                'name' => 'Hernandez, Andrea',
                'program' => 'BSIS',
                'year_level' => 2,
            ],
            [
                'name' => 'Ilagan, Christian',
                'program' => 'BSIS',
                'year_level' => 2,
            ],
            [
                'name' => 'Josol, Bianca',
                'program' => 'BSIS',
                'year_level' => 2,
            ],
            [
                'name' => 'Katigbak, Renz',
                'program' => 'BSIS',
                'year_level' => 2,
            ],
            
            //BSIS - Year 3
            [
                'name' => 'Ariate, Angelene',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Balmaceda, Jr Cachuela',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Bautista, Anthony Daniel',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Bautista, Russel Jonn',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Binos, Samuel',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Bon, Alexandrian',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Bronola, Leander Dylan',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Buen, Louise',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Calimlim, Jaden',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Calimlim, Jarelle',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Carbonel, Jess Marvin',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Casimiro, Daniel B.',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Estrella, Lychee',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Gabaleo, Rimer Arciola',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Gardoce, Perfecto',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Jarina, Jimuel',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Lava, Rocelyn',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Magdaraog, Mel',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Mandocdoc, Marwin Atienza',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Padillon, Luisa',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Ramirez, Ydrey Ann',
                'program' => 'BSIS',
                'year_level' => 3,
            ],
            [
                'name' => 'Teves, Diana Sto Tomas',
                'program' => 'BSIS',
                'year_level' => 3,
            ],

            // BSIS - Year 4
            [
                'name' => 'Lacson, Ferdinand',
                'program' => 'BSIS',
                'year_level' => 4,
            ],
            [
                'name' => 'Macalintal, Joyce',
                'program' => 'BSIS',
                'year_level' => 4,
            ],
            [
                'name' => 'Nepomuceno, Aaron',
                'program' => 'BSIS',
                'year_level' => 4,
            ],
            [
                'name' => 'Orlanda, Sheila',
                'program' => 'BSIS',
                'year_level' => 4,
            ],
            [
                'name' => 'Pineda, Justin',
                'program' => 'BSIS',
                'year_level' => 4,
            ],

            // BSSW - Year 1
            [
                'name' => 'Quiambao, Angelica',
                'program' => 'BSSW',
                'year_level' => 1,
            ],
            [
                'name' => 'Rosales, Kevin',
                'program' => 'BSSW',
                'year_level' => 1,
            ],
            [
                'name' => 'Sarmiento, Faith',
                'program' => 'BSSW',
                'year_level' => 1,
            ],
            [
                'name' => 'Tolentino, Marc',
                'program' => 'BSSW',
                'year_level' => 1,
            ],
            [
                'name' => 'Uy, Camille',
                'program' => 'BSSW',
                'year_level' => 1,
            ],

            // BSA - Year 1
            [
                'name' => 'Villaruel, Nathaniel',
                'program' => 'BSA',
                'year_level' => 1,
            ],
            [
                'name' => 'Wenceslao, Patricia',
                'program' => 'BSA',
                'year_level' => 1,
            ],
            [
                'name' => 'Ynares, Carlos',
                'program' => 'BSA',
                'year_level' => 1,
            ],
            [
                'name' => 'Zamora, Denise',
                'program' => 'BSA',
                'year_level' => 1,
            ],
            [
                'name' => 'Aquino, Marc Julian',
                'program' => 'BSA',
                'year_level' => 1,
            ],
        ];

        foreach($students as $student) {
            Student::firstOrCreate(
                [
                    'name' => $student['name'],
                    'program' => $student['program'],
                    'year_level' => $student['year_level'],
                ],
                $student
            );
        }
    }
}