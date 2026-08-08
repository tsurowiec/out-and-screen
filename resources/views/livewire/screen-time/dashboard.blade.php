<?php

use App\Enums\ScreenType;
use App\Jobs\NotifySessionEnded;
use App\Models\ScreenTimeEntry;
use App\Models\ScreenTimeLimitOverride;
use App\Models\TripEntry;
use App\Support\DayTimeline;
use App\Support\ScreenTimeLimit;
use App\Support\TripWeek;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /**
     * The currently selected screen type. Remembered between visits so the
     * common case is a single tap on a duration button.
     */
    #[Session(key: 'screen-time.type')]
    public string $type = 'tv';

    /** The day currently being edited in the allowance modal, as Y-m-d. */
    public ?string $editingDate = null;

    public bool $editingLimit = false;

    /** The allowance being typed into the modal, in minutes. */
    public ?int $limitMinutes = null;

    /**
     * Log a session of the selected type that starts now.
     */
    public function add(int $minutes): void
    {
        $this->authorize('manage-screen-time');

        $type = ScreenType::from($this->type);

        abort_unless(in_array($minutes, config('screen-time.quick_durations'), true), 422);

        $entry = ScreenTimeEntry::query()->create([
            'type' => $type,
            'minutes' => $minutes,
            'started_at' => now(),
            'user_id' => auth()->id(),
        ]);

        NotifySessionEnded::scheduleFor($entry);

        $this->refreshData();
    }

    /**
     * Give the session that's running right now some more time.
     */
    public function extend(int $minutes): void
    {
        $this->authorize('manage-screen-time');

        abort_unless(in_array($minutes, config('screen-time.extend_durations'), true), 422);

        $active = $this->activeEntry;

        abort_if($active === null, 404);

        $active->update(['minutes' => $active->minutes + $minutes]);

        NotifySessionEnded::scheduleFor($active);

        $this->refreshData();
    }

    /**
     * End the running session now, trimming it to the time actually used.
     */
    public function stop(): void
    {
        $this->authorize('manage-screen-time');

        $active = $this->activeEntry;

        abort_if($active === null, 404);

        // Round down to the last whole minute, so the session ends now at the
        // latest — rounding up would leave it running for up to another minute.
        $elapsed = (int) floor($active->started_at->diffInSeconds(now()) / 60);

        if ($elapsed < 1) {
            // Stopped before a single minute was used, so there's nothing worth
            // recording.
            $active->delete();
        } else {
            $active->update(['minutes' => $elapsed]);
        }

        $this->refreshData();
    }

    public function remove(int $id): void
    {
        $this->authorize('manage-screen-time');

        ScreenTimeEntry::query()->whereKey($id)->delete();

        $this->refreshData();
    }

    /**
     * The shared entry editor writes straight to the database, so the cached
     * day data has to be dropped once it reports back.
     */
    #[On('entry-saved')]
    public function onEntrySaved(): void
    {
        $this->refreshData();
    }

    /**
     * Open the allowance editor for one of the visible days.
     */
    public function editLimit(string $date): void
    {
        $this->authorize('manage-screen-time');

        $day = $this->visibleDay($date);

        $this->editingDate = $day->toDateString();
        $this->limitMinutes = ScreenTimeLimit::for($day);
        $this->editingLimit = true;
    }

    /**
     * Replace the scheduled allowance for the day being edited.
     */
    public function saveLimit(): void
    {
        $this->authorize('manage-screen-time');

        $day = $this->visibleDay($this->editingDate);

        $this->validate([
            'limitMinutes' => ['required', 'integer', 'min:0', 'max:1440'],
        ]);

        ScreenTimeLimitOverride::query()->updateOrCreate(
            ['date' => $day->toDateString()],
            ['minutes' => $this->limitMinutes],
        );

        $this->closeLimit();
    }

    /**
     * Drop the override and fall back to the schedule.
     */
    public function resetLimit(): void
    {
        $this->authorize('manage-screen-time');

        $day = $this->visibleDay($this->editingDate);

        ScreenTimeLimitOverride::query()
            ->whereDate('date', $day)
            ->delete();

        $this->closeLimit();
    }

    public function closeLimit(): void
    {
        $this->editingLimit = false;
        $this->editingDate = null;
        $this->limitMinutes = null;
        $this->resetValidation();

        $this->refreshData();
    }

    /**
     * Resolve a date string to one of the days actually on screen, so the
     * allowance editor can't be pointed at an arbitrary date.
     */
    protected function visibleDay(?string $date): Carbon
    {
        $day = collect($this->timelines)
            ->first(fn (DayTimeline $timeline) => $timeline->day->toDateString() === $date);

        abort_if($day === null, 404);

        return $day->day;
    }

    /**
     * Drop the cached day data so the next render reflects a write.
     */
    protected function refreshData(): void
    {
        unset($this->timelines, $this->today, $this->breakdown, $this->todayEntries, $this->activeEntry);
    }

    /**
     * The session that is running right now, if any — an entry that has started
     * but whose duration hasn't elapsed yet. If sessions overlap, the one that
     * started most recently wins.
     */
    #[Computed]
    public function activeEntry(): ?ScreenTimeEntry
    {
        return ScreenTimeEntry::query()
            ->where('started_at', '<=', now())
            ->where('started_at', '>=', now()->subDay())
            ->orderByDesc('started_at')
            ->get()
            ->first(fn (ScreenTimeEntry $entry) => $entry->endedAt()->isAfter(now()));
    }

    /**
     * One timeline per visible day, most recent first.
     *
     * @return array<int, DayTimeline>
     */
    #[Computed]
    public function timelines(): array
    {
        $days = config('screen-time.days_shown');
        $from = today()->subDays($days - 1);

        $entries = ScreenTimeEntry::query()
            ->betweenDays($from, today())
            ->orderBy('started_at')
            ->get()
            ->groupBy(fn (ScreenTimeEntry $entry) => $entry->started_at->toDateString());

        $overrides = ScreenTimeLimitOverride::query()
            ->betweenDays($from, today())
            ->pluck('minutes', 'date')
            ->mapWithKeys(fn (int $minutes, string $date) => [Carbon::parse($date)->toDateString() => $minutes]);

        return collect(range(0, $days - 1))
            ->map(function (int $offset) use ($entries, $overrides) {
                $day = today()->subDays($offset);
                $key = $day->toDateString();

                return DayTimeline::build($day, $entries->get($key, collect()), $overrides->get($key));
            })
            ->all();
    }

    #[Computed]
    public function today(): DayTimeline
    {
        return $this->timelines[0];
    }

    /**
     * Minutes used today, per type.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function breakdown(): array
    {
        return collect($this->today->segments)
            ->groupBy(fn (array $segment) => $segment['type']->value)
            ->map(fn ($segments) => (int) $segments->sum('minutes'))
            ->all();
    }

    /**
     * Hours out on trips so far this week, where the week runs Saturday to
     * Friday. Trips are logged on their own page; this is a read-only glance.
     */
    #[Computed]
    public function tripMinutesThisWeek(): int
    {
        return (int) TripEntry::query()
            ->betweenDays(TripWeek::startFor(today()), TripWeek::endFor(today()))
            ->sum('minutes');
    }

    /**
     * @return Collection<int, ScreenTimeEntry>
     */
    #[Computed]
    public function todayEntries()
    {
        return ScreenTimeEntry::query()
            ->onDay(today())
            ->orderByDesc('started_at')
            ->get();
    }

    public function formatMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes}m";
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? "{$hours}h" : "{$hours}h {$rest}m";
    }

    public function with(): array
    {
        return [
            'types' => ScreenType::cases(),
            'durations' => config('screen-time.quick_durations'),
            'extensions' => config('screen-time.extend_durations'),
            'canManage' => auth()->user()->canManageScreenTime(),
            'tripWeekLabel' => TripWeek::label(TripWeek::startFor(today())),
            'hourTicks' => DayTimeline::hourTicks(),
            'startHour' => config('screen-time.day_start_hour'),
            'endHour' => config('screen-time.day_end_hour'),
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    @php
        $used = $this->today->totalMinutes;
        $limit = $this->today->limitMinutes;
        $span = $endHour - $startHour;
    @endphp

    {{-- Countdown for the session that's running right now --}}
    @if ($this->activeEntry)
        @php
            $active = $this->activeEntry;
            $secondsLeft = max(0, (int) round(now()->diffInSeconds($active->endedAt(), false)));
        @endphp

        <div
            wire:key="active-{{ $active->id }}-{{ $active->updated_at->timestamp }}"
            class="rounded-xl border-2 p-6 text-white"
            style="background-color: {{ $active->type->color() }}; border-color: {{ $active->type->color() }}"
            x-data="{
                total: {{ $active->minutes * 60 }},
                remaining: {{ $secondsLeft }},
                // Anchor to a wall-clock deadline so the countdown stays honest
                // if the tab sleeps or the machine's clock drifts.
                deadline: Date.now() + {{ $secondsLeft }} * 1000,
                timer: null,
                get clock() {
                    let minutes = Math.floor(this.remaining / 60)
                    let seconds = this.remaining % 60
                    return minutes + ':' + String(seconds).padStart(2, '0')
                },
                get elapsedPercent() {
                    return this.total ? (1 - this.remaining / this.total) * 100 : 100
                },
                tick() {
                    this.remaining = Math.max(0, Math.round((this.deadline - Date.now()) / 1000))

                    if (this.remaining === 0) {
                        clearInterval(this.timer)
                        $wire.$refresh()
                    }
                },
            }"
            x-init="tick(); timer = setInterval(() => tick(), 1000)"
            x-on:destroy="clearInterval(timer)"
        >
            <div class="flex items-center gap-2 text-sm font-semibold uppercase tracking-wide opacity-90">
                <flux:icon :icon="$active->type->icon()" variant="micro" />
                {{ $active->type->label() }} in progress
            </div>

            <div class="mt-2 flex flex-wrap items-baseline justify-between gap-3">
                <div class="text-6xl font-bold tabular-nums sm:text-7xl" x-text="clock">
                    {{ intdiv($secondsLeft, 60) }}:{{ str_pad($secondsLeft % 60, 2, '0', STR_PAD_LEFT) }}
                </div>
                <div class="text-sm font-medium opacity-90 tabular-nums">
                    {{ $active->started_at->format('H:i') }}–{{ $active->endedAt()->format('H:i') }}
                    &middot; {{ $this->formatMinutes($active->minutes) }} session
                </div>
            </div>

            <div class="mt-4 h-2 w-full overflow-hidden rounded-full bg-black/20">
                <div class="h-full bg-white/90 transition-[width] duration-1000 ease-linear" :style="`width: ${elapsedPercent}%`"></div>
            </div>

            @if ($canManage)
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium opacity-90">Extend by</span>
                @foreach ($extensions as $extension)
                    {{-- Only the shortest extension fits on a phone held upright. --}}
                    <button
                        type="button"
                        wire:click="extend({{ $extension }})"
                        wire:loading.attr="disabled"
                        @class([
                            'rounded-lg border border-white/40 bg-white/15 px-4 py-2 text-sm font-bold text-white transition hover:bg-white/25 disabled:opacity-50',
                            'hidden sm:block landscape:block' => ! $loop->first,
                        ])
                    >
                        +{{ $extension }} min
                    </button>
                @endforeach

                <flux:spacer />

                <button
                    type="button"
                    wire:click="stop"
                    wire:loading.attr="disabled"
                    wire:confirm="End this session now?"
                    class="rounded-lg border border-white/40 bg-white/15 px-4 py-2 text-sm font-bold text-white transition hover:bg-white/25 disabled:opacity-50"
                >
                    Stop
                </button>
            </div>
            @endif
        </div>
    @endif

    {{-- Today's budget --}}
    <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <div class="flex items-center gap-2">
                <flux:heading size="lg">Today</flux:heading>
                @if ($this->today->limitIsOverridden)
                    <flux:badge size="sm" color="amber">Override</flux:badge>
                @endif
            </div>
            <div class="flex items-baseline gap-2">
                <div @class([
                    'flex items-baseline gap-1.5',
                    'text-red-600 dark:text-red-400' => $this->today->isOverLimit(),
                    'text-zinc-700 dark:text-zinc-200' => ! $this->today->isOverLimit(),
                ])>
                    <span class="text-3xl font-bold tabular-nums">
                        {{ $this->formatMinutes($used > $limit ? $used - $limit : $this->today->remainingMinutes()) }}
                    </span>
                    <span class="text-sm font-medium">{{ $used > $limit ? ' over' : ' left' }}</span>
                </div>
            </div>
        </div>

        {{-- Budget bar, filled by type in the order the time was used --}}
        <div class="mt-4 flex h-4 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
            @foreach ($types as $type)
                @php $typeMinutes = $this->breakdown[$type->value] ?? 0; @endphp
                @if ($typeMinutes > 0)
                    <div
                        style="width: {{ min(100, $typeMinutes / max($limit, $used) * 100) }}%; background-color: {{ $type->color() }}"
                        title="{{ $type->label() }}: {{ $this->formatMinutes($typeMinutes) }}"
                    ></div>
                @endif
            @endforeach
        </div>

        <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1">
            @foreach ($types as $type)
                <div class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                    <span class="size-2.5 rounded-full" style="background-color: {{ $type->color() }}"></span>
                    {{ $type->label() }}
                    <span class="tabular-nums">{{ $this->formatMinutes($this->breakdown[$type->value] ?? 0) }}</span>
                </div>
            @endforeach

            <flux:spacer />

            {{-- The allowance caption doubles as the way in to editing it --}}
            @if ($canManage)
                <button
                    type="button"
                    data-allowance-edit
                    wire:click="editLimit('{{ $this->today->day->toDateString() }}')"
                    class="flex items-center gap-1 text-sm text-zinc-400 underline decoration-dotted underline-offset-4 transition hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300"
                >
                    {{ $this->today->limitReason() }} <span class="tabular-nums">({{ $this->formatMinutes($limit) }})</span>
                    <flux:icon icon="pencil-square" variant="micro" />
                </button>
            @else
                <div class="text-sm text-zinc-400 dark:text-zinc-500">
                    {{ $this->today->limitReason() }} <span class="tabular-nums">({{ $this->formatMinutes($limit) }})</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Quick add --}}
    @if ($canManage)
    <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <flux:heading size="lg">Add screen time</flux:heading>
        <flux:subheading>Pick what's starting, then how long it runs for.</flux:subheading>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-5" role="radiogroup" aria-label="Screen type">
            @foreach ($types as $screenType)
                @php $selected = $this->type === $screenType->value; @endphp
                <button
                    type="button"
                    role="radio"
                    aria-checked="{{ $selected ? 'true' : 'false' }}"
                    wire:click="$set('type', '{{ $screenType->value }}')"
                    @class([
                        'flex items-center justify-center gap-2 rounded-xl border-2 px-4 py-4 text-base font-semibold transition',
                        // An odd number of types leaves a gap in the two-column
                        // phone layout, so the last one fills the row.
                        'max-sm:col-span-2' => $loop->last && $loop->count % 2 === 1,
                        'text-white shadow-sm' => $selected,
                        'border-zinc-200 text-zinc-700 hover:border-zinc-300 dark:border-zinc-700 dark:text-zinc-300 dark:hover:border-zinc-600' => ! $selected,
                    ])
                    @style([
                        "background-color: {$screenType->color()}; border-color: {$screenType->color()}" => $selected,
                    ])
                >
                    <flux:icon :icon="$screenType->icon()" variant="micro" />
                    {{ $screenType->label() }}
                </button>
            @endforeach
        </div>

        <div class="mt-4 grid grid-cols-3 gap-3">
            @foreach ($durations as $minutes)
                <button
                    type="button"
                    wire:click="add({{ $minutes }})"
                    wire:loading.attr="disabled"
                    class="rounded-xl bg-zinc-900 px-4 py-6 text-xl font-bold text-white transition hover:bg-zinc-700 disabled:opacity-50 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                >
                    {{ $this->formatMinutes($minutes) }}
                </button>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Trips, at a glance: the week's total, with the page itself a tap away --}}
    <a
        href="{{ route('trips') }}"
        wire:navigate
        class="block rounded-xl border border-zinc-200 p-5 transition hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600"
    >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <flux:heading size="lg">Trips this week</flux:heading>

            <div class="flex items-baseline gap-1.5 text-zinc-700 dark:text-zinc-200">
                <span class="text-3xl font-bold tabular-nums">
                    {{ $this->formatMinutes($this->tripMinutesThisWeek) }}
                </span>
                <span class="text-sm font-medium">out</span>
            </div>
        </div>

        <div class="mt-1 text-sm text-zinc-400 dark:text-zinc-500">{{ $tripWeekLabel }}</div>
    </a>

    {{-- Day timelines: too cramped on a phone held upright, so portrait phones skip them. --}}
    <div class="hidden rounded-xl border border-zinc-200 p-5 sm:block landscape:block dark:border-zinc-700">
        <flux:heading size="lg">Last {{ count($this->timelines) }} days</flux:heading>
        <flux:subheading>{{ sprintf('%02d:00', $startHour) }} to {{ sprintf('%02d:00', $endHour) }}</flux:subheading>

        <div class="mt-5 space-y-3">
            {{-- Hour axis --}}
            <div class="flex items-center gap-3">
                <div class="w-16 shrink-0 sm:w-24"></div>
                <div class="relative h-4 flex-1">
                    @foreach ($hourTicks as $hour)
                        <span
                            class="absolute -translate-x-1/2 text-[10px] tabular-nums text-zinc-400 dark:text-zinc-500"
                            style="left: {{ ($hour - $startHour) / $span * 100 }}%"
                        >{{ $hour }}</span>
                    @endforeach
                </div>
                <div class="w-24 shrink-0"></div>
            </div>

            @foreach ($this->timelines as $timeline)
                <div class="flex items-center gap-3" wire:key="day-{{ $timeline->day->toDateString() }}">
                    <div class="flex w-16 shrink-0 items-center gap-1 text-sm text-zinc-600 sm:w-24 dark:text-zinc-400">
                        <span>{{ $timeline->day->isToday() ? 'Today' : $timeline->day->format('D j M') }}</span>
                        @if ($timeline->limitIsOverridden)
                            <span class="size-1.5 rounded-full bg-amber-500" title="Manual override"></span>
                        @endif
                    </div>

                    <div class="relative h-7 flex-1 overflow-hidden rounded-lg bg-zinc-200 dark:bg-zinc-700">
                        {{-- Hour gridlines --}}
                        @foreach ($hourTicks as $hour)
                            @if ($hour > $startHour && $hour < $endHour)
                                <span
                                    class="absolute inset-y-0 w-px bg-white/50 dark:bg-black/20"
                                    style="left: {{ ($hour - $startHour) / $span * 100 }}%"
                                ></span>
                            @endif
                        @endforeach

                        @foreach ($timeline->segments as $segment)
                            <div
                                class="absolute inset-y-0"
                                style="left: {{ $segment['left'] }}%; width: {{ $segment['width'] }}%; background-color: {{ $segment['type']->color() }}"
                                title="{{ $segment['type']->label() }} &middot; {{ $segment['from'] }}–{{ $segment['to'] }} ({{ $this->formatMinutes($segment['minutes']) }})"
                            ></div>
                        @endforeach
                    </div>

                    <button
                        type="button"
                        wire:click="editLimit('{{ $timeline->day->toDateString() }}')"
                        @disabled(! $canManage)
                        title="{{ $timeline->limitReason() }} allowance: {{ $this->formatMinutes($timeline->limitMinutes) }}"
                        @class([
                            'w-24 shrink-0 rounded text-right text-sm tabular-nums',
                            'hover:underline' => $canManage,
                            'font-semibold text-red-600 dark:text-red-400' => $timeline->isOverLimit(),
                            'text-zinc-500 dark:text-zinc-400' => ! $timeline->isOverLimit(),
                        ])
                    >
                        {{ $timeline->totalMinutes > 0 ? $this->formatMinutes($timeline->totalMinutes) : '—' }}
                        <span class="text-zinc-400 dark:text-zinc-500">/ {{ $this->formatMinutes($timeline->limitMinutes) }}</span>
                    </button>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Today's entries, so a mistap can be fixed or undone --}}
    @if ($this->todayEntries->isNotEmpty())
        <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
            <flux:heading size="lg">Today's entries</flux:heading>
            @if ($canManage)
                <flux:subheading>Tap an entry to change when it started or how long it ran.</flux:subheading>
            @endif

            <div class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach ($this->todayEntries as $entry)
                    <div class="flex items-center gap-1 py-1" wire:key="entry-{{ $entry->id }}">
                        <button
                            type="button"
                            wire:click="$dispatch('edit-entry', { id: {{ $entry->id }} })"
                            @disabled(! $canManage)
                            @class([
                                'flex flex-1 items-center gap-3 rounded-lg px-2 py-1.5 text-left',
                                'hover:bg-zinc-50 dark:hover:bg-zinc-800' => $canManage,
                            ])
                        >
                            <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $entry->type->color() }}"></span>
                            <span class="font-medium">{{ $entry->type->label() }}</span>
                            <span class="text-sm text-zinc-500 tabular-nums dark:text-zinc-400">
                                {{ $entry->started_at->format('H:i') }}
                            </span>
                            <flux:spacer />
                            <span class="text-sm tabular-nums">{{ $this->formatMinutes($entry->minutes) }}</span>
                            @if ($canManage)
                                <flux:icon icon="pencil-square" variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                            @endif
                        </button>

                        @if ($canManage)
                            <flux:button
                                size="xs"
                                variant="subtle"
                                icon="trash"
                                wire:click="remove({{ $entry->id }})"
                                wire:confirm="Remove this entry?"
                            />
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Shared entry editor, opened via the edit-entry event --}}
    <livewire:screen-time.entry-editor />

    {{-- Allowance override --}}
    <flux:modal wire:model.self="editingLimit" class="md:w-96">
        @if ($editingDate)
            @php
                $editing = collect($this->timelines)->firstWhere(fn ($t) => $t->day->toDateString() === $editingDate);
                $scheduled = \App\Support\ScreenTimeLimit::default($editing->day);
            @endphp

            <form wire:submit="saveLimit" class="space-y-6">
                <div>
                    <flux:heading size="lg">
                        Allowance for {{ $editing->day->isToday() ? 'today' : $editing->day->format('D j M') }}
                    </flux:heading>
                    <flux:subheading>
                        {{ \App\Support\ScreenTimeLimit::reason($editing->day) }} &middot;
                        normally {{ $this->formatMinutes($scheduled) }}
                    </flux:subheading>
                </div>

                <div class="flex flex-wrap gap-2">
                    @foreach ([0, 60, 90, 120, 150, 180, 240] as $preset)
                        <flux:button
                            size="sm"
                            type="button"
                            :variant="$limitMinutes === $preset ? 'primary' : 'outline'"
                            wire:click="$set('limitMinutes', {{ $preset }})"
                        >{{ $this->formatMinutes($preset) }}</flux:button>
                    @endforeach
                </div>

                <flux:input
                    wire:model="limitMinutes"
                    type="number"
                    min="0"
                    max="1440"
                    label="Minutes"
                    description="Applies to this day only."
                />

                <div class="flex gap-2">
                    <flux:button type="button" variant="subtle" wire:click="resetLimit">
                        Use default
                    </flux:button>
                    <flux:spacer />
                    <flux:button type="button" variant="ghost" wire:click="closeLimit">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
        @endif
    </flux:modal>
</div>
