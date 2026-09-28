<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'roles' => AsEnumCollection::of(Role::class),
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * Once the e-mail address is proven, attach the member record carrying that address.
     * Only when it is unambiguous: some addresses are shared by several members.
     */
    public function linkMemberByEmail(): void
    {
        if ($this->member_id || ! $this->hasVerifiedEmail()) {
            return;
        }

        $candidates = Member::where('email', Str::lower($this->email))->whereDoesntHave('user')->get();

        if ($candidates->count() === 1) {
            $this->linkMember($candidates->first());
        }
    }

    /** Attach (or detach, with null) a member record; an OM member makes the account an OM. */
    public function linkMember(?Member $member): void
    {
        $this->member()->associate($member)->save();

        $roles = ($this->roles ?? collect())->reject(fn (Role $r) => $r === Role::OriginatingMember);
        $this->syncRoles($member?->is_om ? $roles->push(Role::OriginatingMember) : $roles);
    }

    /**
     * Replace the account's roles. The OM role needs a member record (the circuits' return
     * address) and is mirrored on it, so the directory and the circuit forms stay in step.
     *
     * @param  iterable<Role>  $roles
     */
    public function syncRoles(iterable $roles): void
    {
        $roles = collect($roles)->unique()->values();

        if (! $this->member_id) {
            $roles = $roles->reject(fn (Role $r) => $r === Role::OriginatingMember)->values();
        }

        $this->roles = $roles;
        $this->save();

        $this->member?->update(['is_om' => $roles->contains(Role::OriginatingMember)]);
    }

    public function hasRole(Role $role): bool
    {
        return (bool) $this->roles?->contains($role);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(Role::Admin);
    }

    /** An Originating Member account, linked to its member record. */
    public function isOm(): bool
    {
        return $this->hasRole(Role::OriginatingMember) && $this->member_id !== null;
    }

    /** Originating Members and administrators can create circuits. */
    public function canManageCircuits(): bool
    {
        return $this->isAdmin() || $this->isOm();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
