<?php

namespace App\Models;

use App\Enums\MemberStatus;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class Member extends Model
{
    /** @use HasFactory<\Database\Factories\MemberFactory> */
    use HasFactory;

    protected $fillable = [
        'member_number', 'name', 'title', 'is_om', 'om_code', 'address', 'city', 'country_id',
        'email', 'phone', 'interest_countries', 'interest_themes', 'cover_preferences',
        'philatelic_references', 'status', 'remarks',
    ];

    protected function casts(): array
    {
        return [
            'is_om' => 'boolean',
            'status' => MemberStatus::class,
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function legs(): HasMany
    {
        return $this->hasMany(CircuitLeg::class);
    }

    public function originatedCircuits(): HasMany
    {
        return $this->hasMany(Circuit::class, 'originating_member_id');
    }

    public function absences(): HasMany
    {
        return $this->hasMany(MemberAbsence::class)->orderBy('starts_on');
    }

    /** Absences not over yet, soonest first. */
    public function upcomingAbsences(): HasMany
    {
        return $this->absences()->whereDate('ends_on', '>=', now()->toDateString());
    }

    /** The first absence overlapping the next $days days, if any (uses loaded upcomingAbsences). */
    public function absenceWithin(int $days = 60): ?MemberAbsence
    {
        $horizon = now()->addDays($days)->endOfDay();

        return $this->upcomingAbsences->first(fn (MemberAbsence $a) => $a->starts_on->lte($horizon));
    }

    /** Grant or withdraw the Originating Member function, keeping the linked account's role in step. */
    public function setOriginatingMember(bool $isOm, ?string $code = null): void
    {
        $this->update(['is_om' => $isOm, 'om_code' => $code]);

        if ($user = $this->user) {
            $roles = ($user->roles ?? collect())->reject(fn (Role $r) => $r === Role::OriginatingMember);
            $user->syncRoles($isOm ? $roles->push(Role::OriginatingMember) : $roles);
        }
    }

    /** Name with club function, e.g. OM "HAE" Kimmo Liljeroos or MD-10 "EI" Holger Kaufhold. */
    public function displayName(): string
    {
        $prefix = collect([
            $this->title ?: ($this->is_om ? 'OM' : null),
            $this->om_code ? '"'.$this->om_code.'"' : null,
        ])->filter()->implode(' ');

        return trim($prefix.' '.$this->name);
    }

    /** Many members have no e-mail: they simply receive nothing and follow the paper form. */
    public function notifyByMail(Notification $notification): void
    {
        $email = $this->user?->email ?? $this->email;

        if ($email) {
            NotificationFacade::route('mail', $email)->notify($notification);
        }
    }

    /** @return list<string> */
    public function postalLines(): array
    {
        return array_values(array_filter([$this->address, $this->city, $this->country?->name]));
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', MemberStatus::Active);
    }

    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $q) use ($term) {
            $like = '%'.$term.'%';
            $q->where('name', 'like', $like)
                ->orWhere('city', 'like', $like)
                ->orWhere('om_code', $term)
                ->orWhere('interest_themes', 'like', $like)
                ->orWhere('interest_countries', 'like', $like)
                ->orWhereHas('country', fn (Builder $c) => $c->where('name', 'like', $like));

            if (ctype_digit($term)) {
                $q->orWhere('member_number', (int) $term);
            }
        });
    }
}
