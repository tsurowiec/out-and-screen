<?php

namespace App\Support;

use App\Models\PushSubscription;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Delivers Web Push messages, and prunes registrations the push services have
 * given up on.
 */
class PushSender
{
    /**
     * Send one payload to every registration passed in.
     *
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $payload
     * @return int How many were accepted by their push service.
     */
    public function send(Collection $subscriptions, array $payload): int
    {
        if ($subscriptions->isEmpty() || ! $this->isConfigured()) {
            return 0;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => config('webpush.vapid.subject'),
            'publicKey' => config('webpush.vapid.public_key'),
            'privateKey' => config('webpush.vapid.private_key'),
        ]]);

        $webPush->setDefaultOptions(['TTL' => config('webpush.ttl')]);

        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification($this->toSubscription($subscription), $body);
        }

        $sent = 0;

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;

                continue;
            }

            // 404/410 mean the browser threw the registration away — usually the
            // app was deleted from the home screen. Anything else is transient,
            // so only the expired ones get dropped.
            if ($report->isSubscriptionExpired()) {
                PushSubscription::query()
                    ->where('endpoint_hash', PushSubscription::hashFor($report->getEndpoint()))
                    ->delete();

                continue;
            }

            Log::warning('Web push delivery failed.', [
                'endpoint' => $report->getEndpoint(),
                'reason' => $report->getReason(),
            ]);
        }

        return $sent;
    }

    /**
     * Whether VAPID keys have been set up. Without them nothing can be signed,
     * so sending is skipped rather than throwing on every queued job.
     */
    public function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key'))
            && filled(config('webpush.vapid.private_key'));
    }

    protected function toSubscription(PushSubscription $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => $subscription->endpoint,
            'publicKey' => $subscription->public_key,
            'authToken' => $subscription->auth_token,
            'contentEncoding' => $subscription->content_encoding,
        ]);
    }
}
