<?php

use App\Enums\MemberStatus;
use App\Models\Country;
use App\Models\Member;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public ?int $memberId = null;

    public string $name = '';
    public string $address = '';
    public string $city = '';
    public ?int $countryId = null;
    public string $phone = '';
    public string $interestCountries = '';
    public string $interestThemes = '';
    public string $coverPreferences = '';
    public string $philatelicReferences = '';

    // Administrator only.
    public string $email = '';
    public string $title = '';
    public string $status = '';
    public string $remarks = '';
    public bool $isOm = false;
    public string $omCode = '';

    public string $absenceStartsOn = '';
    public string $absenceEndsOn = '';
    public string $absenceNote = '';

    /** Without a route parameter: the signed-in member's own record. */
    public function mount(?Member $member = null): void
    {
        $member = $member?->exists ? $member : auth()->user()->member;

        if (! $member) {
            return;
        }

        Gate::authorize('edit-member', $member);

        $this->memberId = $member->id;
        $this->fill([
            'name' => $member->name,
            'address' => (string) $member->address,
            'city' => (string) $member->city,
            'countryId' => $member->country_id,
            'phone' => (string) $member->phone,
            'interestCountries' => (string) $member->interest_countries,
            'interestThemes' => (string) $member->interest_themes,
            'coverPreferences' => (string) $member->cover_preferences,
            'philatelicReferences' => (string) $member->philatelic_references,
            'email' => (string) $member->email,
            'title' => (string) $member->title,
            'status' => $member->status->value,
            'remarks' => (string) $member->remarks,
            'isOm' => $member->is_om,
            'omCode' => (string) $member->om_code,
        ]);
    }

    public function rendering(View $view): void
    {
        $view->title($this->isOwnProfile() ? __('My profile') : $this->member()?->name);
    }

    public function save(): void
    {
        $member = $this->member();
        Gate::authorize('edit-member', $member);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'countryId' => ['required', 'exists:countries,id'],
            'phone' => ['nullable', 'string', 'max:40'],
            'interestCountries' => ['nullable', 'string', 'max:2000'],
            'interestThemes' => ['nullable', 'string', 'max:2000'],
            'coverPreferences' => ['nullable', 'string', 'max:2000'],
            'philatelicReferences' => ['nullable', 'string', 'max:2000'],
        ]);

        $member->update([
            'name' => $validated['name'],
            'address' => $validated['address'] ?: null,
            'city' => $validated['city'] ?: null,
            'country_id' => $validated['countryId'],
            'phone' => $validated['phone'] ?: null,
            'interest_countries' => $validated['interestCountries'] ?: null,
            'interest_themes' => $validated['interestThemes'] ?: null,
            'cover_preferences' => $validated['coverPreferences'] ?: null,
            'philatelic_references' => $validated['philatelicReferences'] ?: null,
        ]);

        // Club data only an administrator may change: never trust these fields otherwise.
        if (auth()->user()->isAdmin()) {
            $admin = $this->validate([
                'email' => ['nullable', 'email', 'max:255'],
                'title' => ['nullable', 'string', 'max:20'],
                'status' => ['required', Rule::enum(MemberStatus::class)],
                'remarks' => ['nullable', 'string', 'max:2000'],
                'omCode' => ['nullable', 'string', 'max:10'],
            ]);

            $member->update([
                'email' => $admin['email'] ? strtolower($admin['email']) : null,
                'title' => $admin['title'] ?: null,
                'status' => $admin['status'],
                'remarks' => $admin['remarks'] ?: null,
            ]);

            $member->setOriginatingMember($this->isOm, $this->isOm ? (strtoupper($admin['omCode']) ?: null) : null);
        }

        $this->dispatch('profile-saved');
    }

    public function addAbsence(): void
    {
        $member = $this->member();
        Gate::authorize('edit-member', $member);

        $this->validate([
            'absenceStartsOn' => ['required', 'date'],
            'absenceEndsOn' => ['required', 'date', 'after_or_equal:absenceStartsOn', 'after_or_equal:today'],
            'absenceNote' => ['nullable', 'string', 'max:255'],
        ]);

        $member->absences()->create([
            'starts_on' => $this->absenceStartsOn,
            'ends_on' => $this->absenceEndsOn,
            'note' => $this->absenceNote ?: null,
        ]);

        $this->reset('absenceStartsOn', 'absenceEndsOn', 'absenceNote');
        unset($this->absences);
    }

    public function deleteAbsence(int $id): void
    {
        $member = $this->member();
        Gate::authorize('edit-member', $member);

        $member->absences()->whereKey($id)->delete();
        unset($this->absences);
    }

    #[Computed]
    public function absences()
    {
        return $this->member()?->upcomingAbsences()->get() ?? collect();
    }

    #[Computed]
    public function countries()
    {
        return Country::orderBy('name')->get(['id', 'name']);
    }

    public function isOwnProfile(): bool
    {
        return $this->memberId !== null && $this->memberId === auth()->user()->member_id;
    }

    private function member(): ?Member
    {
        return $this->memberId ? Member::with('user')->find($this->memberId) : null;
    }

    public function with(): array
    {
        return ['member' => $this->member()];
    }
}; ?>

<div class="max-w-3xl space-y-8">
    @unless ($member)
        <flux:heading size="xl" level="1">{{ __('My profile') }}</flux:heading>
        <flux:callout icon="information-circle" :heading="__('Your account is not linked to a member number yet.')">
            <flux:callout.text>{{ __('Please contact the club so an administrator can link your CCCC number to your account.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div>
            <flux:heading size="xl" level="1">{{ $this->isOwnProfile() ? __('My profile') : $member->displayName() }}</flux:heading>
            <flux:subheading>
                {{ __('CCCC member #:number', ['number' => $member->member_number]) }}
                @if ($member->user) &middot; {{ __('Account e-mail: :email', ['email' => $member->user->email]) }} @endif
            </flux:subheading>
        </div>

        <form method="post" wire:submit="save" class="space-y-8">
            {{-- Postal address: printed on every circuit form --}}
            <section class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Postal address') }}</flux:heading>
                    <flux:text>{{ __('Printed on the circuit forms: members mail their covers to this address.') }}</flux:text>
                </div>
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="address" :label="__('Street address / P.O. Box')" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="city" :label="__('Postcode and city')" />
                    <flux:select wire:model="countryId" :label="__('Country')" required>
                        <flux:select.option value="">{{ __('Choose...') }}</flux:select.option>
                        @foreach ($this->countries as $country)
                            <flux:select.option :value="$country->id">{{ $country->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:input wire:model="phone" type="tel" :label="__('Phone (optional)')" :description="__('Only visible to Originating Members and administrators.')" />
            </section>

            {{-- What the member collects --}}
            <section class="space-y-4">
                <div>
                    <flux:heading size="lg">{{ __('Collecting interests') }}</flux:heading>
                    <flux:text>{{ __('Helps Originating Members choose circuits and senders choose your covers.') }}</flux:text>
                </div>
                <flux:textarea wire:model="interestCountries" :label="__('Countries of interest')" rows="2" :placeholder="__('e.g. Scandinavia, Japan, used stamps from North Africa')" />
                <flux:textarea wire:model="interestThemes" :label="__('Themes of interest')" rows="2" :placeholder="__('e.g. birds, ships, Europa CEPT')" />
                <flux:textarea wire:model="coverPreferences" :label="__('Wishes for the covers you receive')" rows="2" :placeholder="__('e.g. C6 envelopes, full sets, no CTO, registered welcome')" />
                <flux:textarea wire:model="philatelicReferences" :label="__('Philatelic references')" rows="2" :placeholder="__('Other clubs and societies with your member numbers, website, exhibitions...')" />
            </section>

            @if (auth()->user()->isAdmin())
                <section class="space-y-4 rounded-xl border border-red-200 p-4 dark:border-red-900">
                    <div>
                        <flux:heading size="lg">{{ __('Administration') }}</flux:heading>
                        <flux:text>{{ __('Only administrators see and change these fields.') }}</flux:text>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="email" type="email" :label="__('Directory e-mail')" :description="__('Used when the member has no account.')" />
                        <flux:select wire:model="status" :label="__('Status')">
                            @foreach (MemberStatus::cases() as $case)
                                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="title" :label="__('Club function')" placeholder="MD-10" />
                        <div class="space-y-2">
                            <flux:checkbox wire:model.live="isOm" :label="__('Originating Member')" />
                            @if ($isOm)
                                <flux:input wire:model="omCode" :label="__('OM code')" placeholder="HAE" />
                            @endif
                        </div>
                    </div>
                    <flux:textarea wire:model="remarks" :label="__('Internal remarks')" rows="2" />
                </section>
            @endif

            <div class="flex items-center gap-4">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <x-action-message on="profile-saved">{{ __('Saved.') }}</x-action-message>
            </div>
        </form>

        {{-- Absences: Originating Members are warned before routing a circuit to an absent member --}}
        <section class="space-y-4">
            <div>
                <flux:heading size="lg">{{ __('Absences') }}</flux:heading>
                <flux:text>{{ __('Going away? Tell the club when you cannot receive circuits, so Originating Members avoid sending you one.') }}</flux:text>
            </div>

            @if ($this->absences->isNotEmpty())
                <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->absences as $absence)
                        <li wire:key="absence-{{ $absence->id }}" class="flex items-center gap-3 p-3 text-sm">
                            <flux:icon name="calendar-days" class="size-5 text-zinc-400" />
                            <div class="flex-1">
                                <div class="font-medium">{{ $absence->starts_on->format('Y-m-d') }} &rarr; {{ $absence->ends_on->format('Y-m-d') }}</div>
                                @if ($absence->note)<div class="text-zinc-500">{{ $absence->note }}</div>@endif
                            </div>
                            @if ($absence->isCurrent())
                                <flux:badge size="sm" color="orange">{{ __('Now') }}</flux:badge>
                            @endif
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="deleteAbsence({{ $absence->id }})"
                                wire:confirm="{{ __('Delete this absence?') }}" aria-label="{{ __('Delete') }}" />
                        </li>
                    @endforeach
                </ul>
            @else
                <flux:text class="italic">{{ __('No absence planned.') }}</flux:text>
            @endif

            <form method="post" wire:submit="addAbsence" class="grid items-end gap-3 rounded-xl border border-dashed border-zinc-300 p-4 sm:grid-cols-4 dark:border-zinc-600">
                <flux:input type="date" wire:model="absenceStartsOn" :label="__('From')" required />
                <flux:input type="date" wire:model="absenceEndsOn" :label="__('To')" required />
                <flux:input wire:model="absenceNote" :label="__('Note (optional)')" :placeholder="__('Holidays')" />
                <flux:button type="submit" icon="plus">{{ __('Add') }}</flux:button>
            </form>
        </section>
    @endunless
</div>
