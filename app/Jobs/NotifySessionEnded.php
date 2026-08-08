<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\ScreenTimeEntry;
use App\Support\PushSender;
use App\Support\ScreenTimeLimit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Tells everyone's phone that a screen time session has run out.
 *
 * Queued with a delay reaching to the session's end, but a session can be
 * extended, shortened or deleted after that job is already waiting — and a
 * queued job can't be recalled. So this re-reads the entry when it runs and
 * bails unless the session really is ending about now; a superseded job simply
 * finds a deadline that has moved and does nothing.
 */
class NotifySessionEnded implements ShouldQueue
{
    use Queueable;

    /**
     * How far either side of the deadline still counts as "now". The late side
     * is generous because a busy worker can lag; the early side only absorbs
     * clock jitter, so an extended session is always recognised as superseded.
     */
    protected const LATE_TOLERANCE_MINUTES = 5;

    protected const EARLY_TOLERANCE_SECONDS = 30;

    public function __construct(public int $entryId) {}

    /**
     * Queue the announcement for when this session is due to end.
     *
     * Safe to call again whenever the entry changes: the newest job wins, and
     * any job already waiting on the old deadline will bail when it runs.
     */
    public static function scheduleFor(ScreenTimeEntry $entry): void
    {
        if ($entry->endedAt()->isPast()) {
            // Backfilled or already-finished sessions have nothing to announce.
            return;
        }

        if ($entry->notified_at !== null) {
            // Extended past its end after the push went out, so it gets to
            // announce itself a second time.
            $entry->forceFill(['notified_at' => null])->save();
        }

        self::dispatch($entry->id)->delay($entry->endedAt());
    }

    public function handle(PushSender $sender): void
    {
        $entry = ScreenTimeEntry::query()->find($this->entryId);

        if ($entry === null || $entry->notified_at !== null) {
            // Deleted, or already announced by an earlier run.
            return;
        }

        if (! $this->isEndingNow($entry->endedAt())) {
            return;
        }

        // Claim the entry before sending, so a retry after a partial failure
        // can't produce a second buzz on every phone.
        $entry->forceFill(['notified_at' => now()])->save();

        $subscriptions = PushSubscription::query()->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $sender->send($subscriptions, [
            'title' => $entry->type->label().' time is up',
            'body' => $this->body($entry),
            'tag' => 'session-ended',
            'url' => route('dashboard'),
        ]);
    }

    /**
     * Whether the deadline this job was queued for is still the entry's real
     * deadline.
     */
    protected function isEndingNow(Carbon $endedAt): bool
    {
        return $endedAt->isAfter(now()->subMinutes(self::LATE_TOLERANCE_MINUTES))
            && $endedAt->isBefore(now()->addSeconds(self::EARLY_TOLERANCE_SECONDS));
    }

    /**
     * A line of context, so the notification answers "and how much is left?"
     * without anyone having to open the app.
     */
    protected function body(ScreenTimeEntry $entry): string
    {
        $used = (int) ScreenTimeEntry::query()
            ->onDay($entry->started_at)
            ->sum('minutes');

        $remaining = ScreenTimeLimit::for($entry->started_at) - $used;

        $session = $this->humanize($entry->minutes);

        return $remaining > 0
            ? "{$session} session finished. {$this->humanize($remaining)} left today."
            : "{$session} session finished. Today's screen time is used up.";
    }

    protected function humanize(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours}h" : "{$hours}h {$rest}m";
    }
}
