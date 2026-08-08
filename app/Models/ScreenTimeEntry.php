<?php

namespace App\Models;

use App\Enums\ScreenType;
use Database\Factories\ScreenTimeEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ScreenTimeEntry extends Model
{
    /** @use HasFactory<ScreenTimeEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'minutes',
        'started_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ScreenType::class,
            'minutes' => 'integer',
            'started_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function endedAt(): Carbon
    {
        return $this->started_at->copy()->addMinutes($this->minutes);
    }

    /**
     * Entries that start within the given day. An entry that runs past midnight
     * still belongs to the day it started on.
     */
    public function scopeOnDay(Builder $query, Carbon $day): Builder
    {
        return $query->whereBetween('started_at', [
            $day->copy()->startOfDay(),
            $day->copy()->endOfDay(),
        ]);
    }

    public function scopeBetweenDays(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('started_at', [
            $from->copy()->startOfDay(),
            $to->copy()->endOfDay(),
        ]);
    }
}
