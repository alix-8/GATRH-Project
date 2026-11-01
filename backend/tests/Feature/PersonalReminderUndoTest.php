<?php

namespace Tests\Feature;

use App\Models\{Reminder, User};
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonalReminderUndoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-01 12:00:00');
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email');
            $table->string('password'); $table->string('role'); $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('reminders', function (Blueprint $table) {
            $table->id(); $table->integer('user_id'); $table->string('reminder_type');
            $table->string('title'); $table->string('status'); $table->dateTime('due_date');
            $table->dateTime('completed_at')->nullable(); $table->dateTime('expired_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::withoutEvents(fn () => User::create(['name' => 'Secretary',
            'email' => uniqid().'@laverdad.edu.ph', 'password' => 'password',
            'role' => 'secretary', 'is_active' => true]));
    }

    private function reminder(User $user, array $attributes = []): Reminder
    {
        return Reminder::create(array_merge(['user_id' => $user->id, 'reminder_type' => 'custom',
            'title' => 'Personal task', 'status' => 'completed', 'due_date' => now()->addDay(),
            'completed_at' => now(), 'expired_at' => now()], $attributes));
    }

    public function test_undo_restores_status_from_due_date_and_clears_completion_metadata(): void
    {
        $user = $this->owner();
        foreach ([-1 => 'needs_action', 0 => 'upcoming', 1 => 'upcoming'] as $offset => $status) {
            $reminder = $this->reminder($user, ['due_date' => now()->addDays($offset)]);
            $this->actingAs($user)->patchJson('/api/reminders/'.$reminder->id.'/undo')
                ->assertOk()->assertJsonPath('data.status', $status)
                ->assertJsonPath('data.completed_at', null)->assertJsonPath('data.expired_at', null);
            $this->assertSame($status, $reminder->fresh()->status);
            $this->assertSame('Personal task', $reminder->fresh()->title);
            $this->actingAs($user)->patchJson('/api/reminders/'.$reminder->id.'/undo')->assertStatus(409);
        }
    }

    public function test_undo_cannot_modify_another_users_reminder(): void
    {
        $owner = $this->owner(); $other = $this->owner();
        $reminder = $this->reminder($owner);
        $this->actingAs($other)->patchJson('/api/reminders/'.$reminder->id.'/undo')->assertNotFound();
        $this->assertSame('completed', $reminder->fresh()->status);
    }

    public function test_undo_rejects_system_and_monthly_export_reminders(): void
    {
        $user = $this->owner();
        foreach (['monthly_export', 'gathering', 'submission_countdown'] as $type) {
            $reminder = $this->reminder($user, ['reminder_type' => $type]);
            $this->actingAs($user)->patchJson('/api/reminders/'.$reminder->id.'/undo')->assertStatus(422);
            $this->assertSame('completed', $reminder->fresh()->status);
        }
    }

    public function test_chancellor_cannot_access_reminder_endpoints(): void
    {
        $user = $this->owner();
        $user->role = 'chancellor';
        $reminder = $this->reminder($user);
        $this->actingAs($user)->getJson('/api/reminders')->assertForbidden();
        $this->actingAs($user)->getJson('/api/reminders/role-summary')->assertForbidden();
        $this->actingAs($user)->postJson('/api/reminders', [])->assertForbidden();
        $this->actingAs($user)->patchJson('/api/reminders/'.$reminder->id.'/undo')->assertForbidden();
        $this->assertSame('completed', $reminder->fresh()->status);
    }
}
