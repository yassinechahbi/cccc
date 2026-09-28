<?php

use App\Enums\CircuitStatus;
use App\Enums\MemberStatus;
use App\Models\Circuit;
use App\Models\Country;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('New circuit')] class extends Component {
    use WithPagination;

    public ?int $omId = null;
    public int $size = 4;

    /** @var list<int> ordered member ids */
    public array $selected = [];

    public string $search = '';
    public ?int $countryId = null;
    public bool $activeOnly = true;

    public string $mailedAt = '';
    public string $message = '';

    public function mount(): void
    {
        $user = auth()->user();
        // An OM originates their own circuits; only an admin chooses another OM.
        $this->omId = $user->isOm() ? $user->member_id : null;
        $this->size = config('cccc.default_members');
        $this->mailedAt = now()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'countryId', 'activeOnly', 'omId'], true)) {
            $this->resetPage();
        }

        if ($property === 'size') {
            $this->size = max(config('cccc.min_members'), min(config('cccc.max_members'), (int) $this->size));
            $this->selected = array_slice($this->selected, 0, $this->size);
        }

        if ($property === 'omId') {
            if (! auth()->user()->isAdmin()) {
                $this->omId = auth()->user()->member_id;
            }

            $this->selected = array_values(array_diff($this->selected, [(int) $this->omId]));
        }
    }

    public function add(int $memberId): void
    {
        if (count($this->selected) < $this->size && ! in_array($memberId, $this->selected, true) && $memberId !== $this->omId) {
            $this->selected[] = $memberId;
        }
    }

    public function remove(int $index): void
    {
        array_splice($this->selected, $index, 1);
    }

    public function move(int $index, int $offset): void
    {
        $target = $index + $offset;

        if (isset($this->selected[$index], $this->selected[$target])) {
            [$this->selected[$index], $this->selected[$target]] = [$this->selected[$target], $this->selected[$index]];
        }
    }

    public function create(): void
    {
        $this->validate([
            'omId' => ['required', 'exists:members,id'],
            'selected' => ['required', 'array', 'size:'.$this->size],
            'selected.*' => ['integer', 'distinct', 'exists:members,id', 'not_in:'.$this->omId],
            'mailedAt' => ['required', 'date'],
            'message' => ['nullable', 'string', 'max:1000'],
        ], [
            'selected.size' => __('Please choose :size members.', ['size' => $this->size]),
        ]);

        $om = Member::findOrFail($this->omId);
        abort_unless(auth()->user()->isAdmin() || (auth()->user()->isOm() && $om->id === auth()->user()->member_id), 403);

        $circuit = Circuit::launch($om, $this->selected, $this->mailedAt, $this->message ?: null, auth()->user());

        session()->flash('status', __('Circuit #:number created. Print the form and mail it with your cover to the first member.', ['number' => $circuit->number]));

        $this->redirect(route('circuits.show', $circuit), navigate: true);
    }

    #[Computed]
    public function oms()
    {
        return Member::where('is_om', true)->orderBy('name')->get(['id', 'name', 'om_code', 'member_number']);
    }

    #[Computed]
    public function countries()
    {
        return Country::whereHas('members')->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function chosen()
    {
        $members = Member::with('country', 'upcomingAbsences')->findMany($this->selected)->keyBy('id');

        return collect($this->selected)->map(fn ($id) => $members[$id])->filter();
    }

    public function with(): array
    {
        $omId = $this->omId;

        $candidates = Member::query()
            ->with('country', 'upcomingAbsences')
            ->search($this->search)
            ->when($this->countryId, fn (Builder $q) => $q->where('country_id', $this->countryId))
            ->when($this->activeOnly, fn (Builder $q) => $q->active())
            ->whereNotIn('id', array_filter([...$this->selected, $omId]))
            ->withCount([
                // Covers still travelling towards this member.
                'legs as pending_count' => fn (Builder $q) => $q->whereNull('received_at')
                    ->whereHas('circuit', fn (Builder $c) => $c->where('status', CircuitStatus::InProgress)),
                // Already in one of this OM's circuits during the last year.
                'legs as recent_with_om_count' => fn (Builder $q) => $q->where('is_return', false)
                    ->whereHas('circuit', fn (Builder $c) => $c->where('originating_member_id', $omId)->where('mailed_at', '>=', now()->subYear())),
            ])
            ->orderBy('name')
            ->paginate(10);

        return ['candidates' => $candidates];
    }
}; ?>

<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('New circuit') }}</flux:heading>
        <flux:subheading>{{ __('Choose the members, in the order the cover will travel. The circuit then comes back to the Originating Member.') }}</flux:subheading>
    </div>

    <div class="grid gap-6 lg:grid-cols-5">
        {{-- Left: settings and route --}}
        <form method="post" wire:submit="create" class="space-y-5 lg:col-span-2">
            @if (auth()->user()->isAdmin())
                <flux:select wire:model.live="omId" :label="__('Originating Member')">
                    <flux:select.option value="">{{ __('Choose...') }}</flux:select.option>
                    @foreach ($this->oms as $om)
                        <flux:select.option :value="$om->id">{{ $om->name }} @if ($om->om_code) ("{{ $om->om_code }}") @endif #{{ $om->member_number }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:text>
                    {{ __('Originating Member') }}: <strong>{{ auth()->user()->member->displayName() }}</strong>
                    &mdash; {{ __('the circuit comes back to you.') }}
                </flux:text>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <flux:input type="number" wire:model.live.debounce.400ms="size" :label="__('Number of members')"
                    min="{{ config('cccc.min_members') }}" max="{{ config('cccc.max_members') }}" />
                <flux:input type="date" wire:model="mailedAt" :label="__('Date mailed')" />
            </div>

            <div>
                <flux:heading>{{ __('Route') }} ({{ count($selected) }}/{{ $size }})</flux:heading>
                <ol class="mt-2 space-y-2">
                    @foreach ($this->chosen as $index => $member)
                        <li wire:key="chosen-{{ $member->id }}" class="flex items-center gap-2 rounded-lg border border-zinc-200 bg-white p-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-800 text-xs font-semibold text-white dark:bg-zinc-200 dark:text-zinc-900">{{ $index + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <div class="truncate font-medium">{{ $member->name }}</div>
                                <div class="truncate text-xs text-zinc-500">#{{ $member->member_number }} &middot; {{ $member->country?->name }}</div>
                                @if ($member->status !== MemberStatus::Active)
                                    <flux:badge size="sm" :color="$member->status->color()">{{ $member->status->label() }}</flux:badge>
                                @endif
                                <x-absence-badge :member="$member" />
                            </div>
                            <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="move({{ $index }}, -1)" :disabled="$index === 0" aria-label="{{ __('Move up') }}" />
                            <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="move({{ $index }}, 1)" :disabled="$index === count($selected) - 1" aria-label="{{ __('Move down') }}" />
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="remove({{ $index }})" aria-label="{{ __('Remove') }}" />
                        </li>
                    @endforeach
                    @for ($i = count($selected); $i < $size; $i++)
                        <li class="rounded-lg border border-dashed border-zinc-300 p-2 text-sm text-zinc-400 dark:border-zinc-600">
                            {{ $i + 1 }}. {{ __('Pick a member from the list') }}
                        </li>
                    @endfor
                    <li class="rounded-lg bg-zinc-100 p-2 text-sm text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                        {{ $size + 1 }}. {{ __('Back to the Originating Member') }}
                    </li>
                </ol>
                <flux:error name="selected" />
            </div>

            <flux:textarea wire:model="message" :label="__('Message printed on the backside (optional)')" rows="3" />

            <flux:button type="submit" variant="primary" class="w-full" :disabled="count($selected) !== $size">
                {{ __('Create the circuit') }}
            </flux:button>
        </form>

        {{-- Right: member search over the whole directory --}}
        <div class="space-y-3 lg:col-span-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Name, #, city, theme...')" />
                <flux:select wire:model.live="countryId">
                    <flux:select.option value="">{{ __('All countries') }}</flux:select.option>
                    @foreach ($this->countries as $country)
                        <flux:select.option :value="$country->id">{{ $country->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:checkbox wire:model.live="activeOnly" :label="__('Active members only')" />

            <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-white dark:divide-zinc-700 dark:border-zinc-700 dark:bg-zinc-800">
                @forelse ($candidates as $member)
                    <li wire:key="candidate-{{ $member->id }}" class="flex items-start gap-3 p-3 text-sm">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">
                                {{ $member->displayName() }}
                                <span class="font-normal text-zinc-500">#{{ $member->member_number }}</span>
                            </div>
                            <div class="text-zinc-600 dark:text-zinc-400">{{ $member->city }} &middot; {{ $member->country?->name }}</div>
                            @if ($member->interest_themes || $member->interest_countries)
                                <div class="mt-1 text-xs text-zinc-500">
                                    @if ($member->interest_themes) {{ __('Themes') }}: {{ Str::limit($member->interest_themes, 90) }} @endif
                                    @if ($member->interest_countries) &middot; {{ __('Countries') }}: {{ Str::limit($member->interest_countries, 60) }} @endif
                                </div>
                            @endif
                            <div class="mt-1 flex flex-wrap gap-1">
                                @if ($member->status !== MemberStatus::Active)
                                    <flux:badge size="sm" :color="$member->status->color()">{{ $member->status->label() }}</flux:badge>
                                @endif
                                <x-absence-badge :member="$member" />
                                @if ($member->pending_count)
                                    <flux:badge size="sm" color="blue">{{ trans_choice(':count circuit on its way|:count circuits on their way', $member->pending_count) }}</flux:badge>
                                @endif
                                @if ($member->recent_with_om_count)
                                    <flux:badge size="sm" color="amber">{{ __('In one of your circuits this year') }}</flux:badge>
                                @endif
                                @unless ($member->email)
                                    <flux:badge size="sm" color="zinc">{{ __('No e-mail') }}</flux:badge>
                                @endunless
                            </div>
                        </div>
                        <flux:button size="sm" icon="plus" wire:click="add({{ $member->id }})" :disabled="count($selected) >= $size">{{ __('Add') }}</flux:button>
                    </li>
                @empty
                    <li class="p-6 text-center text-sm text-zinc-500">{{ __('No member matches your search.') }}</li>
                @endforelse
            </ul>

            {{ $candidates->links() }}
        </div>
    </div>
</div>
