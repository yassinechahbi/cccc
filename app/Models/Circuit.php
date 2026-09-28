<?php

namespace App\Models;

use App\Enums\CircuitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Circuit extends Model
{
    protected $fillable = ['number', 'originating_member_id', 'created_by', 'status', 'mailed_at', 'completed_at', 'om_message'];

    protected function casts(): array
    {
        return [
            'status' => CircuitStatus::class,
            'mailed_at' => 'date',
            'completed_at' => 'date',
        ];
    }

    public function originatingMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'originating_member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function legs(): HasMany
    {
        return $this->hasMany(CircuitLeg::class)->orderBy('position');
    }

    /**
     * Create a circuit travelling through the given members, in order, then back to the OM.
     *
     * @param  list<int>  $memberIds
     */
    public static function launch(Member $om, array $memberIds, string $mailedAt, ?string $message, ?User $creator): self
    {
        return DB::transaction(function () use ($om, $memberIds, $mailedAt, $message, $creator) {
            $last = (int) static::query()->lockForUpdate()->max('number');
            $number = max($last + 1, (int) config('cccc.first_circuit_number'));

            $circuit = static::create([
                'number' => $number,
                'originating_member_id' => $om->id,
                'created_by' => $creator?->id,
                'status' => CircuitStatus::InProgress,
                'mailed_at' => $mailedAt,
                'om_message' => $message,
            ]);

            $stops = [...$memberIds, $om->id];

            foreach ($stops as $index => $memberId) {
                $circuit->legs()->create([
                    'position' => $index + 1,
                    'member_id' => $memberId,
                    'is_return' => $index === count($stops) - 1,
                    'code' => CircuitLeg::generateCode(),
                ]);
            }

            return $circuit;
        });
    }

    /** The leg the circuit is currently travelling to, or null once it is back with the OM. */
    public function currentLeg(): ?CircuitLeg
    {
        return $this->legs->first(fn (CircuitLeg $leg) => $leg->received_at === null);
    }

    public function isOverdue(): bool
    {
        return $this->status === CircuitStatus::InProgress && (bool) $this->currentLeg()?->isOverdue();
    }

    /**
     * Admins see everything; an OM sees the circuits they originated; every member sees
     * the circuits in progress they take part in.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->member_id !== null
            && ($user->member_id === $this->originating_member_id
                || ($this->status === CircuitStatus::InProgress && $this->legs->contains('member_id', $user->member_id)));
    }

    /** The query counterpart of isVisibleTo(). */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        if (! $user->member_id) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(fn (Builder $q) => $q
            ->where('originating_member_id', $user->member_id)
            ->orWhere(fn (Builder $q) => $q
                ->where('status', CircuitStatus::InProgress)
                ->whereHas('legs', fn (Builder $l) => $l->where('member_id', $user->member_id))));
    }

    /** The Originating Member of the circuit, or an admin. */
    public function isEditableBy(User $user): bool
    {
        return $user->isAdmin() || ($user->member_id !== null && $user->member_id === $this->originating_member_id);
    }

    public function completeIfReturned(): void
    {
        // Query rather than use the loaded relation, which may predate the reception just recorded.
        $last = $this->legs()->reorder('position', 'desc')->first();

        if ($last?->received_at && $this->status === CircuitStatus::InProgress) {
            $this->update(['status' => CircuitStatus::Completed, 'completed_at' => $last->received_at]);
        }
    }
}
