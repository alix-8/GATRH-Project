<?php

namespace App\Providers;
use App\Models\AttendanceRecord;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Observers\AdminActivityObserver;
use App\Observers\AttendanceRecordObserver;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
        AttendanceRecord::observe(AttendanceRecordObserver::class);
        User::observe(AdminActivityObserver::class);
        Student::observe(AdminActivityObserver::class);
        Program::observe(AdminActivityObserver::class);

        ResetPassword::createUrlUsing(function ($notifiable, string $token): string {
            return config('app.password_reset_url')
                . '?token=' . urlencode($token)
                . '&email=' . urlencode($notifiable->getEmailForPasswordReset());
        });
    }
}
