<?php

namespace App\Enums;

enum GroupType: string
{
    case Gang = 'gang';
    case Family = 'family';

    public function label(): string
    {
        return match ($this) {
            self::Gang => 'แก๊ง',
            self::Family => 'ครอบครัว',
        };
    }
}
