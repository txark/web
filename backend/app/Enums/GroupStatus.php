<?php

namespace App\Enums;

enum GroupStatus: string
{
    case Normal = 'normal';
    case Blacklist = 'blacklist';
    case Sanction = 'sanction';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'ปกติ',
            self::Blacklist => 'Blacklist',
            self::Sanction => 'คว่ำบาตร',
        };
    }
}
