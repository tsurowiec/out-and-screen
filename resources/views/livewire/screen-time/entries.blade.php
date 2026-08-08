<?php

use App\Enums\ScreenType;
use App\Models\ScreenTimeEntry;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.app')] #[Title('Screen time')] class extends Component
{
    use WithPagination;

    /** Optional type filter, as a ScreenType value. */
    #[Url(except: '')]
    public string $filter = '';

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /**
     * The shared editor writes directly, so this just forces a re-render to
     * pick the new values up.
     */
    #[On('entry-saved')]
    public function onEntrySaved(): void {}

    public function remove(int $id): void
    {
        $this->authorize('manage-screen-time');

        ScreenTimeEntry::query()->whereKey($id)->delete();
    }

    public function dayLabel(\Illuminate\Support\Carbon $day): string
    {
        return match (true) {
            $day->isToday() => 'Today',
            $day->isYesterday() => 'Yesterday',
            default => $day->format('D j M Y'),
        };
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
            'entries' => ScreenTimeEntry::query()
                ->when($this->filter !== '', fn ($query) => $query->where('type', $this->filter))
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->paginate(25),
            'types' => ScreenType::cases(),
            'canManage' => auth()->user()->canManageScreenTime(),
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg">Screen time</flux:heading>
                <flux:subheading>
                    {{ $entries->total() }} {{ Str::plural('entry', $entries->total()) }} logged.
                    @if ($canManage) Tap one to edit it. @endif
                </flux:subheading>
            </div>

            <flux:select wire:model.live="filter" class="max-w-48">
                <flux:select.option value="">All types</flux:select.option>
                @foreach ($types as $type)
                    <flux:select.option value="{{ $type->value }}">{{ $type->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($entries->isEmpty())
            <flux:callout class="mt-5" icon="clock">
                <flux:callout.heading>Nothing logged yet</flux:callout.heading>
                <flux:callout.text>
                    Entries added from the dashboard show up here.
                </flux:callout.text>
            </flux:callout>
        @else
            {{-- Grouped per page, so a day split across pages gets a heading on both. --}}
            <div class="mt-4 flex flex-col gap-5">
                @foreach ($entries->getCollection()->groupBy(fn ($entry) => $entry->started_at->toDateString()) as $date => $dayEntries)
                    <div wire:key="day-{{ $date }}">
                        <flux:heading class="px-2">
                            {{ $this->dayLabel($dayEntries->first()->started_at) }}
                        </flux:heading>

                        <div class="mt-1 divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach ($dayEntries as $entry)
                                <div wire:key="entry-{{ $entry->id }}" class="flex items-center gap-1 py-1">
                                    <button
                                        type="button"
                                        wire:click="$dispatch('edit-entry', { id: {{ $entry->id }} })"
                                        @disabled(! $canManage)
                                        @class([
                                            'flex flex-1 items-center gap-3 rounded-lg px-2 py-1.5 text-left',
                                            'hover:bg-zinc-50 dark:hover:bg-zinc-800' => $canManage,
                                        ])
                                    >
                                        <span class="w-10 shrink-0 text-sm text-zinc-500 tabular-nums dark:text-zinc-400">
                                            {{ $entry->started_at->format('H:i') }}
                                        </span>

                                        <span class="size-3 shrink-0 rounded-full" style="background-color: {{ $entry->type->color() }}"></span>
                                        <span class="font-medium">{{ $entry->type->label() }}</span>

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
                @endforeach
            </div>

            <div class="mt-4">
                {{ $entries->links() }}
            </div>
        @endif
    </div>

    {{-- Shared entry editor, opened via the edit-entry event --}}
    <livewire:screen-time.entry-editor />
</div>
