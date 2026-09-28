<?php

namespace App\Enums;

/** Account roles. Add a case here to introduce a new role. */
enum Role: string
{
    case Admin = 'admin';
    case OriginatingMember = 'om';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Administrator'),
            self::OriginatingMember => __('Originating Member'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => __('Full access: all circuits, all members, user accounts and roles.'),
            self::OriginatingMember => __('Creates circuits (as their own Originating Member) and sees the member directory.'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'red',
            self::OriginatingMember => 'blue',
        };
    }
}
