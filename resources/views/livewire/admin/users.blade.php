<?php

use App\Enums\Role;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('Users')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $role = '';

    #[Locked]
    public ?int $editingId = null;

    public string $memberNumber = '';

    /** @var list<string> */
    public array $roles = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'role'], true)) {
            $this->resetPage();
        }
    }

    public function edit(int $userId): void
    {
        $user = User::with('member')->findOrFail($userId);

        $this->editingId = $user->id;
        $this->memberNumber = (string) $user->member?->member_number;
        $this->roles = ($user->roles ?? collect())->map->value->all();
        $this->resetValidation();
    }

    public function cancel(): void
    {
        $this->editingId = null;
    }

    public function save(): void
    {
        $user = User::findOrFail($this->editingId);

        $this->validate([
            'memberNumber' => ['nullable', 'integer', 'exists:members,member_number'],
            'roles' => ['array'],
            'roles.*' => [\Illuminate\Validation\Rule::enum(Role::class)],
        ]);

        $member = $this->memberNumber !== '' ? Member::where('member_number', $this->memberNumber)->first() : null;

        if ($member && $member->user && ! $member->user->is($user)) {
            $this->addError('memberNumber', __('Member #:number is already linked to :email.', ['number' => $member->member_number, 'email' => $member->user->email]));

            return;
        }

        $roles = collect($this->roles)->map(fn (string $r) => Role::from($r));

        // Never lock the club out: an admin cannot drop their own admin role.
        if ($user->is(auth()->user()) && ! $roles->contains(Role::Admin)) {
            $this->addError('roles', __('You cannot remove your own administrator role.'));

            return;
        }

        if ($roles->contains(Role::OriginatingMember) && ! $member) {
            $this->addError('roles', __('An Originating Member must be linked to a member number (the circuits come back to that address).'));

            return;
        }

        $user->member()->associate($member)->save();
        $user->syncRoles($roles);

        $this->editingId = null;
    }

    public function with(): array
    {
        return [
            'users' => User::query()
                ->with('member.country')
                ->when($this->search !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                    ->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%')
                    ->orWhereHas('member', fn (Builder $m) => $m->search($this->search))))
                ->when($this->role === 'none', fn (Builder $q) => $q->where(fn (Builder $q) => $q->whereNull('roles')->orWhereJsonLength('roles', 0)))
                ->when(Role::tryFrom($this->role), fn (Builder $q, Role $role) => $q->whereJsonContains('roles', $role->value))
                ->orderBy('name')
                ->paginate(25),
        ];
    }
}; ?>

<div class="space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
        <flux:subheading>{{ __('Accounts of the website. Link each account to its CCCC member number and give it its roles.') }}</flux:subheading>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        @foreach (Role::cases() as $case)
            <div class="rounded-xl border border-zinc-200 p-3 text-sm dark:border-zinc-700">
                <flux:badge size="sm" :color="$case->color()">{{ $case->label() }}</flux:badge>
                <span class="text-zinc-600 dark:text-zinc-400">{{ $case->description() }}</span>
            </div>
        @endforeach
    </div>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Name, e-mail, member #...')" class="sm:col-span-2" />
        <flux:select wire:model.live="role">
            <flux:select.option value="">{{ __('All roles') }}</flux:select.option>
            @foreach (Role::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
            <flux:select.option value="none">{{ __('Members without role') }}</flux:select.option>
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-zinc-50 text-xs uppercase text-zinc-500 dark:bg-zinc-800">
                <tr>
                    <th class="px-4 py-3">{{ __('Account') }}</th>
                    <th class="px-4 py-3">{{ __('Member') }}</th>
                    <th class="px-4 py-3">{{ __('Roles') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($users as $user)
                    <tr wire:key="user-{{ $user->id }}" class="align-top">
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $user->name }}</div>
                            <div class="text-xs text-zinc-500">{{ $user->email }}</div>
                            @unless ($user->hasVerifiedEmail())
                                <flux:badge size="sm" color="zinc">{{ __('E-mail not verified') }}</flux:badge>
                            @endunless
                        </td>
                        <td class="px-4 py-3">
                            @if ($user->member)
                                <a href="{{ route('members.edit', $user->member) }}" class="hover:underline" wire:navigate>#{{ $user->member->member_number }} {{ $user->member->name }}</a>
                                <div class="text-xs text-zinc-500">{{ $user->member->country?->name }}</div>
                            @else
                                <span class="text-zinc-400">{{ __('Not linked') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-1">
                                @forelse ($user->roles ?? [] as $r)
                                    <flux:badge size="sm" :color="$r->color()">{{ $r->label() }}</flux:badge>
                                @empty
                                    <span class="text-zinc-400">{{ __('Member') }}</span>
                                @endforelse
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($editingId !== $user->id)
                                <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="edit({{ $user->id }})">{{ __('Edit') }}</flux:button>
                            @endif
                        </td>
                    </tr>
                    @if ($editingId === $user->id)
                        <tr wire:key="user-edit-{{ $user->id }}">
                            <td colspan="4" class="bg-zinc-50 px-4 py-4 dark:bg-zinc-800/50">
                                <form method="post" wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                                    <flux:input wire:model="memberNumber" :label="__('CCCC member number')" :description="__('Leave empty to unlink.')" inputmode="numeric" />
                                    <flux:checkbox.group wire:model="roles" :label="__('Roles')">
                                        @foreach (Role::cases() as $case)
                                            <flux:checkbox :value="$case->value" :label="$case->label()" :description="$case->description()" />
                                        @endforeach
                                    </flux:checkbox.group>
                                    <div class="flex gap-2 sm:col-span-2">
                                        <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                                        <flux:button size="sm" variant="ghost" wire:click="cancel">{{ __('Cancel') }}</flux:button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-zinc-500">{{ __('No account matches your search.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
</div>
