<?php

namespace App\Enums;

enum Role: string
{
    case Gang = 'gang';
    case Family = 'family';
    case Member = 'member';
    case VicePresident = 'vice_president';
    case President = 'president';
    case Admin = 'admin';

    /** ระดับสิทธิ์: ยิ่งมากยิ่งสูง (Gang กับ Family สิทธิ์เท่ากัน ต่างกันที่ประเภทของกลุ่มเท่านั้น) */
    public function level(): int
    {
        return match ($this) {
            self::Gang, self::Family => 1,
            self::Member => 2,
            self::VicePresident => 3,
            self::President => 4,
            self::Admin => 5,
        };
    }

    /** ชื่อที่แสดงบนป้ายบทบาท */
    public function label(): string
    {
        return match ($this) {
            self::Gang => 'Gang',
            self::Family => 'Family',
            self::Member => 'Council',
            self::VicePresident => 'Sub-CC',
            self::President => 'Head-CC',
            self::Admin => 'Admin',
        };
    }

    /** ชื่อเต็มภาษาไทย ใช้ในประโยคและคำอธิบาย */
    public function thaiName(): string
    {
        return match ($this) {
            self::Gang => 'แก๊ง',
            self::Family => 'ครอบครัว',
            self::Member => 'สมาชิกสภา',
            self::VicePresident => 'รองประธาน',
            self::President => 'ประธาน',
            self::Admin => 'แอดมินดูแลเว็บ',
        };
    }

    public function atLeast(self $min): bool
    {
        return $this->level() >= $min->level();
    }

    /**
     * บทบาทที่ผู้ที่มีบทบาทนี้ "กำหนดให้คนอื่นได้": ต่ำกว่าตัวเองเท่านั้น
     * กันการยกระดับสิทธิ์ตัวเองหรือเพื่อน (Sub-CC ตั้งได้ถึง Council, Head-CC ถึง Sub-CC, Admin ถึง Head-CC)
     * ส่วน Admin คนใหม่กำหนดผ่าน ADMIN_DISCORD_IDS ใน .env เท่านั้น
     *
     * @return list<self>
     */
    public function assignable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $r) => $r->level() < $this->level()));
    }

    public function canAssign(self $role): bool
    {
        return in_array($role, $this->assignable(), true);
    }
}
