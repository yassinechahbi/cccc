<?php

namespace App\Enums;

enum CircuitStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Lost = 'lost';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::InProgress => __('In progress'),
            self::Completed => __('Completed'),
            self::Lost => __('Lost'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::InProgress => 'blue',
            self::Completed => 'green',
            self::Lost => 'red',
            self::Cancelled => 'zinc',
        };
    }
}
