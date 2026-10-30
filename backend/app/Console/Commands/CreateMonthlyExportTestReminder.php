<?php

namespace App\Console\Commands;

use App\Models\{MonthlyExportTask, Reminder, User};
use App\Services\Reminders\MonthlyExportService;
use App\Support\SystemTimezone;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Validator};

class CreateMonthlyExportTestReminder extends Command
{
    protected $signature = 'reminders:monthly-export-test {--month=} {--user=} {--program=} {--year=}';
    protected $description = 'Create one early monthly export reminder against real local data, without changing the scheduler';

    public function handle(MonthlyExportService $exports): int
    {
        $connection = config('database.default');
        $host = config("database.connections.$connection.host");
        if (!app()->environment('local', 'testing') || ($connection !== 'sqlite' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            $this->error('This test command is available only in local/testing environments with a local database.');
            return self::FAILURE;
        }

        $data = [
            'month' => $this->option('month') ?: SystemTimezone::today()->format('Y-m'),
            'user' => $this->option('user'), 'program' => strtoupper(trim((string) $this->option('program'))),
            'year' => $this->option('year'),
        ];
        $validator = Validator::make($data, [
            'month' => 'required|date_format:Y-m', 'user' => 'required',
            'program' => 'required|string', 'year' => 'required|integer|between:1,4',
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) $this->error($error);
            return self::FAILURE;
        }
        $user = ctype_digit((string) $data['user'])
            ? User::find($data['user']) : User::where('email', $data['user'])->first();
        if (!$user) {
            $this->error('Account not found. Use an existing account ID or email.');
            return self::FAILURE;
        }
        if (!$user->is_active || !in_array([$data['program'], (int) $data['year']], $exports->scopes($user), true)) {
            $this->error('This active account is not authorized for that program/year.');
            return self::FAILURE;
        }
        $month = Carbon::createFromFormat('!Y-m', $data['month'], SystemTimezone::timezone());
        $task = DB::transaction(function () use ($data, $user, $month, $exports) {
            $task = MonthlyExportTask::where('user_id', $user->id)->where('program', $data['program'])
                ->where('year_level', (int) $data['year'])->whereDate('month', $month->toDateString())
                ->firstOrCreate([], [
                    'user_id' => $user->id, 'program' => $data['program'], 'year_level' => (int) $data['year'],
                    'month' => $month->toDateString(),
                ]);
            $task = MonthlyExportTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (!$task->reminder_id) {
                $reminder = Reminder::create([
                    'user_id' => $user->id, 'reminder_type' => 'monthly_export',
                    'program' => $data['program'], 'year_level' => (int) $data['year'],
                    'title' => 'Export '.$month->format('F Y').' attendance records (Local test)',
                    'description' => 'Local monthly export test.', 'due_date' => SystemTimezone::now(),
                    'status' => 'needs_action',
                ]);
                $task->update(['reminder_id' => $reminder->id]);
            }
            $exports->refresh($task);
            return $task;
        });
        $snapshot = $exports->snapshot($task);
        $this->info("Reminder {$task->reminder_id} is available for {$user->name}: {$task->program} Year {$task->year_level}, {$month->format('F Y')}.");
        $this->line('Real local data: '.$snapshot['students']->count().' students, '.$snapshot['rows']->count().' recorded entries.');
        $this->line($snapshot['notice']);
        $this->line('Normal eligibility dates and attendance data were not changed. Existing reminders were reused.');
        return self::SUCCESS;
    }
}
