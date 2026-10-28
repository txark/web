<?php

namespace App\Enums;

enum AnnouncementType: string
{
    case General = 'general';
    case Summon = 'summon';
    case Event = 'event';

    public function label(): string
    {
        return match ($this) {
            self::General => 'ประกาศทั่วไป',
            self::Summon => 'ประกาศเรียก',
            self::Event => 'กิจกรรม',
        };
    }
}
