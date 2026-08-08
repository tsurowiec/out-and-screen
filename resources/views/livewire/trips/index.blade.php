<?php

use App\Models\TripEntry;
use App\Support\TripWeek;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] #[Title('Trips')] class extends Component
{
    use WithPagination;

    /** The trip being logged or edited, as Y-m-d. */
    public string $date = '';

    /** Duration being typed in, in hours — decimals allowed, e.g. "2.5". */
    public string $hours = '';

    public string $description = '';

    /** Set while an existing trip is open in the editor. */
    public ?int $editingId = null;

    public bool $editing = false;

    public function mount(): void
    {
        $this->resetForm();
    }

    /**
     * Open the editor on a blank trip, dated today.
     */
    public function create(): void
    {
        $this->authorize('manage-trips');

        $this->resetForm();
        $this->resetValidation();
        $this->editing = true;
    }

    /**
     * Open the editor on an existing trip.
     */
    public function edit(int $id): void
    {
        $this->authorize('manage-trips');

        $trip = $this->findTrip($id);

        $this->editingId = $trip->id;
        $this->date = $trip->date->toDateString();
        $this->hours = $this->hoursInput($trip->minutes);
        $this->description = $trip->description ?? '';
        $this->resetValidation();
        $this->editing = true;
    }

    /**
     * Log a new trip, or write back the one open in the editor.
     */
    public function save(): void
    {
        $this->authorize('manage-trips');

        $validated = $this->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'hours' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $attributes = [
            'date' => $validated['date'],
            'minutes' => (int) round((float) $validated['hours'] * 60),
            'description' => trim($this->description) ?: null,
        ];

        if ($this->editingId !== null) {
            $this->findTrip($this->editingId)->update($attributes);
        } else {
            TripEntry::query()->create($attributes + ['user_id' => auth()->id()]);
        }

        $this->close();
    }

    public function remove(int $id): void
    {
        $this->authorize('manage-trips');

        TripEntry::query()->whereKey($id)->delete();
    }

    public function close(): void
    {
        $this->editing = false;
        $this->resetForm();
        $this->resetValidation();
    }

    /**
     * Fill the form in for a fresh entry: today's date, nothing else.
     */
    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->date = today()->toDateString();
        $this->hours = '';
        $this->description = '';
    }

    protected function findTrip(int $id): TripEntry
    {
        $trip = TripEntry::query()->find($id);

        abort_if($trip === null, 404);

        return $trip;
    }

    /**
     * Minutes as a duration to type back into the hours field, without the
     * trailing zeros of a plain division.
     */
    public function hoursInput(int $minutes): string
    {
        return rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');
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

    /**
     * Trips grouped into Saturday-to-Friday weeks, newest week first.
     *
     * Weeks are the unit of pagination rather than the entries, so a week's
     * total is never split across two pages. The current week is always shown,
     * even before anything has been logged into it.
     *
     * @return LengthAwarePaginator<int, array{start: Carbon, end: Carbon, label: string, current: bool, minutes: int, entries: Collection<int, TripEntry>}>
     */
    protected function weeks(): LengthAwarePaginator
    {
        $currentWeek = TripWeek::startFor(today());

        $starts = TripEntry::query()
            ->orderByDesc('date')
            ->pluck('date')
            ->map(fn ($date) => TripWeek::startFor(Carbon::parse($date)))
            ->prepend($currentWeek)
            ->unique(fn (Carbon $start) => $start->toDateString())
            ->sortByDesc(fn (Carbon $start) => $start->toDateString())
            ->values();

        $perPage = (int) config('trips.weeks_per_page');
        $page = $this->getPage();
        $visible = $starts->forPage($page, $perPage)->values();

        $entries = $visible->isEmpty()
            ? collect()
            : TripEntry::query()
                ->betweenDays($visible->last(), TripWeek::endFor($visible->first()))
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get()
                ->groupBy(fn (TripEntry $trip) => $trip->weekStart()->toDateString());

        $items = $visible->map(function (Carbon $start) use ($entries) {
            $weekEntries = $entries->get($start->toDateString(), collect());

            return [
                'start' => $start,
                'end' => TripWeek::endFor($start),
                'label' => TripWeek::label($start),
                'prefix' => TripWeek::prefix($start),
                'current' => TripWeek::isCurrent($start),
                'minutes' => (int) $weekEntries->sum('minutes'),
                'entries' => $weekEntries,
            ];
        })->all();

        return new LengthAwarePaginator($items, $starts->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    public function with(): array
    {
        return [
            'weeks' => $this->weeks(),
            'quickHours' => config('trips.quick_hours'),
            'canManage' => auth()->user()->canManageTrips(),
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg">Trips</flux:heading>
                <flux:subheading>
                    Hours spent out, totalled per week — Saturday to Friday.
                </flux:subheading>
            </div>

            @if ($canManage)
                <flux:button variant="primary" icon="plus" wire:click="create">
                    Add trip
                </flux:button>
            @endif
        </div>

        <div class="mt-5 flex flex-col gap-5">
            @foreach ($weeks as $week)
                <div wire:key="week-{{ $week['start']->toDateString() }}">
                    <div class="flex items-baseline justify-between gap-3 px-2">
                        <flux:heading class="flex min-w-0 items-baseline gap-2">
                            @if ($week['prefix'])
                                <span>{{ $week['prefix'] }}</span>
                            @endif
                            <span @class([
                                'truncate',
                                'text-xs font-normal text-zinc-500 dark:text-zinc-400' => $week['prefix'],
                            ])>{{ $week['label'] }}</span>
                        </flux:heading>

                        <span class="shrink-0 text-lg font-semibold tabular-nums">
                            {{ $this->formatMinutes($week['minutes']) }}
                        </span>
                    </div>

                    @if ($week['entries']->isEmpty())
                        <div class="mt-1 px-2 py-2 text-sm text-zinc-500 dark:text-zinc-400">
                            No trips logged.
                        </div>
                    @else
                        <div class="mt-1 divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($week['entries'] as $trip)
                                <div wire:key="trip-{{ $trip->id }}" class="flex items-center gap-1 py-1">
                                    <button
                                        type="button"
                                        wire:click="edit({{ $trip->id }})"
                                        @disabled(! $canManage)
                                        @class([
                                            'flex min-w-0 flex-1 items-center gap-3 rounded-lg px-2 py-1.5 text-left',
                                            'hover:bg-zinc-50 dark:hover:bg-zinc-800' => $canManage,
                                        ])
                                    >
                                        <span class="w-14 shrink-0 text-sm text-zinc-500 tabular-nums dark:text-zinc-400">
                                            {{ $trip->date->format('D j') }}
                                        </span>

                                        <span class="truncate">
                                            @if ($trip->description)
                                                {{ $trip->description }}
                                            @else
                                                <span class="text-zinc-400 dark:text-zinc-500">Trip</span>
                                            @endif
                                        </span>

                                        <flux:spacer />

                                        <span class="shrink-0 text-sm font-medium tabular-nums">
                                            {{ $this->formatMinutes($trip->minutes) }}
                                        </span>
                                        @if ($canManage)
                                            <flux:icon icon="pencil-square" variant="micro" class="shrink-0 text-zinc-400 dark:text-zinc-500" />
                                        @endif
                                    </button>

                                    @if ($canManage)
                                        <flux:button
                                            size="xs"
                                            variant="subtle"
                                            icon="trash"
                                            wire:click="remove({{ $trip->id }})"
                                            wire:confirm="Remove this trip?"
                                        />
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($weeks->hasPages())
            <div class="mt-4">
                {{ $weeks->links() }}
            </div>
        @endif
    </div>

    <flux:modal wire:model.self="editing" class="md:w-96">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit trip' : 'Add trip' }}</flux:heading>
                <flux:subheading>Counts towards the week the date falls in.</flux:subheading>
            </div>

            <flux:input wire:model="date" type="date" label="Date" />

            <flux:input wire:model="hours" type="number" step="0.25" min="0.25" max="24" label="Duration (hours)" />

            <div class="flex flex-wrap gap-2">
                @foreach ($quickHours as $preset)
                    <flux:button
                        size="sm"
                        type="button"
                        :variant="(float) $hours === (float) $preset ? 'primary' : 'outline'"
                        wire:click="$set('hours', '{{ $preset }}')"
                    >{{ $preset }}h</flux:button>
                @endforeach
            </div>

            <flux:input wire:model="description" label="Description" placeholder="Optional" />

            <div class="flex gap-2">
                <flux:spacer />
                <flux:button type="button" variant="ghost" wire:click="close">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
