<?php

use App\Enums\ScreenType;
use App\Models\ScreenTimeEntry;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

/**
 * Shared modal for editing a single entry. Open it from anywhere with
 * `$dispatch('edit-entry', { id: … })`; it announces `entry-saved` once the
 * write lands so the surrounding list can refresh itself.
 */
new class extends Component
{
    public ?int $entryId = null;

    public bool $open = false;

    public ?string $entryType = null;

    /** Start time being edited, as HH:MM. */
    public ?string $entryTime = null;

    public ?int $entryMinutes = null;

    /** The entry's date, shown for context but not editable here. */
    public ?string $entryDay = null;

    #[On('edit-entry')]
    public function edit(int $id): void
    {
        $this->authorize('manage-screen-time');

        $entry = $this->findEntry($id);

        $this->entryId = $entry->id;
        $this->entryType = $entry->type->value;
        $this->entryTime = $entry->started_at->format('H:i');
        $this->entryMinutes = $entry->minutes;
        $this->entryDay = $entry->started_at->isToday()
            ? 'Today'
            : $entry->started_at->format('D j M Y');
        $this->open = true;
    }

    /**
     * Change an entry's type, start time and/or duration. The day it belongs to
     * is kept as-is; only the time of day changes.
     */
    public function save(): void
    {
        $this->authorize('manage-screen-time');

        $entry = $this->findEntry($this->entryId);

        $this->validate([
            'entryType' => ['required', Rule::enum(ScreenType::class)],
            'entryTime' => ['required', 'date_format:H:i'],
            'entryMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        [$hour, $minute] = explode(':', $this->entryTime);

        $entry->update([
            'type' => ScreenType::from($this->entryType),
            'started_at' => $entry->started_at->copy()->setTime((int) $hour, (int) $minute),
            'minutes' => $this->entryMinutes,
        ]);

        $this->close();

        $this->dispatch('entry-saved');
    }

    public function close(): void
    {
        $this->open = false;
        $this->entryId = null;
        $this->entryType = null;
        $this->entryTime = null;
        $this->entryMinutes = null;
        $this->entryDay = null;
        $this->resetValidation();
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
        return ['types' => ScreenType::cases()];
    }
}; ?>

<flux:modal wire:model.self="open" class="md:w-96">
    @if ($entryId)
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">Edit entry</flux:heading>
                <flux:subheading>{{ $entryDay }}</flux:subheading>
            </div>

            <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Screen type">
                @foreach ($types as $screenType)
                    @php $selected = $entryType === $screenType->value; @endphp
                    <button
                        type="button"
                        role="radio"
                        aria-checked="{{ $selected ? 'true' : 'false' }}"
                        wire:click="$set('entryType', '{{ $screenType->value }}')"
                        @class([
                            'flex items-center justify-center gap-2 rounded-lg border-2 px-3 py-2 text-sm font-semibold transition',
                            'col-span-2' => $loop->last && $loop->count % 2 === 1,
                            'text-white' => $selected,
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

            <flux:input wire:model="entryTime" type="time" label="Started at" />

            <flux:input wire:model="entryMinutes" type="number" min="1" max="1440" label="Duration (minutes)" />

            <div class="flex flex-wrap gap-2">
                @foreach ([15, 30, 45, 60, 90, 120] as $preset)
                    <flux:button
                        size="sm"
                        type="button"
                        :variant="(int) $entryMinutes === $preset ? 'primary' : 'outline'"
                        wire:click="$set('entryMinutes', {{ $preset }})"
                    >{{ $this->formatMinutes($preset) }}</flux:button>
                @endforeach
            </div>

            <div class="flex gap-2">
                <flux:spacer />
                <flux:button type="button" variant="ghost" wire:click="close">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    @endif
</flux:modal>
