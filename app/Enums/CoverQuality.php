<?php

namespace App\Enums;

enum CoverQuality: int
{
    case Superior = 5;
    case Excellent = 4;
    case VeryGood = 3;
    case Good = 2;
    case Poor = 1;

    public function label(): string
    {
        return match ($this) {
            self::Superior => __('Superior'),
            self::Excellent => __('Excellent'),
            self::VeryGood => __('Very Good'),
            self::Good => __('Good'),
            self::Poor => __('Poor'),
        };
    }

    /** The rating guide printed on the backside of every circuit. */
    public function guide(): string
    {
        return match ($this) {
            self::Superior => __('Three or more attractive stamps or souvenir sheet, or FDC, registered letter, cover from hard to obtain location. Standard size envelope with extra fine or special postmark related to the stamps.'),
            self::Excellent => __('Two or more attractive stamps, or souvenir sheet, or FDC, registered letter, cover from hard to obtain location. Standard size envelope with regular postmark.'),
            self::VeryGood => __('At least one current pictorial stamp of your interest with regular cancellation. Standard size envelope with local post office cancellation, or large envelope with multiple stamps of interest.'),
            self::Good => __('At least one stamp with regular cancellation, or cover with stamps of philatelic interest, even with unclear or multiple postmarks.'),
            self::Poor => __('Cover of NO philatelic interest. Do not downgrade a cover which is not 100% in your philatelic interest. If you grade a cover as POOR, it is reported to the Managing Director; mail the circuit to the next member in a quality cover equal to one which you would like to receive.'),
        };
    }
}
