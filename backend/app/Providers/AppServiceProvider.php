<?php

namespace App\Providers;
use App\Models\AttendanceRecord;
use App\Models\Program;
use App\Models\Student;
use App\Models\User;
use App\Observers\AdminActivityObserver;
use App\Observers\AttendanceRecordObserver;
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
    }
}
