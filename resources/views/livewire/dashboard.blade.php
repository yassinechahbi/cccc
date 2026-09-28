<?php

use App\Enums\CircuitStatus;
use App\Models\Circuit;
use App\Models\CircuitLeg;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Dashboard')] class extends Component {
    public function with(): array
    {
        $user = auth()->user();
        $memberId = $user->member_id;

        // My legs in circuits still travelling; kept only when the circuit is at (or passed) my step.
        $myLegs = $memberId
            ? CircuitLeg::with('circuit.legs.member.country')
                ->where('member_id', $memberId)
                ->where(fn ($q) => $q->whereNull('received_at')->orWhere(fn ($q) => $q->whereNull('mailed_at')->where('is_return', false)))
                ->whereHas('circuit', fn ($q) => $q->where('status', CircuitStatus::InProgress))
                ->get()
            : collect();

        return [
            'member' => $user->member,
            'incoming' => $myLegs->filter(fn (CircuitLeg $leg) => ! $leg->received_at && $leg->circuit->currentLeg()?->is($leg)),
            'toMail' => $myLegs->filter(fn (CircuitLeg $leg) => $leg->received_at && ! $leg->mailed_at),
            'myCircuits' => $memberId
                ? Circuit::with('legs.member.country')->where('originating_member_id', $memberId)
                    ->where('status', CircuitStatus::InProgress)->latest('mailed_at')->get()
                : collect(),
            'stats' => $user->isAdmin() ? [
                __('Circuits in progress') => Circuit::where('status', CircuitStatus::InProgress)->count(),
                __('Completed this year') => Circuit::where('status', CircuitStatus::Completed)->whereYear('completed_at', now()->year)->count(),
                __('Active members') => \App\Models\Member::active()->count(),
            ] : [],
        ];
    }
}; ?>

<div class="space-y-8">
    <flux:heading size="xl" level="1">{{ __('Welcome, :name', ['name' => auth()->user()->name]) }}</flux:heading>

    @unless ($member)
        <flux:callout icon="information-circle" :heading="__('Your account is not linked to a member number yet.')">
            <flux:callout.text>
                {{ __('Accounts are linked automatically when your e-mail address matches the one in the member directory. Otherwise, please contact the club so we can link your CCCC number.') }}
            </flux:callout.text>
        </flux:callout>
    @endunless

    @if ($stats)
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach ($stats as $label => $value)
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="text-sm text-zinc-500">{{ $label }}</div>
                    <div class="mt-1 text-3xl font-semibold tabular-nums">{{ $value }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if ($member)
        <section class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-3">
                <flux:heading size="lg">{{ __('Coming to you') }}</flux:heading>
                @forelse ($incoming as $leg)
                    <a href="{{ route('circuits.show', $leg->circuit) }}" wire:navigate class="block rounded-xl border border-zinc-200 p-4 hover:border-zinc-400 dark:border-zinc-700">
                        <div class="font-medium">{{ __('Circuit #:number', ['number' => $leg->circuit->number]) }}</div>
                        <div class="text-sm text-zinc-500">
                            {{ __('From :name (:country), sent around :date', [
                                'name' => $leg->previous()?->member->name ?? $leg->circuit->legs->last()->member->name,
                                'country' => ($leg->previous()?->member ?? $leg->circuit->legs->last()->member)->country?->name,
                                'date' => $leg->sentOn()?->format('Y-m-d'),
                            ]) }}
                        </div>
                    </a>
                @empty
                    <flux:text>{{ __('Nothing on its way to you at the moment.') }}</flux:text>
                @endforelse
            </div>

            <div class="space-y-3">
                <flux:heading size="lg">{{ __('To mail on') }}</flux:heading>
                @forelse ($toMail as $leg)
                    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/30">
                        <div class="font-medium">{{ __('Circuit #:number', ['number' => $leg->circuit->number]) }}</div>
                        <div class="text-sm">{{ __('Next') }}: {{ $leg->next()->member->name }}, {{ $leg->next()->member->country?->name }}</div>
                        <flux:button size="sm" class="mt-2" :href="$leg->trackingUrl()" wire:navigate>{{ __('I have mailed it') }}</flux:button>
                    </div>
                @empty
                    <flux:text>{{ __('No circuit waiting on your desk.') }}</flux:text>
                @endforelse
            </div>
        </section>
    @endif

    @if ($myCircuits->isNotEmpty())
        <section class="space-y-3">
            <flux:heading size="lg">{{ __('Your circuits as Originating Member') }}</flux:heading>
            <div class="grid gap-3 md:grid-cols-2">
                @foreach ($myCircuits as $circuit)
                    @php($current = $circuit->currentLeg())
                    <a href="{{ route('circuits.show', $circuit) }}" wire:navigate class="block rounded-xl border p-4 hover:border-zinc-400 {{ $circuit->isOverdue() ? 'border-red-300 dark:border-red-800' : 'border-zinc-200 dark:border-zinc-700' }}">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">#{{ $circuit->number }}</span>
                            @if ($circuit->isOverdue())<flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>@endif
                        </div>
                        <div class="mt-2 flex gap-0.5">
                            @foreach ($circuit->legs as $leg)
                                <span class="h-2 flex-1 rounded-full {{ $leg->received_at ? 'bg-green-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></span>
                            @endforeach
                        </div>
                        <div class="mt-2 text-sm text-zinc-500">{{ __('Travelling to') }} {{ $current?->member->name }} ({{ $current?->member->country?->name }})</div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif
</div>
