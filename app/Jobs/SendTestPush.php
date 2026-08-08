<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Support\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a throwaway push, so a device can be checked end to end without
 * burning real screen time.
 *
 * Queued with the same delay a short session would have, because the awkward
 * part to verify is a notification arriving while the app is closed — not one
 * that fires while it's still on screen.
 */
class SendTestPush implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId) {}

    public function handle(PushSender $sender): void
    {
        $subscriptions = PushSubscription::query()
            ->where('user_id', $this->userId)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $sender->send($subscriptions, [
            'title' => 'Test notification',
            'body' => 'Push notifications are working on this device.',
            'tag' => 'test-push',
            'url' => route('dashboard'),
        ]);
    }
}
