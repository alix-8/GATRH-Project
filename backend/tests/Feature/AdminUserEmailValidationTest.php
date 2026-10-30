<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Admin\AdminUserService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminUserEmailValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
        });
        $admin = new User(['role' => 'admin', 'email' => 'admin@laverdad.edu.ph', 'is_active' => true]);
        $admin->id = 1;
        $this->actingAs($admin);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_new_users_accept_root_and_school_subdomains_case_insensitively(): void
    {
        $addresses = [
            'staff@laverdad.edu.ph', 'student@student.laverdad.edu.ph',
            'faculty@faculty.laverdad.edu.ph', 'staff@office.department.laverdad.edu.ph',
            'Teacher+School@STUDENT.LAVERDAD.EDU.PH',
        ];
        $service = $this->mock(AdminUserService::class);
        foreach ($addresses as $email) {
            $normalized = strtolower($email);
            $user = new User(['email' => $normalized]);
            $service->shouldReceive('create')->once()->withArgs(fn ($data) => $data['email'] === $normalized)
                ->andReturn(['user' => $user, 'temporary_password_email_sent' => true]);
            $service->shouldReceive('presentUser')->once()->with($user)->andReturn(['email' => $normalized]);
        }
        foreach ($addresses as $email) {
            $this->postJson('/api/admin/users', ['name' => 'New User', 'email' => $email, 'role' => 'chancellor'])
                ->assertCreated()->assertJsonPath('user.email', strtolower($email));
        }
    }

    public function test_new_users_reject_external_domains_and_school_lookalikes_before_creation(): void
    {
        $this->mock(AdminUserService::class)->shouldNotReceive('create');
        foreach ([
            'user@gmail.com', 'user@evil-laverdad.edu.ph', 'user@notlaverdad.edu.ph',
            'user@laverdad.edu.ph.example.com', 'user@student.laverdad.edu.ph.evil.com',
            'user@laverdad-edu.ph', 'user@student..laverdad.edu.ph', 'user@-staff.laverdad.edu.ph',
        ] as $email) {
            $this->postJson('/api/admin/users', ['name' => 'New User', 'email' => $email, 'role' => 'chancellor'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
    }
}
