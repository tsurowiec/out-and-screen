<?php

namespace App\Enums;

/**
 * Case order drives the order the types are shown in on the dashboard pickers
 * and legend; it has no bearing on what is stored.
 */
enum ScreenType: string
{
    case Mobile = 'mobile';
    case Tv = 'tv';
    case Playstation = 'playstation';
    case Computer = 'computer';
    case Youtube = 'youtube';

    public function label(): string
    {
        return match ($this) {
            self::Tv => 'TV',
            self::Youtube => 'YouTube',
            self::Playstation => 'PlayStation',
            self::Mobile => 'Phone',
            self::Computer => 'Computer',
        };
    }

    /**
     * Hex colour used for this type on the timeline charts. Kept as a hex value
     * (rather than a Tailwind class) because the chart segments are positioned
     * with inline styles, which Tailwind cannot generate at runtime.
     */
    public function color(): string
    {
        return match ($this) {
            self::Tv => '#3b82f6',
            self::Youtube => '#ef4444',
            self::Playstation => '#22c55e',
            self::Mobile => '#eab308',
            self::Computer => '#f97316',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Tv => 'tv',
            self::Youtube => 'play',
            self::Playstation => 'puzzle-piece',
            self::Mobile => 'device-phone-mobile',
            self::Computer => 'computer-desktop',
        };
    }
}
