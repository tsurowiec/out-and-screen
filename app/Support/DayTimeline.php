<?php

namespace App\Support;

use App\Enums\ScreenType;
use App\Models\ScreenTimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A single day's worth of screen time, laid out as percentage offsets across the
 * dashboard's visible window (6am–10pm by default) so the Blade view can render
 * it as a horizontal bar without doing any arithmetic itself.
 */
class DayTimeline
{
    /**
     * @param  array<int, array{type: ScreenType, left: float, width: float, minutes: int, from: string, to: string}>  $segments
     */
    public function __construct(
        public Carbon $day,
        public array $segments,
        public int $totalMinutes,
        public int $limitMinutes,
        public bool $limitIsOverridden = false,
    ) {}

    /**
     * @param  Collection<int, ScreenTimeEntry>  $entries  entries that started on $day
     * @param  int|null  $limitMinutes  a manual override, or null for the scheduled default
     */
    public static function build(Carbon $day, Collection $entries, ?int $limitMinutes = null): self
    {
        $windowStart = $day->copy()->startOfDay()->addHours(config('screen-time.day_start_hour'));
        $windowEnd = $day->copy()->startOfDay()->addHours(config('screen-time.day_end_hour'));
        $windowMinutes = $windowStart->diffInMinutes($windowEnd);

        $segments = [];

        foreach ($entries->sortBy('started_at') as $entry) {
            $from = $entry->started_at->max($windowStart);
            $to = $entry->endedAt()->min($windowEnd);

            // Entirely outside the visible window — it still counts toward the
            // daily total, it just has nowhere to be drawn.
            if ($to <= $from) {
                continue;
            }

            $segments[] = [
                'type' => $entry->type,
                'left' => round($windowStart->diffInMinutes($from) / $windowMinutes * 100, 4),
                'width' => round($from->diffInMinutes($to) / $windowMinutes * 100, 4),
                'minutes' => $entry->minutes,
                'from' => $entry->started_at->format('H:i'),
                'to' => $entry->endedAt()->format('H:i'),
            ];
        }

        return new self(
            $day,
            $segments,
            (int) $entries->sum('minutes'),
            $limitMinutes ?? ScreenTimeLimit::default($day),
            $limitMinutes !== null,
        );
    }

    /**
     * Hour labels for the axis, e.g. [6, 8, 10, ... 22].
     *
     * @return array<int, int>
     */
    public static function hourTicks(int $step = 2): array
    {
        return range(config('screen-time.day_start_hour'), config('screen-time.day_end_hour'), $step);
    }

    public function isOverLimit(): bool
    {
        return $this->totalMinutes > $this->limitMinutes;
    }

    public function remainingMinutes(): int
    {
        return max(0, $this->limitMinutes - $this->totalMinutes);
    }

    /**
     * Why this day has the allowance it does — either the manual override or the
     * schedule rule that produced it.
     */
    public function limitReason(): string
    {
        return $this->limitIsOverridden ? 'Manual override' : ScreenTimeLimit::reason($this->day);
    }
}
