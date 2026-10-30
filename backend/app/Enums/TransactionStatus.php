<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'รออนุมัติ',
            self::Approved => 'อนุมัติแล้ว',
            self::Rejected => 'ไม่อนุมัติ',
            self::Cancelled => 'ยกเลิก',
        };
    }
}
