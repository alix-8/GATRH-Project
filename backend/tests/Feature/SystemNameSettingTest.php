<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SystemNameSettingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('system_name');
            $table->string('institution_name');
            $table->string('academic_year');
            $table->string('timezone');
            $table->string('date_format');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
        SystemSetting::create([
            'system_name' => SystemSetting::DEFAULT_SYSTEM_NAME,
            'institution_name' => 'La Verdad Christian College',
            'academic_year' => '2026-2027',
            'timezone' => 'Asia/Manila',
            'date_format' => 'MM/DD/YYYY',
        ]);
        $this->mock(AuditLogService::class)->shouldReceive('recordAction')->zeroOrMoreTimes();
    }

    public function test_branding_is_public_and_exposes_only_the_system_name(): void
    {
        $this->getJson('/api/branding')->assertOk()->assertExactJson([
            'system_name' => SystemSetting::DEFAULT_SYSTEM_NAME,
        ]);
        $this->patchJson('/api/admin/settings/general', ['system_name' => 'Changed'])->assertUnauthorized();
    }

    public function test_admin_can_save_normalized_name_without_changing_date_settings(): void
    {
        $admin = new User(['name' => 'Admin', 'role' => 'admin', 'is_active' => true]);
        $admin->id = 1;
        $this->actingAs($admin);
        $this->patchJson('/api/admin/settings/general', [
            'system_name' => '  Gathering   Hub  ',
            'academic_year' => '2026-2027',
            'date_format' => 'YYYY-MM-DD',
        ])->assertOk()->assertJsonPath('settings.system_name', 'Gathering Hub');
        $this->assertSame('MM/DD/YYYY', SystemSetting::find(1)->date_format);
        $this->assertSame('Asia/Manila', SystemSetting::find(1)->timezone);
        $this->getJson('/api/branding')->assertExactJson(['system_name' => 'Gathering Hub']);
        foreach (['   ', str_repeat('x', 121)] as $invalid) {
            $this->patchJson('/api/admin/settings/general', ['system_name' => $invalid])
                ->assertUnprocessable()->assertJsonValidationErrors('system_name');
        }
    }
}
