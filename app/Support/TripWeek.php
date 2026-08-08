<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Trips are totalled per week, and the household's week runs Saturday through
 * Friday rather than the usual Monday start — a weekend away lands in the same
 * week as the days off that follow it.
 */
class TripWeek
{
    public const START_DAY = CarbonInterface::SATURDAY;

    public const END_DAY = CarbonInterface::FRIDAY;

    /** The Saturday that opens the week the given date falls in. */
    public static function startFor(Carbon $date): Carbon
    {
        return $date->copy()->startOfDay()->startOfWeek(self::START_DAY);
    }

    /** The Friday that closes the week the given date falls in. */
    public static function endFor(Carbon $date): Carbon
    {
        return self::startFor($date)->addDays(6);
    }

    /**
     * Human label for a week, given the Saturday it starts on. The year is only
     * spelled out when the week doesn't belong to the current one.
     */
    public static function label(Carbon $start): string
    {
        $end = $start->copy()->addDays(6);
        $format = $end->isSameYear(today()) ? 'j M' : 'j M Y';

        return $start->format('j M').' – '.$end->format($format);
    }

    /** Whether the given week start is the week we're in right now. */
    public static function isCurrent(Carbon $start): bool
    {
        return $start->isSameDay(self::startFor(today()));
    }

    /** Whether the given week start is the week just gone. */
    public static function isPrevious(Carbon $start): bool
    {
        return $start->isSameDay(self::startFor(today())->subWeek());
    }

    /**
     * How to name a week alongside its dates: the two most recent weeks get a
     * word, everything further back is dates only.
     */
    public static function prefix(Carbon $start): ?string
    {
        return match (true) {
            self::isCurrent($start) => 'This week',
            self::isPrevious($start) => 'Last week',
            default => null,
        };
    }
}
