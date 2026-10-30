<?php

namespace Tests\Feature;

use App\Models\{Student, User};
use App\Services\ProgramHead\ProgramHeadAttendanceWorkbookService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\TestCase;

class AdminStudentSplitNameImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(\App\Services\Audit\AuditLogService::class)->shouldReceive('record', 'recordAction')->zeroOrMoreTimes();
        Schema::create('programs', function (Blueprint $t): void { $t->id(); $t->string('code'); });
        Schema::create('students', function (Blueprint $t): void {
            $t->id(); $t->string('name'); $t->string('program'); $t->string('year_level');
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        DB::table('programs')->insert(['code' => 'BSIS']);
        $admin = new User(['role' => 'admin', 'is_active' => true]);
        $admin->id = 1;
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('students'); Schema::dropIfExists('programs');
        parent::tearDown();
    }

    private function importCsv(string $csv, array $options = [])
    {
        return $this->postJson('/api/admin/students/import', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', $csv), ...$options,
        ]);
    }

    public function test_csv_split_names_are_formatted_and_existing_full_name_files_still_work(): void
    {
        $this->importCsv("lname,fname,program,year_level\nDela Cruz,Juan Miguel,BSIS,2\nSantos,Maria,BSIS,2\n")
            ->assertCreated()->assertJsonPath('count', 2);
        $this->assertDatabaseHas('students', ['name' => 'Dela Cruz, Juan Miguel', 'program' => 'BSIS', 'year_level' => 2]);
        $this->importCsv("Full Name,program,year_level\n\"Reyes, Ana\",BSIS,1\n")->assertCreated();
        $this->assertDatabaseHas('students', ['name' => 'Reyes, Ana']);
    }

    public function test_reordered_case_insensitive_aliases_and_selected_scope_are_supported(): void
    {
        $this->importCsv("FIRST NAME,LAST NAME\n  Juan   Miguel  ,  Dela   Cruz  \n", ['program' => 'BSIS', 'year_level' => '4'])
            ->assertCreated();
        $this->assertDatabaseHas('students', ['name' => 'Dela Cruz, Juan Miguel', 'year_level' => '4']);
        $this->importCsv("surname,given_name\nO'Neil-Santos,José\n", ['program' => 'BSIS', 'year_level' => '1'])
            ->assertCreated();
        $this->assertDatabaseHas('students', ['name' => "O'Neil-Santos, José"]);
    }

    public function test_incomplete_split_rows_reject_the_whole_file_and_identify_the_row(): void
    {
        $this->importCsv("lname,fname,program,year_level\nCruz,Juan,BSIS,1\nSantos,,BSIS,1\n")
            ->assertUnprocessable()->assertJsonValidationErrors('file')
            ->assertJsonPath('errors.file.0', 'Missing first or last name on row 3. Provide both lname and fname, or a Full Name.');
        $this->assertDatabaseCount('students', 0);
        $this->importCsv("lname,program,year_level\nCruz,BSIS,1\n")
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_duplicate_detection_is_preserved_for_split_names(): void
    {
        Student::create(['name' => 'Dela Cruz, Juan', 'program' => 'BSIS', 'year_level' => '1']);
        $this->importCsv("lname,fname,program,year_level\nDela Cruz,Juan,BSIS,1\n")
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->importCsv("lname,fname,program,year_level\nSantos,Maria,BSIS,1\nSantos,Maria,BSIS,1\n")
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('students', 1);
    }

    public function test_files_can_mix_existing_full_names_and_split_name_rows(): void
    {
        $this->importCsv("Full Name,lname,fname,program,year_level\n\"Reyes, Ana\",,,BSIS,1\n,Santos,Maria,BSIS,1\n,,,,\n")
            ->assertCreated()->assertJsonPath('count', 2);
        $this->assertDatabaseHas('students', ['name' => 'Reyes, Ana']);
        $this->assertDatabaseHas('students', ['name' => 'Santos, Maria']);
    }

    public function test_xlsx_split_names_and_program_year_preview_are_supported(): void
    {
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ([['lname', 'fname', 'program', 'year_level'], ['Ariate', 'Angelene', 'BSIS', '4']] as $index => $row) {
            $number = $index + 1;
            $xml .= '<row r="'.$number.'">';
            foreach ($row as $column => $value) {
                $xml .= '<c r="'.chr(65 + $column).$number.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        // Reuse the independently tested ZIP packer to supply a real .xlsx worksheet to the import reader.
        $archive = (new \ReflectionMethod(ProgramHeadAttendanceWorkbookService::class, 'zipFiles'))
            ->invoke(new ProgramHeadAttendanceWorkbookService(), ['xl/worksheets/sheet1.xml' => $xml]);
        $file = UploadedFile::fake()->createWithContent('students.xlsx', $archive);
        $this->postJson('/api/admin/students/import/preview', ['file' => $file])
            ->assertOk()->assertJsonPath('program', 'BSIS')->assertJsonPath('year_level', 4);
        $this->postJson('/api/admin/students/import', ['file' => $file])->assertCreated();
        $this->assertDatabaseHas('students', ['name' => 'Ariate, Angelene', 'year_level' => '4']);
    }
}
