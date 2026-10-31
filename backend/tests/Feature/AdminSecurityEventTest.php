<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSecurityEventTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeaders(['Origin' => 'http://localhost']);
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id');
            $table->string('action');
            $table->string('target_table');
            $table->string('target_id');
            $table->string('result');
            $table->text('details')->nullable();
            $table->timestamps();
        });
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at');
        });
    }

    private function admin(): User
    {
        return User::create(['name' => 'Admin', 'email' => 'admin@laverdad.edu.ph',
            'password' => 'CurrentPass1!', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_login_records_success_and_failed_admin_attempts(): void
    {
        $user = $this->admin();
        $this->withSession([])->postJson('/api/login', ['email' => $user->email, 'password' => 'WrongPass1!'])->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['action' => 'Signed in', 'result' => 'failed', 'details' => 'Sign-in attempt failed: invalid credentials.']);
        $this->withSession([])->postJson('/api/login', ['email' => $user->email, 'password' => 'CurrentPass1!'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'Signed in', 'result' => 'success', 'details' => null]);
        $this->assertDatabaseCount('audit_logs', 2);
        User::withoutEvents(fn () => $user->update(['is_active' => false]));
        $this->withSession([])->postJson('/api/login', ['email' => $user->email, 'password' => 'CurrentPass1!'])->assertForbidden();
        $this->assertDatabaseCount('audit_logs', 3);
        $this->assertDatabaseHas('audit_logs', ['result' => 'failed', 'details' => 'Sign-in attempt blocked: inactive account.']);
    }

    public function test_password_change_logs_success_without_password_data(): void
    {
        $user = $this->admin();
        $this->actingAs($user)->postJson('/api/change-password', ['current_password' => 'WrongPass1!',
            'password' => 'UpdatedPass2!', 'password_confirmation' => 'UpdatedPass2!'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'Changed password', 'result' => 'failed', 'details' => 'Password change failed: incorrect current password.']);
        $this->postJson('/api/change-password', ['current_password' => 'CurrentPass1!',
            'password' => 'UpdatedPass2!', 'password_confirmation' => 'UpdatedPass2!'])->assertOk();
        $this->assertTrue(Hash::check('UpdatedPass2!', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'Changed password', 'target_id' => (string) $user->id, 'details' => null]);
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'Changed password')->count());
    }

    public function test_verified_password_reset_is_logged_and_non_admin_events_are_excluded(): void
    {
        $user = $this->admin();
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => Hash::make('123456'), 'created_at' => now()]);
        $this->postJson('/api/reset-password', ['email' => $user->email, 'code' => '123456',
            'password' => 'UpdatedPass2!', 'password_confirmation' => 'UpdatedPass2!'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'Changed password']);
        $this->assertTrue(Hash::check('UpdatedPass2!', $user->fresh()->password));
        $user->role = 'secretary';
        app(AuditLogService::class)->recordSecurityEvent('Signed in', $user);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_invalid_reset_code_and_password_validation_are_logged_without_secrets(): void
    {
        $user = $this->admin();
        $this->postJson('/api/verify-reset-code', ['email' => $user->email, 'code' => '654321'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['result' => 'failed',
            'details' => 'Password reset code verification failed: incorrect or expired code.']);
        $this->postJson('/api/reset-password', ['email' => $user->email, 'code' => '654321',
            'password' => 'UpdatedPass2!', 'password_confirmation' => 'UpdatedPass2!'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['action' => 'Changed password', 'result' => 'failed',
            'details' => 'Password reset failed: incorrect or expired code.']);
        $this->actingAs($user)->postJson('/api/change-password', ['current_password' => 'CurrentPass1!',
            'password' => 'weak', 'password_confirmation' => 'different'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['result' => 'failed', 'details' => 'Password change input validation failed.']);
        $this->assertTrue(Hash::check('CurrentPass1!', $user->fresh()->password));
        $logs = DB::table('audit_logs')->get()->toJson();
        foreach (['654321', 'CurrentPass1!', 'UpdatedPass2!', 'weak'] as $secret) {
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    public function test_unknown_and_non_admin_login_attempts_are_not_in_admin_security_log(): void
    {
        $this->postJson('/api/login', ['email' => 'unknown@laverdad.edu.ph', 'password' => 'WrongPass1!'])->assertUnauthorized();
        $user = $this->admin();
        User::withoutEvents(fn () => $user->update(['role' => 'secretary']));
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'WrongPass1!'])->assertUnauthorized();
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_rate_limited_admin_login_is_logged_as_failed(): void
    {
        $user = $this->admin();
        config(['auth.login_throttle.max_attempts' => 1]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'WrongPass1!'])->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'CurrentPass1!'])->assertStatus(429);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Signed in', 'result' => 'failed',
            'details' => 'Sign-in attempt blocked by rate limit.']);
        $this->assertSame(0, DB::table('audit_logs')->where('result', 'success')->count());
    }
}
