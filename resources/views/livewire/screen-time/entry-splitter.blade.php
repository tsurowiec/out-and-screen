<?php

use App\Enums\ScreenType;
use App\Jobs\NotifySessionEnded;
use App\Models\ScreenTimeEntry;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * Shared modal for cutting one entry into two back-to-back entries — an hour of
 * phone that was really 20 minutes of phone and 40 of YouTube. Open it with
 * `$dispatch('split-entry', { id: … })`; it announces `entry-saved` once the
 * write lands so the surrounding list can refresh itself.
 */
new class extends Component
{
    /** How coarse the slider is — nobody splits an hour at 37 minutes. */
    public const STEP_MINUTES = 5;

    public ?int $entryId = null;

    public bool $open = false;

    /** Type of the first (earlier) half. */
    public ?string $firstType = null;

    /** Type of the second (later) half. */
    public ?string $secondType = null;

    /** How many of the entry's minutes stay with the first half. */
    public ?int $firstMinutes = null;

    /** The entry's full duration, so the slider knows its range. */
    public ?int $totalMinutes = null;

    /** Start time of the entry being split, as HH:MM. */
    public ?string $startedAt = null;

    #[On('split-entry')]
    public function openFor(int $id): void
    {
        $this->authorize('manage-screen-time');

        $entry = $this->findEntry($id);

        // The slider moves in five-minute steps, so anything shorter than two
        // steps has nowhere to be cut.
        abort_if($entry->minutes < self::STEP_MINUTES * 2, 422);

        $this->entryId = $entry->id;
        $this->firstType = $entry->type->value;
        $this->secondType = $entry->type->value;
        $this->totalMinutes = $entry->minutes;
        // Halfway, snapped onto the slider's steps.
        $this->firstMinutes = min(
            (int) round($entry->minutes / 2 / self::STEP_MINUTES) * self::STEP_MINUTES,
            self::lastStop($entry->minutes),
        );
        $this->startedAt = $entry->started_at->format('H:i');
        $this->open = true;
    }

    /**
     * Shorten the original entry to the first half and log the remainder as a
     * second entry that picks up exactly where the first one ends.
     */
    public function save(): void
    {
        $this->authorize('manage-screen-time');

        $entry = $this->findEntry($this->entryId);

        // The slider's range depends on the entry, so it can't be a static rule.
        $this->validate([
            'firstType' => ['required', Rule::enum(ScreenType::class)],
            'secondType' => ['required', Rule::enum(ScreenType::class)],
            'firstMinutes' => ['required', 'integer', 'min:1', 'max:'.($entry->minutes - 1)],
        ]);

        $first = (int) $this->firstMinutes;

        $second = ScreenTimeEntry::query()->create([
            'type' => ScreenType::from($this->secondType),
            'minutes' => $entry->minutes - $first,
            'started_at' => $entry->started_at->copy()->addMinutes($first),
            'user_id' => $entry->user_id,
        ]);

        $entry->update([
            'type' => ScreenType::from($this->firstType),
            'minutes' => $first,
        ]);

        // Either half can still be running, and the original's deadline has
        // moved, so both need their announcement re-queued.
        NotifySessionEnded::scheduleFor($entry);
        NotifySessionEnded::scheduleFor($second);

        $this->close();

        $this->dispatch('entry-saved');
    }

    public function close(): void
    {
        $this->open = false;
        $this->entryId = null;
        $this->firstType = null;
        $this->secondType = null;
        $this->firstMinutes = null;
        $this->totalMinutes = null;
        $this->startedAt = null;
        $this->resetValidation();
    }

    /**
     * The furthest the slider can go: the last five-minute stop that still
     * leaves something for the second entry.
     */
    public static function lastStop(int $totalMinutes): int
    {
        return intdiv($totalMinutes - 1, self::STEP_MINUTES) * self::STEP_MINUTES;
    }

    protected function findEntry(?int $id): ScreenTimeEntry
    {
        $entry = ScreenTimeEntry::query()->find($id);

        abort_if($entry === null, 404);

        return $entry;
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
            'step' => self::STEP_MINUTES,
        ];
    }
}; ?>

<flux:modal wire:model.self="open" class="md:w-96">
    @if ($entryId && $totalMinutes)
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">Split entry</flux:heading>
                <flux:subheading>
                    {{ $this->formatMinutes($totalMinutes) }} from {{ $startedAt }}, cut into two.
                </flux:subheading>
            </div>

            {{-- The slider drives the labels locally so dragging stays smooth;
                 the server only hears about the value once it's let go of. --}}
            {{-- Keyed on the entry so reopening for a different one rebuilds
                 the Alpine state instead of morphing the old value onto it. --}}
            <div
                wire:key="split-{{ $entryId }}"
                x-data="{ first: @js((int) $firstMinutes), total: @js((int) $totalMinutes) }"
                class="space-y-4"
            >
                <div class="grid grid-cols-2 gap-3">
                    <flux:select wire:model="firstType" label="First">
                        @foreach ($types as $screenType)
                            <flux:select.option value="{{ $screenType->value }}">
                                {{ $screenType->label() }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:select wire:model="secondType" label="Then">
                        @foreach ($types as $screenType)
                            <flux:select.option value="{{ $screenType->value }}">
                                {{ $screenType->label() }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div>
                    <div class="flex items-baseline justify-between text-sm font-medium tabular-nums">
                        <span x-text="first + 'm'"></span>
                        <span x-text="(total - first) + 'm'"></span>
                    </div>

                    <input
                        type="range"
                        min="{{ $step }}"
                        max="{{ $this->lastStop((int) $totalMinutes) }}"
                        step="{{ $step }}"
                        x-model.number="first"
                        @change="$wire.set('firstMinutes', first)"
                        aria-label="Minutes in the first entry"
                        class="mt-2 w-full accent-zinc-900 dark:accent-white"
                    />

                    <flux:error name="firstMinutes" />
                </div>
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:button type="button" variant="ghost" wire:click="close">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Split</flux:button>
            </div>
        </form>
    @endif
</flux:modal>
