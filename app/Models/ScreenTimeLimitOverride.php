<?php

namespace App\Models;

use App\Support\ScreenTimeLimit;
use Database\Factories\ScreenTimeLimitOverrideFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A manual, one-off allowance for a single day that replaces the scheduled
 * default from {@see ScreenTimeLimit}.
 */
class ScreenTimeLimitOverride extends Model
{
    /** @use HasFactory<ScreenTimeLimitOverrideFactory> */
    use HasFactory;

    protected $fillable = [
        'date',
        'minutes',
    ];

    protected function casts(): array
    {
        return [
            // Pinned to Y-m-d so the stored value matches the plain date strings
            // used to look a day's override up.
            'date' => 'date:Y-m-d',
            'minutes' => 'integer',
        ];
    }

    public function scopeBetweenDays(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }
}
