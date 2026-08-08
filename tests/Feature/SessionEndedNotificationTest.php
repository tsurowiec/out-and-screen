<?php

namespace Tests\Feature;

use App\Enums\ScreenType;
use App\Jobs\NotifySessionEnded;
use App\Jobs\SendTestPush;
use App\Models\PushSubscription;
use App\Models\ScreenTimeEntry;
use App\Models\User;
use App\Support\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SessionEndedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_adding_a_session_queues_the_notification_for_its_end(): void
    {
        Carbon::setTestNow(today()->setTime(15, 0));
        Queue::fake();

        Volt::actingAs(User::factory()->create())
            ->test('screen-time.dashboard')
            ->call('add', 30);

        Queue::assertPushed(NotifySessionEnded::class);
    }

    public function test_a_session_that_already_ended_is_never_queued(): void
    {
        Queue::fake();

        $entry = $this->entry(startedAt: now()->subHour(), minutes: 30);

        NotifySessionEnded::scheduleFor($entry);

        Queue::assertNothingPushed();
    }

    public function test_it_notifies_when_the_session_really_is_ending(): void
    {
        $entry = $this->entry(startedAt: now()->subMinutes(30), minutes: 30);
        PushSubscription::query()->create($this->subscriptionAttributes());

        $sender = $this->spyingSender();

        (new NotifySessionEnded($entry->id))->handle($sender);

        $this->assertCount(1, $sender->sent);
        $this->assertSame('TV time is up', $sender->sent[0]['title']);
        $this->assertNotNull($entry->fresh()->notified_at);
    }

    public function test_an_extended_session_supersedes_the_job_already_waiting(): void
    {
        $entry = $this->entry(startedAt: now()->subMinutes(30), minutes: 30);
        PushSubscription::query()->create($this->subscriptionAttributes());

        // The session gets another 15 minutes just before the original job runs.
        $entry->update(['minutes' => 45]);

        $sender = $this->spyingSender();

        (new NotifySessionEnded($entry->id))->handle($sender);

        $this->assertSame([], $sender->sent);
        $this->assertNull($entry->fresh()->notified_at);
    }

    public function test_a_stopped_session_does_not_notify_at_its_original_deadline(): void
    {
        $entry = $this->entry(startedAt: now()->subMinutes(30), minutes: 60);
        PushSubscription::query()->create($this->subscriptionAttributes());

        // Stopped early, so by the time the original job runs its end is long past.
        $entry->update(['minutes' => 5]);

        $sender = $this->spyingSender();

        (new NotifySessionEnded($entry->id))->handle($sender);

        $this->assertSame([], $sender->sent);
    }

    public function test_a_deleted_session_does_not_notify(): void
    {
        $entry = $this->entry(startedAt: now()->subMinutes(30), minutes: 30);
        $id = $entry->id;
        $entry->delete();

        $sender = $this->spyingSender();

        (new NotifySessionEnded($id))->handle($sender);

        $this->assertSame([], $sender->sent);
    }

    public function test_it_never_notifies_twice_for_the_same_session(): void
    {
        $entry = $this->entry(startedAt: now()->subMinutes(30), minutes: 30);
        PushSubscription::query()->create($this->subscriptionAttributes());

        $sender = $this->spyingSender();

        (new NotifySessionEnded($entry->id))->handle($sender);
        (new NotifySessionEnded($entry->id))->handle($sender);

        $this->assertCount(1, $sender->sent);
    }

    public function test_a_device_can_subscribe_and_unsubscribe(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/push/subscribe', [
                'endpoint' => 'https://web.push.apple.com/abc123',
                'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
            ])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);

        // Subscribing again from the same device updates rather than duplicates.
        $this->actingAs($user)
            ->postJson('/push/subscribe', [
                'endpoint' => 'https://web.push.apple.com/abc123',
                'keys' => ['p256dh' => 'rotated-key', 'auth' => 'auth-token'],
            ])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertSame('rotated-key', PushSubscription::query()->sole()->public_key);

        $this->actingAs($user)
            ->deleteJson('/push/subscribe', ['endpoint' => 'https://web.push.apple.com/abc123'])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_the_test_button_queues_a_push_for_two_minutes_time(): void
    {
        Carbon::setTestNow(today()->setTime(15, 0));
        Queue::fake();

        Volt::actingAs(User::factory()->create())
            ->test('screen-time.dashboard')
            ->call('sendTestPush');

        Queue::assertPushed(
            SendTestPush::class,
            fn (SendTestPush $job) => $job->delay->equalTo(now()->addMinutes(2)),
        );
    }

    public function test_a_test_push_only_reaches_the_device_that_asked_for_it(): void
    {
        $mine = PushSubscription::query()->create($this->subscriptionAttributes());
        PushSubscription::query()->create([
            ...$this->subscriptionAttributes(),
            'endpoint' => $other = 'https://web.push.apple.com/someone-else',
            'endpoint_hash' => PushSubscription::hashFor($other),
        ]);

        $sender = $this->spyingSender();

        (new SendTestPush($mine->user_id))->handle($sender);

        $this->assertCount(1, $sender->sent);
        $this->assertSame('Test notification', $sender->sent[0]['title']);
        $this->assertSame([$mine->endpoint], $sender->endpoints);
    }

    public function test_subscribing_requires_signing_in(): void
    {
        $this->postJson('/push/subscribe', [
            'endpoint' => 'https://web.push.apple.com/abc123',
            'keys' => ['p256dh' => 'public-key', 'auth' => 'auth-token'],
        ])->assertUnauthorized();
    }

    protected function entry(Carbon $startedAt, int $minutes): ScreenTimeEntry
    {
        return ScreenTimeEntry::query()->create([
            'user_id' => User::factory()->create()->id,
            'type' => ScreenType::Tv,
            'minutes' => $minutes,
            'started_at' => $startedAt,
        ]);
    }

    /**
     * @return array<string, string|int>
     */
    protected function subscriptionAttributes(): array
    {
        $endpoint = 'https://web.push.apple.com/abc123';

        return [
            'user_id' => User::factory()->create()->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashFor($endpoint),
            'public_key' => 'public-key',
            'auth_token' => 'auth-token',
        ];
    }

    /**
     * A sender that records payloads instead of talking to a push service.
     */
    protected function spyingSender(): PushSender
    {
        return new class extends PushSender
        {
            /** @var array<int, array<string, mixed>> */
            public array $sent = [];

            /** @var array<int, string> */
            public array $endpoints = [];

            public function send(Collection $subscriptions, array $payload): int
            {
                $this->sent[] = $payload;
                $this->endpoints = $subscriptions->pluck('endpoint')->all();

                return $subscriptions->count();
            }
        };
    }
}
