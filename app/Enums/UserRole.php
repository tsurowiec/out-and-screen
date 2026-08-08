<?php

namespace App\Enums;

/**
 * Everyone shares the same screen time data; the role decides who may change it.
 */
enum UserRole: string
{
    /** A parent: full access. */
    case Parent = 'parent';

    /** The kid: can see everything, can change nothing. */
    case Child = 'child';

    public function label(): string
    {
        return match ($this) {
            self::Parent => 'Parent',
            self::Child => 'Child',
        };
    }

    public function canManage(): bool
    {
        return $this === self::Parent;
    }
}
