<?php

use App\Enums\CircuitStatus;
use App\Enums\CoverQuality;
use App\Models\CircuitLeg;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.public')] class extends Component {
    #[Locked]
    public string $reference;

    #[Locked]
    public string $via = 'qr';

    public string $receivedAt = '';
    public ?int $quality = null;
    public string $comment = '';
    public bool $alreadyMailed = false;
    public string $mailedAt = '';

    public function mount(string $reference): void
    {
        abort_unless(CircuitLeg::findByReference($reference), 404);

        $this->reference = $reference;
        $this->via = request()->query('via') === 'code' ? 'code' : 'qr';
        $this->receivedAt = $this->mailedAt = now()->toDateString();
    }

    public function with(): array
    {
        $leg = $this->leg();

        return ['leg' => $leg, 'circuit' => $leg->circuit];
    }

    public function rendering(View $view): void
    {
        $view->title(__('Circuit #:number', ['number' => strtok($this->reference, '-')]));
    }

    public function recordReception(): void
    {
        $leg = $this->leg();
        abort_if($leg->received_at || $leg->circuit->status !== CircuitStatus::InProgress, 403);

        $this->validate([
            'receivedAt' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$leg->circuit->mailed_at->toDateString()],
            'quality' => [$leg->is_return ? 'nullable' : 'required', Rule::enum(CoverQuality::class)],
            'comment' => ['nullable', 'string', 'max:1000'],
            'mailedAt' => ['exclude_unless:alreadyMailed,true', 'required', 'date', 'before_or_equal:today', 'after_or_equal:receivedAt'],
        ]);

        $leg->recordReception($this->receivedAt, CoverQuality::tryFrom((int) $this->quality), $this->comment ?: null, $this->via, auth()->user());

        if ($this->alreadyMailed && ! $leg->is_return) {
            $leg->recordMailing($this->mailedAt, $this->via, auth()->user());
        }
    }

    public function recordMailing(): void
    {
        $leg = $this->leg();
        abort_if(! $leg->received_at || $leg->mailed_at || $leg->is_return, 403);

        $this->validate([
            'mailedAt' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.$leg->received_at->toDateString()],
        ]);

        $leg->recordMailing($this->mailedAt, $this->via, auth()->user());
    }

    private function leg(): CircuitLeg
    {
        return CircuitLeg::findByReference($this->reference)->load('circuit.legs.member.country', 'member.country');
    }
}; ?>

<div class="space-y-6">
    <div>
        <flux:subheading>{{ __('Circuit #:number', ['number' => $circuit->number]) }} &middot; {{ __('Step :position of :total', ['position' => $leg->position, 'total' => $circuit->legs->count()]) }}</flux:subheading>
        <flux:heading size="xl" level="1">
            {{ $leg->is_return ? __('Circuit back home') : __('Box :position', ['position' => $leg->position]) }}:
            {{ $leg->member->name }}
        </flux:heading>
        <flux:text>{{ $leg->member->city }}, {{ $leg->member->country?->name }}</flux:text>
    </div>

    {{-- Progress of the whole circuit --}}
    <ol class="flex items-center gap-1" aria-label="{{ __('Progress') }}">
        @foreach ($circuit->legs as $step)
            <li class="h-2 flex-1 rounded-full {{ $step->received_at ? 'bg-green-500' : ($step->is($leg) ? 'bg-amber-400' : 'bg-zinc-200 dark:bg-zinc-700') }}"
                title="{{ $step->position }}. {{ $step->member->country?->name }}"></li>
        @endforeach
    </ol>

    @if ($circuit->status === \App\Enums\CircuitStatus::Cancelled || $circuit->status === \App\Enums\CircuitStatus::Lost)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('This circuit is closed (:status).', ['status' => $circuit->status->label()])" />

    @elseif (! $leg->received_at)
        {{-- Step 1: reception --}}
        <form wire:submit="recordReception" class="space-y-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg">{{ __('I have received the cover') }}</flux:heading>

            <flux:input type="date" wire:model="receivedAt" :label="__('Date received')" max="{{ now()->toDateString() }}" required />

            @unless ($leg->is_return)
                <flux:radio.group wire:model="quality" :label="__('Quality of the cover you received')">
                    @foreach (CoverQuality::cases() as $q)
                        <flux:radio :value="$q->value" :label="$q->label()" :description="$q->guide()" />
                    @endforeach
                </flux:radio.group>
            @endunless

            <flux:textarea wire:model="comment" :label="__('Message (optional)')" :placeholder="__('A word for the Originating Member...')" rows="3" />

            @unless ($leg->is_return)
                <flux:checkbox wire:model.live="alreadyMailed" :label="__('I have already mailed the circuit to the next member')" />

                @if ($alreadyMailed)
                    <flux:input type="date" wire:model="mailedAt" :label="__('Date mailed')" max="{{ now()->toDateString() }}" required />
                @endif
            @endunless

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Confirm reception') }}</flux:button>
        </form>

    @elseif (! $leg->mailed_at && ! $leg->is_return)
        {{-- Step 2: mailing on --}}
        <flux:callout variant="success" icon="check-circle"
            :heading="__('Reception recorded on :date. Thank you!', ['date' => $leg->received_at->format('Y-m-d')])" />

        @php($next = $leg->next())
        <form wire:submit="recordMailing" class="space-y-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <div>
                <flux:heading size="lg">{{ __('I have mailed the circuit on') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ $next->is_return ? __('Next: back to the Originating Member') : __('Next member') }}:
                    <strong>{{ $next->member->name }}</strong>, {{ $next->member->country?->name }}.
                    {{ __('Use the same code to come back here once the cover is posted.') }}
                </flux:text>
            </div>

            <flux:input type="date" wire:model="mailedAt" :label="__('Date mailed')" max="{{ now()->toDateString() }}" required />

            <flux:button type="submit" variant="primary" class="w-full">{{ __('Confirm mailing') }}</flux:button>
        </form>

    @else
        <flux:callout variant="success" icon="check-circle" :heading="__('This step is complete. Thank you!')">
            <flux:callout.text>
                {{ __('Received') }}: {{ $leg->received_at->format('Y-m-d') }}
                @if ($leg->quality) &middot; {{ $leg->quality->label() }} @endif
                @if ($leg->mailed_at) &middot; {{ __('Mailed') }}: {{ $leg->mailed_at->format('Y-m-d') }} @endif
            </flux:callout.text>
            <flux:callout.text>{{ __('To correct something, please contact the Originating Member.') }}</flux:callout.text>
        </flux:callout>
    @endif
</div>
