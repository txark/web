<?php

namespace App\Enums;

enum MemberPosition: string
{
    case Leader = 'leader';
    case CoLeader = 'co_leader';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::Leader => 'หัวหน้า',
            self::CoLeader => 'รองหัวหน้า',
            self::Member => 'สมาชิก',
        };
    }

    /** ลำดับแสดงผล: น้อยก่อน */
    public function order(): int
    {
        return match ($this) {
            self::Leader => 1,
            self::CoLeader => 2,
            self::Member => 3,
        };
    }
}
