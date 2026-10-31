<?php

namespace App\Models\Concerns;

trait TracksRosterHistory
{
    public static function bootTracksRosterHistory(): void
    {
        static::updating(function ($model): void {
            $model->rememberRosterStatusChange();
        });
    }

    public function rememberRosterStatusChange(): void
    {
        if (! $this->isDirty('is_active')) {
            return;
        }

        $history = $this->roster_history ?? [];
        if ($history === []) {
            $history[] = ['at' => null, 'active' => (bool) $this->getOriginal('is_active')];
        }
        $history[] = ['at' => now()->toIso8601String(), 'active' => (bool) $this->is_active];
        $this->roster_history = $history;
    }

    public function wasActiveOn(string $date): bool
    {
        $history = $this->roster_history ?? [];
        if ($history === []) {
            return (bool) $this->is_active;
        }

        $active = (bool) $history[0]['active'];
        foreach ($history as $change) {
            if ($change['at'] !== null && substr($change['at'], 0, 10) <= $date) {
                $active = (bool) $change['active'];
            }
        }

        return $active;
    }
}
