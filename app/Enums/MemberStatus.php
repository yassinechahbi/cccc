<?php

namespace App\Enums;

enum MemberStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case ReturnedMail = 'returned_mail';
    case Resigned = 'resigned';
    case Deceased = 'deceased';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Inactive => __('Inactive'),
            self::ReturnedMail => __('Returned mail'),
            self::Resigned => __('Resigned'),
            self::Deceased => __('Deceased'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Inactive => 'zinc',
            self::ReturnedMail => 'amber',
            self::Resigned, self::Deceased => 'red',
        };
    }
}
