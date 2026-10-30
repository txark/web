<?php

namespace App\Enums;

enum GroupStatus: string
{
    case Normal = 'normal';
    case Blacklist = 'blacklist';
    case Sanction = 'sanction';
    case Inactive = 'inactive'; // ขาดการเคลื่อนไหว (ตั้งจากปุ่มรายงานตัว)

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'ปกติ',
            self::Blacklist => 'Blacklist',
            self::Sanction => 'คว่ำบาตร',
            self::Inactive => 'ขาดการเคลื่อนไหว',
        };
    }
}
