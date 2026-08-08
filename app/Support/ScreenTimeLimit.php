<?php

namespace App\Support;

use App\Models\ScreenTimeLimitOverride;
use Illuminate\Support\Carbon;

/**
 * Works out how much screen time a given day is allowed, before any manual
 * override is taken into account.
 */
class ScreenTimeLimit
{
    /**
     * The allowance actually in force for a date, honouring a manual override.
     * Allowances belong to the household, not to whoever is logged in.
     */
    public static function for(Carbon $date): int
    {
        return ScreenTimeLimitOverride::whereDate('date', $date)->value('minutes')
            ?? self::default($date);
    }

    /**
     * The scheduled allowance for a date, in minutes, ignoring any override.
     */
    public static function default(Carbon $date): int
    {
        if (self::isHoliday($date) || $date->isWeekend()) {
            return (int) config('screen-time.limits.weekend_minutes');
        }

        return (int) config('screen-time.limits.weekday_minutes');
    }

    /**
     * Whether the date falls in a school-holiday month, when weekday and
     * weekend allowances are the same.
     */
    public static function isHoliday(Carbon $date): bool
    {
        return in_array($date->month, config('screen-time.limits.holiday_months'), true);
    }

    /**
     * A short human explanation of where the default came from, e.g. for a
     * tooltip next to the day's allowance.
     */
    public static function reason(Carbon $date): string
    {
        return match (true) {
            self::isHoliday($date) => 'Summer holidays',
            $date->isWeekend() => 'Weekend',
            default => 'School-year weekday',
        };
    }
}
