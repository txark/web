<?php

namespace App\Enums;

enum AnnouncementType: string
{
    case General = 'general';
    case Summon = 'summon';
    case Fine = 'fine';
    case Penalty = 'penalty';
    case Important = 'important'; // ประกาศสำคัญ (ประกาศทั่วไปแบบสำคัญ — โพสต์ช่อง ประกาศสำคัญจากสภา)
    case Event = 'event'; // ประเภทเดิม (general/event) ยังแสดงและแก้ไขได้ แต่หน้าเว็บสร้างใหม่ได้เฉพาะ เรียก/ปรับ/โทษ

    public function label(): string
    {
        return match ($this) {
            self::General => 'ประกาศธรรมดา',
            self::Important => 'ประกาศสำคัญ',
            self::Summon => 'ประกาศเรียก',
            self::Fine => 'ประกาศปรับ',
            self::Penalty => 'ประกาศโทษ',
            self::Event => 'กิจกรรม',
        };
    }
}
