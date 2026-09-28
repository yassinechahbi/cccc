<?php

use App\Enums\MemberStatus;
use App\Models\Country;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('Members')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $country = '';

    #[Url]
    public string $status = 'active';

    #[Url]
    public bool $omOnly = false;

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function countries()
    {
        return Country::whereHas('members')->withCount('members')->orderBy('name')->get();
    }

    public function with(): array
    {
        return [
            'members' => Member::query()
                ->with('country', 'upcomingAbsences')
                ->search($this->search)
                ->when($this->country !== '', fn (Builder $q) => $q->where('country_id', $this->country))
                ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
                ->when($this->omOnly, fn (Builder $q) => $q->where('is_om', true))
                ->orderBy('name')
                ->paginate(25),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Members') }}</flux:heading>
        <flux:subheading>{{ __('Postal addresses are only visible to Originating Members and administrators.') }}</flux:subheading>
    </div>

    <div class="grid gap-3 sm:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Name, #, city, theme...')" class="sm:col-span-2" />
        <flux:select wire:model.live="country">
            <flux:select.option value="">{{ __('All countries') }}</flux:select.option>
            @foreach ($this->countries as $c)
                <flux:select.option :value="$c->id">{{ $c->name }} ({{ $c->members_count }})</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (MemberStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>
    <flux:checkbox wire:model.live="omOnly" :label="__('Originating Members only')" />

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Address') }}</th>
                    <th class="px-4 py-3">{{ __('Interests') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($members as $member)
                    <tr wire:key="member-{{ $member->id }}" class="align-top">
                        <td class="px-4 py-3 text-zinc-500">{{ $member->member_number }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $member->displayName() }}</div>
                            @if ($member->email)<div class="text-xs text-zinc-500">{{ $member->email }}</div>@endif
                            @if ($member->phone)<div class="text-xs text-zinc-500">{{ $member->phone }}</div>@endif
                            @can('admin')
                                <flux:link class="text-xs" :href="route('members.edit', $member)" wire:navigate>{{ __('Edit') }}</flux:link>
                            @endcan
                        </td>
                        <td class="px-4 py-3">
                            @foreach ($member->postalLines() as $line)<div>{{ $line }}</div>@endforeach
                        </td>
                        <td class="max-w-xs px-4 py-3 text-xs text-zinc-600 dark:text-zinc-400">
                            @if ($member->interest_countries)<div><span class="font-medium">{{ __('Countries') }}:</span> {{ $member->interest_countries }}</div>@endif
                            @if ($member->interest_themes)<div><span class="font-medium">{{ __('Themes') }}:</span> {{ $member->interest_themes }}</div>@endif
                            @if ($member->cover_preferences)<div><span class="font-medium">{{ __('Wishes') }}:</span> {{ $member->cover_preferences }}</div>@endif
                        </td>
                        <td class="px-4 py-3">
                            <flux:badge size="sm" :color="$member->status->color()">{{ $member->status->label() }}</flux:badge>
                            <x-absence-badge :member="$member" />
                            @if ($member->remarks)<div class="mt-1 text-xs text-zinc-500">{{ $member->remarks }}</div>@endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">{{ __('No member matches your search.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $members->links() }}
</div>
