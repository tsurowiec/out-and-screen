<?php

namespace App\Models;

use App\Support\TripWeek;
use Database\Factories\TripEntryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stretch of time spent out on a trip. Trips are logged per day rather than
 * per session, so a date and a duration is all there is to one.
 */
class TripEntry extends Model
{
    /** @use HasFactory<TripEntryFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'minutes',
        'description',
    ];

    protected function casts(): array
    {
        return [
            // Pinned to Y-m-d so the stored value matches the plain date strings
            // the form works in.
            'date' => 'date:Y-m-d',
            'minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The Saturday that opens the week this trip falls in. */
    public function weekStart(): Carbon
    {
        return TripWeek::startFor($this->date);
    }

    public function scopeBetweenDays(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
    }
}
