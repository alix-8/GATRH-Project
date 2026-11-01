<?php

namespace Tests\Unit;

use App\Services\Dashboard\CmCoordinatorMonitoringService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class CmCoordinatorReportingWeeksTest extends TestCase
{
    private function weeks(string $month): Collection
    {
        $method = new ReflectionMethod(CmCoordinatorMonitoringService::class, 'getWeeks');

        return $method->invoke(new CmCoordinatorMonitoringService(), Carbon::parse($month));
    }

    public function test_cross_month_week_belongs_only_to_its_wednesday_month(): void
    {
        $october = $this->weeks('2026-10-01');
        $november = $this->weeks('2026-11-01');

        $this->assertCount(4, $october);
        $this->assertSame('2026-10-07', $october->first()['start']);
        $this->assertSame('2026-10-28', $october->last()['start']);
        $this->assertSame('2026-11-03', $october->last()['end']);
        $this->assertCount(4, $november);
        $this->assertSame('2026-11-04', $november->first()['start']);
        $this->assertEmpty($october->pluck('start')->intersect($november->pluck('start')));
        $this->assertSame([1, 2, 3, 4], $november->pluck('number')->all());
    }

    public function test_every_week_has_one_owner_and_preserves_its_full_gathering_windows(): void
    {
        $starts = [];
        for ($month = 1; $month <= 12; $month++) {
            $weeks = $this->weeks(sprintf('2026-%02d-01', $month));
            $this->assertContains($weeks->count(), [4, 5]);

            foreach ($weeks as $week) {
                $start = Carbon::parse($week['start']);
                $this->assertSame($month, $start->month);
                $this->assertSame(Carbon::WEDNESDAY, $start->dayOfWeek);
                $this->assertSame($start->copy()->addDays(6)->toDateString(), $week['end']);
                $this->assertSame($week['start'], $week['gathering_ranges']['pm']['start']);
                $this->assertSame($start->copy()->addDays(4)->toDateString(), $week['gathering_ranges']['tg']['end']);
                $starts[] = $week['start'];
            }
        }

        $this->assertCount(52, $starts);
        $this->assertSame($starts, array_values(array_unique($starts)));
        $this->assertSame('2027-01-05', $this->weeks('2026-12-01')->last()['end']);
        $this->assertSame('2027-01-06', $this->weeks('2027-01-01')->first()['start']);
    }
}
