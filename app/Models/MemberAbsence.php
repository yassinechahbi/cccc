<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A period when a member cannot receive circuits (holidays, hospital, travel...). */
class MemberAbsence extends Model
{
    protected $fillable = ['starts_on', 'ends_on', 'note'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function isCurrent(): bool
    {
        return $this->starts_on->isPast() || $this->starts_on->isToday();
    }
}
