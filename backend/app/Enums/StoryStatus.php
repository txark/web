<?php

namespace App\Enums;

enum StoryStatus: string
{
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'กำลังดำเนินอยู่',
            self::Ended => 'จบแล้ว',
            self::Cancelled => 'ยกเลิก',
        };
    }
}
