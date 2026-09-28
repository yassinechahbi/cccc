<?php

use App\Enums\CircuitStatus;
use App\Models\Circuit;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('Circuits')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'in_progress';

    #[Url]
    public string $search = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $user = auth()->user();

        $circuits = Circuit::query()
            ->with('originatingMember', 'legs.member.country')
            ->visibleTo($user)
            // Plain members only ever see circuits in progress: no status filter for them.
            ->when($user->canManageCircuits() && $this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->where('number', (int) $this->search)
                ->orWhereHas('legs.member', fn (Builder $m) => $m->search($this->search))))
            ->latest('mailed_at')
            ->latest('number')
            ->paginate(20);

        return ['circuits' => $circuits];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-4">
        <flux:heading size="xl" level="1" class="flex-1">{{ __('Circuits') }}</flux:heading>
        @can('manage-circuits')
            <flux:button variant="primary" icon="plus" :href="route('circuits.create')" wire:navigate>{{ __('New circuit') }}</flux:button>
        @endcan
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Circuit # or member')" class="sm:col-span-2" />
        @can('manage-circuits')
            <flux:select wire:model.live="status">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (CircuitStatus::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        @endcan
    </div>
    @cannot('manage-circuits')
        <flux:text>{{ __('The circuits in progress you take part in.') }}</flux:text>
    @endcannot

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">{{ __('Originating Member') }}</th>
                    <th class="px-4 py-3">{{ __('Mailed') }}</th>
                    <th class="px-4 py-3">{{ __('Progress') }}</th>
                    <th class="px-4 py-3">{{ __('Now with') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($circuits as $circuit)
                    @php($current = $circuit->currentLeg())
                    <tr wire:key="circuit-{{ $circuit->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                        <td class="px-4 py-3 font-medium">
                            <a href="{{ route('circuits.show', $circuit) }}" class="underline-offset-2 hover:underline" wire:navigate>{{ $circuit->number }}</a>
                        </td>
                        <td class="px-4 py-3">{{ $circuit->originatingMember->name }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $circuit->mailed_at->format('Y-m-d') }}</td>
                        <td class="px-4 py-3">
                            <div class="flex w-28 gap-0.5">
                                @foreach ($circuit->legs as $leg)
                                    <span class="h-2 flex-1 rounded-full {{ $leg->received_at ? 'bg-green-500' : 'bg-zinc-200 dark:bg-zinc-700' }}" title="{{ $leg->member->country?->name }}"></span>
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            @if ($current && $circuit->status === CircuitStatus::InProgress)
                                {{ __('Travelling to') }} {{ $current->member->name }} ({{ $current->member->country?->name }})
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge size="sm" :color="$circuit->status->color()">{{ $circuit->status->label() }}</flux:badge>
                            @if ($circuit->isOverdue())
                                <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-zinc-500">{{ __('No circuit yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $circuits->links() }}
</div>
