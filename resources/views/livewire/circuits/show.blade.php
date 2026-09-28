<?php

use App\Enums\CircuitStatus;
use App\Enums\CoverQuality;
use App\Models\Circuit;
use App\Models\CircuitLeg;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public int $circuitId;

    #[Locked]
    public ?int $editingLegId = null;

    public string $receivedAt = '';
    public ?int $quality = null;
    public string $mailedAt = '';
    public string $comment = '';

    public function mount(Circuit $circuit): void
    {
        Gate::authorize('view-circuit', $circuit->load('legs'));
        $this->circuitId = $circuit->id;
    }

    public function with(): array
    {
        $circuit = $this->circuit();

        return [
            'circuit' => $circuit,
            'canEdit' => Gate::allows('edit-circuit', $circuit),
            'current' => $circuit->currentLeg(),
        ];
    }

    public function rendering(View $view): void
    {
        $view->title(__('Circuit #:number', ['number' => $this->circuit()->number]));
    }

    /** Open the entry form for a member who reported by mail, phone or on the returned paper form. */
    public function edit(int $legId): void
    {
        $leg = $this->editableLeg($legId);

        $this->editingLegId = $leg->id;
        $this->receivedAt = $leg->received_at?->toDateString() ?? '';
        $this->quality = $leg->quality?->value;
        $this->mailedAt = $leg->mailed_at?->toDateString() ?? '';
        $this->comment = $leg->comment ?? '';
        $this->resetValidation();
    }

    public function cancelEdit(): void
    {
        $this->editingLegId = null;
    }

    public function save(): void
    {
        $leg = $this->editableLeg($this->editingLegId);

        $this->validate([
            'receivedAt' => ['nullable', 'date', 'before_or_equal:today', 'required_with:mailedAt'],
            'quality' => ['nullable', Rule::enum(CoverQuality::class)],
            'mailedAt' => ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:receivedAt'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $quality = CoverQuality::tryFrom((int) $this->quality);

        if ($this->receivedAt && ! $leg->received_at) {
            $leg->recordReception($this->receivedAt, $quality, $this->comment ?: null, 'proxy', auth()->user());
        } else {
            // Correction: no new notifications.
            $leg->update(['received_at' => $this->receivedAt ?: null, 'quality' => $quality, 'comment' => $this->comment ?: null]);
        }

        if (! $leg->is_return) {
            if ($this->mailedAt && ! $leg->mailed_at) {
                $leg->recordMailing($this->mailedAt, 'proxy', auth()->user());
            } else {
                $leg->update(['mailed_at' => $this->mailedAt ?: null]);
            }
        }

        $leg->circuit->completeIfReturned();
        $this->editingLegId = null;
    }

    public function setStatus(string $status): void
    {
        $circuit = $this->circuit();
        Gate::authorize('edit-circuit', $circuit);

        $circuit->update(['status' => CircuitStatus::from($status)]);
    }

    private function circuit(): Circuit
    {
        return Circuit::with('legs.member.country', 'legs.recorder', 'originatingMember.country', 'creator')->findOrFail($this->circuitId);
    }

    private function editableLeg(?int $legId): CircuitLeg
    {
        $leg = CircuitLeg::with('circuit.legs', 'member')->where('circuit_id', $this->circuitId)->findOrFail($legId);
        Gate::authorize('edit-circuit', $leg->circuit);

        return $leg;
    }
}; ?>

<div class="space-y-6">
    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('status')" />
    @endif

    <div class="flex flex-wrap items-start gap-4">
        <div class="flex-1">
            <div class="flex items-center gap-3">
                <flux:heading size="xl" level="1">{{ __('Circuit #:number', ['number' => $circuit->number]) }}</flux:heading>
                <flux:badge :color="$circuit->status->color()">{{ $circuit->status->label() }}</flux:badge>
                @if ($circuit->isOverdue())
                    <flux:badge color="red" icon="clock">{{ __('Overdue') }}</flux:badge>
                @endif
            </div>
            <flux:subheading>
                {{ __('Originating Member') }}: {{ $circuit->originatingMember->displayName() }}
                &middot; {{ __('Mailed on :date', ['date' => $circuit->mailed_at->format('Y-m-d')]) }}
                @if ($circuit->completed_at) &middot; {{ __('Back home on :date', ['date' => $circuit->completed_at->format('Y-m-d')]) }} @endif
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            <flux:button icon="printer" :href="route('circuits.pdf', [$circuit, 'paper' => 'a4'])" target="_blank">{{ __('PDF (A4)') }}</flux:button>
            <flux:button icon="printer" :href="route('circuits.pdf', [$circuit, 'paper' => 'letter'])" target="_blank">{{ __('PDF (US Letter)') }}</flux:button>
            @if ($canEdit && $circuit->status === CircuitStatus::InProgress)
                <flux:dropdown>
                    <flux:button icon="ellipsis-horizontal" aria-label="{{ __('More') }}" />
                    <flux:menu>
                        <flux:menu.item icon="exclamation-triangle" wire:click="setStatus('lost')" wire:confirm="{{ __('Mark this circuit as lost?') }}">{{ __('Mark as lost') }}</flux:menu.item>
                        <flux:menu.item icon="x-circle" wire:click="setStatus('cancelled')" wire:confirm="{{ __('Cancel this circuit?') }}">{{ __('Cancel circuit') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @elseif ($canEdit && in_array($circuit->status, [CircuitStatus::Lost, CircuitStatus::Cancelled], true))
                <flux:button icon="arrow-path" wire:click="setStatus('in_progress')">{{ __('Reopen') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Timeline --}}
    <ol class="relative space-y-4 border-l-2 border-zinc-200 pl-6 dark:border-zinc-700">
        <li class="relative">
            <span class="absolute -left-[33px] top-1 flex size-4 items-center justify-center rounded-full bg-green-500 ring-4 ring-white dark:ring-zinc-800"></span>
            <div class="text-sm">
                <span class="font-medium">{{ __('Mailed by the Originating Member') }}</span>
                <span class="text-zinc-500">&middot; {{ $circuit->mailed_at->format('Y-m-d') }} &middot; {{ $circuit->originatingMember->country?->name }}</span>
            </div>
        </li>

        @foreach ($circuit->legs as $leg)
            @php
                $isCurrent = $current?->is($leg) && $circuit->status === CircuitStatus::InProgress;
                $dot = $leg->received_at ? 'bg-green-500' : ($isCurrent ? ($leg->isOverdue() ? 'bg-red-500' : 'bg-amber-400 animate-pulse') : 'bg-zinc-300 dark:bg-zinc-600');
            @endphp
            <li wire:key="leg-{{ $leg->id }}" class="relative">
                <span class="absolute -left-[33px] top-4 flex size-4 rounded-full ring-4 ring-white dark:ring-zinc-800 {{ $dot }}"></span>

                <div class="rounded-xl border p-4 {{ $isCurrent ? 'border-amber-300 bg-amber-50 dark:border-amber-700 dark:bg-amber-950/30' : 'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800' }}">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">
                                {{ $leg->position }}. {{ $leg->is_return ? __('Back to') : '' }} {{ $leg->member->displayName() }}
                                <span class="font-normal text-zinc-500">#{{ $leg->member->member_number }} &middot; {{ $leg->member->country?->name }}</span>
                            </div>

                            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-600 dark:text-zinc-400">
                                @if ($leg->received_at)
                                    <span>{{ __('Received') }}: <strong>{{ $leg->received_at->format('Y-m-d') }}</strong>
                                        @if (! is_null($leg->daysInTransit())) ({{ trans_choice(':count day in transit|:count days in transit', $leg->daysInTransit()) }}) @endif
                                    </span>
                                    @if ($leg->quality)
                                        <span>{{ __('Quality') }}: <flux:badge size="sm" :color="$leg->quality === CoverQuality::Poor ? 'red' : 'green'">{{ $leg->quality->label() }}</flux:badge></span>
                                    @endif
                                    @unless ($leg->is_return)
                                        <span>{{ __('Mailed') }}: {{ $leg->mailed_at?->format('Y-m-d') ?? '—' }}</span>
                                    @endunless
                                    <span class="text-xs">
                                        {{ match ($leg->recorded_via) { 'qr' => __('via QR code'), 'code' => __('via typed code'), 'proxy' => __('entered by :name', ['name' => $leg->recorder?->name]), default => '' } }}
                                    </span>
                                @elseif ($isCurrent)
                                    <span>
                                        {{ __('On its way since :date', ['date' => $leg->sentOn()?->format('Y-m-d')]) }}
                                        @if ($leg->isOverdue())
                                            <flux:badge size="sm" color="red">{{ __('No news for more than :days days', ['days' => config('cccc.overdue_days')]) }}</flux:badge>
                                        @endif
                                    </span>
                                @else
                                    <span>{{ __('Waiting') }}</span>
                                @endif
                            </div>

                            @if ($leg->comment)
                                <p class="mt-2 text-sm italic text-zinc-700 dark:text-zinc-300">“{{ $leg->comment }}”</p>
                            @endif
                        </div>

                        @if ($canEdit)
                            <div class="text-right">
                                <div class="font-mono text-xs text-zinc-500" title="{{ __('Code printed on the form') }}">{{ $leg->reference() }}</div>
                                @if ($editingLegId !== $leg->id)
                                    <flux:button size="xs" variant="ghost" icon="pencil-square" class="mt-1" wire:click="edit({{ $leg->id }})">
                                        {{ $leg->received_at ? __('Correct') : __('Record for the member') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endif
                    </div>

                    @if ($editingLegId === $leg->id)
                        <form wire:submit="save" class="mt-4 grid gap-4 border-t border-zinc-200 pt-4 sm:grid-cols-2 dark:border-zinc-700">
                            <flux:input type="date" wire:model="receivedAt" :label="__('Date received')" />
                            @unless ($leg->is_return)
                                <flux:input type="date" wire:model="mailedAt" :label="__('Date mailed')" />
                            @endunless
                            <flux:select wire:model="quality" :label="__('Quality')">
                                <flux:select.option value="">—</flux:select.option>
                                @foreach (CoverQuality::cases() as $q)
                                    <flux:select.option :value="$q->value">{{ $q->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input wire:model="comment" :label="__('Comment')" />
                            <div class="flex gap-2 sm:col-span-2">
                                <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                            </div>
                        </form>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    @if ($circuit->om_message)
        <div class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <flux:heading>{{ __('Message from the Originating Member') }}</flux:heading>
            <p class="mt-1 whitespace-pre-line">{{ $circuit->om_message }}</p>
        </div>
    @endif
</div>
