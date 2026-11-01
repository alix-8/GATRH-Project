<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BenchmarkAuthStorage extends Command
{
    protected $signature = 'auth:benchmark-storage {--samples=3 : Number of probes, from 1 to 10}';
    protected $description = 'Measure auth storage latency with temporary probes, without changing configured drivers';

    public function handle(): int
    {
        $samples = filter_var($this->option('samples'), FILTER_VALIDATE_INT);
        if ($samples === false || $samples < 1 || $samples > 10) {
            $this->error('Samples must be between 1 and 10.');
            return self::FAILURE;
        }

        $cache = Cache::store();
        $session = app('session')->driver()->getHandler();
        $cacheKey = 'auth-storage-probe:'.Str::uuid();
        $sessionId = Str::random(40);
        $timings = [];

        $this->table(['Storage', 'Configured driver'], [
            ['Database connection', config('database.default')],
            ['Cache', config('cache.default')],
            ['Session', config('session.driver')],
        ]);

        try {
            $operations = [
                'Database SELECT 1' => fn () => DB::select('SELECT 1'),
                'Cache write' => fn () => $cache->put($cacheKey, 'probe', 60),
                'Cache read' => fn () => $cache->get($cacheKey),
                'Session write' => fn () => $session->write($sessionId, 'probe'),
                'Session read' => fn () => $session->read($sessionId),
            ];
            for ($sample = 0; $sample < $samples; $sample++) {
                foreach ($operations as $label => $operation) {
                    $start = hrtime(true);
                    $operation();
                    $timings[$label][] = (hrtime(true) - $start) / 1_000_000;
                }
            }
            $rows = [];
            foreach ($timings as $label => $values) {
                $rows[] = [$label, number_format(min($values), 2),
                    number_format(array_sum($values) / count($values), 2), number_format(max($values), 2)];
            }
            $this->table(['Operation', 'Min ms', 'Average ms', 'Max ms'], $rows);
            $this->info('Temporary probes only. No passwords, user records, or real sessions were accessed.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Storage probe failed ('.class_basename($exception).'). Check the configured storage service.');
            return self::FAILURE;
        } finally {
            try { $cache->forget($cacheKey); } catch (\Throwable) { /* Probe expires after 60 seconds. */ }
            try { $session->destroy($sessionId); } catch (\Throwable) { /* Uses a random, unused session ID. */ }
        }
    }
}
