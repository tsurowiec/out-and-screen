<?php

use App\Jobs\SendTestPush;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Queue a test notification for two minutes' time — long enough to lock
     * the phone and close the app before it arrives.
     */
    public function sendTestPush(): void
    {
        SendTestPush::dispatch(auth()->id())->delay(now()->addMinutes(2));

        $this->dispatch('test-push-queued');
    }

    public function with(): array
    {
        return [
            'pushConfigured' => filled(config('webpush.vapid.public_key')),
        ];
    }
}; ?>

<div class="flex flex-col items-start">
    @include('partials.settings-heading')

    <x-settings.layout heading="Notifications" subheading="Get a buzz on this device when a session runs out">
        @if ($pushConfigured)
            {{--
                Subscribing is per-device, not per-account: each phone registers
                itself with its own push service, so this panel reports on the
                one it is being viewed from.
            --}}
            <div
                x-data="{
                    state: 'checking',
                    error: null,
                    busy: false,
                    queued: false,
                    ios: false,
                    diagnostics: null,
                    /**
                     * The handful of values that decide whether a prompt can appear at
                     * all. Shown on the panel because none of it is visible from the
                     * phone otherwise — there are no dev tools on a Home Screen app.
                     */
                    describe(step) {
                        this.diagnostics = [
                            step,
                            'permission: ' + (window.push?.permission() ?? 'no script'),
                            'installed: ' + (window.push?.standalone() ? 'yes' : 'no'),
                            'worker: ' + ('serviceWorker' in navigator ? 'yes' : 'no'),
                        ].join(' · ')
                    },
                    async init() {
                        this.ios = Boolean(window.push?.isIos())

                        if (! window.push?.supported()) {
                            this.state = 'unsupported'
                            return
                        }

                        // iOS exposes the push APIs in Safari but refuses to subscribe
                        // there — only the home-screen app can.
                        if (window.push.isIos() && ! window.push.standalone()) {
                            this.state = 'needs-install'
                            return
                        }

                        if (window.push.permission() === 'denied') {
                            this.state = 'denied'
                            return
                        }

                        this.state = (await window.push.subscribed()) ? 'on' : 'off'
                    },
                    async enable() {
                        this.busy = true
                        this.error = null
                        this.describe('before')

                        try {
                            await window.push.subscribe()
                            this.state = 'on'
                            this.describe('subscribed')
                        } catch (e) {
                            this.error = e.message
                            this.describe('failed')
                            if (window.push.permission() === 'denied') this.state = 'denied'
                        } finally {
                            this.busy = false
                        }
                    },
                    async disable() {
                        this.busy = true

                        try {
                            await window.push.unsubscribe()
                            this.state = 'off'
                        } catch (e) {
                            this.error = e.message
                        } finally {
                            this.busy = false
                        }
                    },
                }"
                x-on:test-push-queued.window="queued = true"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <flux:subheading>
                        <span x-show="state === 'on'">This device will buzz when a session runs out.</span>
                        <span x-show="state === 'off'">Notifications are off on this device.</span>
                        <span x-show="state === 'needs-install'">Add Out&amp;Screen to your Home Screen, then open it from there.</span>
                        {{--
                            A refused prompt sticks for the life of the installed
                            app, and iOS only lists a web app under Settings ›
                            Notifications once it has been allowed — so there is no
                            switch to send anyone to. Reinstalling is the only reset.
                        --}}
                        <span x-show="state === 'denied' && ios">Notifications were turned down. Remove Out&amp;Screen from your Home Screen, add it again from Safari, and tap Enable.</span>
                        <span x-show="state === 'denied' && ! ios">Notifications are blocked. Allow them for this site in your browser's settings.</span>
                        <span x-show="state === 'unsupported'">This browser can't show notifications.</span>
                        <span x-show="state === 'checking'">Checking…</span>
                    </flux:subheading>

                    <div x-show="state === 'off'">
                        <flux:button variant="primary" x-on:click="enable()" ::disabled="busy">
                            Enable
                        </flux:button>
                    </div>

                    <div class="flex items-center gap-3" x-show="state === 'on'">
                        <flux:badge size="sm" color="green">On</flux:badge>
                        <flux:button
                            size="sm"
                            variant="subtle"
                            wire:click="sendTestPush"
                            wire:loading.attr="disabled"
                        >
                            Test in 2 min
                        </flux:button>
                        <flux:button size="sm" variant="subtle" x-on:click="disable()" ::disabled="busy">
                            Turn off
                        </flux:button>
                    </div>
                </div>

                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400" x-show="queued">
                    Test notification queued — it should arrive in about two minutes. Close the app to see it land.
                </p>

                <p class="mt-3 text-sm text-red-600 dark:text-red-400" x-show="error" x-text="error"></p>

                <p class="mt-2 font-mono text-xs text-zinc-400 dark:text-zinc-500" x-show="diagnostics" x-text="diagnostics"></p>
            </div>
        @else
            <flux:subheading>
                Push notifications aren't set up on this server yet.
            </flux:subheading>
        @endif
    </x-settings.layout>
</div>
