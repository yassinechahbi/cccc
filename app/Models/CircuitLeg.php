<?php

namespace App\Models;

use App\Enums\CoverQuality;
use App\Notifications\CircuitLegUpdated;
use App\Notifications\CircuitOnItsWay;
use App\Notifications\PoorCoverReported;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

class CircuitLeg extends Model
{
    // No 0/O or 1/I, so a code copied by hand from paper cannot be misread.
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const CODE_LENGTH = 4;

    protected $fillable = [
        'position', 'member_id', 'is_return', 'code', 'received_at', 'mailed_at',
        'quality', 'comment', 'recorded_via', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'is_return' => 'boolean',
            'received_at' => 'date',
            'mailed_at' => 'date',
            'quality' => CoverQuality::class,
        ];
    }

    public function circuit(): BelongsTo
    {
        return $this->belongsTo(Circuit::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public static function generateCode(): string
    {
        $code = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    /** Reference printed under the QR code, e.g. 5708-2-K9F4. */
    public function reference(): string
    {
        return $this->circuit->number.'-'.$this->position.'-'.$this->code;
    }

    public function trackingUrl(): string
    {
        return route('track.leg', $this->reference());
    }

    /** Resolve a reference typed by a member: "5708-2-K9F4", "5708 2 k9f4", ... */
    public static function findByReference(string $input): ?self
    {
        $normalized = strtoupper(trim($input));

        if (! preg_match('/^(\d+)[^A-Z0-9]+(\d{1,2})[^A-Z0-9]+([A-Z0-9]+)$/', $normalized, $m)) {
            return null;
        }

        $leg = static::query()
            ->whereHas('circuit', fn ($q) => $q->where('number', (int) $m[1]))
            ->where('position', (int) $m[2])
            ->first();

        return $leg && hash_equals($leg->code, $m[3]) ? $leg : null;
    }

    public function previous(): ?self
    {
        return $this->circuit->legs->firstWhere('position', $this->position - 1);
    }

    /** When the cover was sent towards this member, as far as we know. */
    public function sentOn(): ?Carbon
    {
        $previous = $this->previous();

        return $previous ? ($previous->mailed_at ?? $previous->received_at) : $this->circuit->mailed_at;
    }

    public function daysInTransit(): ?int
    {
        $sent = $this->sentOn();

        return $sent && $this->received_at ? (int) $sent->diffInDays($this->received_at) : null;
    }

    public function next(): ?self
    {
        return $this->circuit->legs->firstWhere('position', $this->position + 1);
    }

    public function recordReception(string $date, ?CoverQuality $quality, ?string $comment, string $via, ?User $by = null): void
    {
        $this->update([
            'received_at' => $date,
            'quality' => $quality,
            'comment' => $comment,
            'recorded_via' => $via,
            'recorded_by' => $by?->id,
        ]);

        $circuit = $this->circuit;
        $circuit->completeIfReturned();

        if (! $this->is_return) {
            $circuit->originatingMember->notifyByMail(new CircuitLegUpdated($this, 'received'));
        }

        if ($quality === CoverQuality::Poor) {
            Notification::route('mail', config('cccc.managing_director_email'))->notify(new PoorCoverReported($this));
        }
    }

    public function recordMailing(string $date, string $via, ?User $by = null): void
    {
        $this->update(['mailed_at' => $date, 'recorded_via' => $this->recorded_via ?? $via, 'recorded_by' => $this->recorded_by ?? $by?->id]);

        $this->circuit->originatingMember->notifyByMail(new CircuitLegUpdated($this, 'mailed'));

        if ($next = $this->next()) {
            $next->member->notifyByMail(new CircuitOnItsWay($next));
        }
    }

    public function isOverdue(): bool
    {
        $sent = $this->sentOn();

        return $this->received_at === null
            && $sent !== null
            && $sent->copy()->addDays((int) config('cccc.overdue_days'))->isPast();
    }
}
