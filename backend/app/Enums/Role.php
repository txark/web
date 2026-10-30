<?php

namespace App\Enums;

enum Role: string
{
    case Gang = 'gang';
    case Family = 'family';
    case Trainee = 'trainee';        // สภาฝึกหัด
    case Member = 'member';          // สภา
    case Operations = 'operations';  // สภาปฏิบัติการ
    case Senior = 'senior';          // สภาอาวุโส
    case Secretary = 'secretary';    // เลขา
    case VicePresident = 'vice_president';
    case President = 'president';
    case Admin = 'admin';

    /** ระดับสิทธิ์: ยิ่งมากยิ่งสูง (Gang กับ Family สิทธิ์เท่ากัน ต่างกันที่ประเภทของกลุ่มเท่านั้น) */
    public function level(): int
    {
        return match ($this) {
            self::Gang, self::Family => 1,
            self::Trainee, self::Member, self::Operations, self::Senior, self::Secretary => 2, // สิทธิ์ใช้งานเท่ากันทั้ง 5 ยศ
            self::VicePresident => 3,
            self::President => 4,
            self::Admin => 0, // อยู่นอกลำดับสภา: ไม่ผ่านการตรวจสิทธิ์ระดับสภาใด ๆ (ดู atLeast)
        };
    }

    /** ชื่อที่แสดงบนป้ายบทบาท */
    public function label(): string
    {
        return match ($this) {
            self::Gang => 'Gang',
            self::Family => 'Family',
            self::Trainee => 'Trainee',
            self::Member => 'Council',
            self::Operations => 'Operations',
            self::Senior => 'Senior',
            self::Secretary => 'Secretary',
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
            self::Trainee => 'สภาฝึกหัด',
            self::Member => 'สภา',
            self::Operations => 'สภาปฏิบัติการ',
            self::Senior => 'สภาอาวุโส',
            self::Secretary => 'เลขาสภา',
            self::VicePresident => 'รองประธานสภา',
            self::President => 'ประธานสภา',
            self::Admin => 'แอดมินดูแลเว็บ',
        };
    }

    /** น้ำหนักเรียงลำดับแสดงผล (ยศในสภาเรียงจากสูงไปต่ำ) ต่างจาก level ที่ใช้ตัดสินสิทธิ์ */
    public function sortWeight(): int
    {
        return match ($this) {
            self::Gang => 10,
            self::Family => 11,
            self::Trainee => 20,
            self::Member => 30,
            self::Operations => 40,
            self::Senior => 50,
            self::Secretary => 60,
            self::VicePresident => 70,
            self::President => 80,
            self::Admin => 90,
        };
    }

    /** Admin อยู่นอกลำดับสภา: ผ่านเฉพาะการตรวจ "Admin" เอง และไม่ผ่านการตรวจสิทธิ์ระดับอื่นเลย */
    public function atLeast(self $min): bool
    {
        if ($this === self::Admin || $min === self::Admin) {
            return $this === $min;
        }

        return $this->level() >= $min->level();
    }

    public function isAdmin(): bool
    {
        return $this === self::Admin;
    }

    /** ลำดับสภา (ประธาน…สภาฝึกหัด) ไม่รวมแก๊ง/ครอบครัว/Admin */
    public function isCouncil(): bool
    {
        return $this->atLeast(self::Trainee) && ! $this->isAdmin();
    }

    /** แก้ไขบัญชีของ $target ได้ไหม: ยศสภาแก้ได้เฉพาะคนที่ระดับต่ำกว่า; Admin แก้ได้ทุกคนยกเว้น Admin ด้วยกัน; ไม่มีใครแก้ Admin ผ่านหน้าเว็บได้ */
    public function canManage(self $target): bool
    {
        if ($target->isAdmin()) {
            return false;
        }

        return $this->isAdmin() || $this->level() > $target->level();
    }

    /**
     * บทบาทที่ผู้ที่มีบทบาทนี้ "กำหนดให้คนอื่นได้": ต่ำกว่าตัวเองเท่านั้น
     * กันการยกระดับสิทธิ์ตัวเองหรือเพื่อน (รองประธานตั้งได้ถึงสภา, ประธานถึงรองประธาน)
     * Admin ตั้งยศให้ใครไม่ได้ และ Admin คนใหม่กำหนดผ่าน ADMIN_DISCORD_IDS ใน .env เท่านั้น
     *
     * @return list<self>
     */
    public function assignable(): array
    {
        // เฉพาะประธานกับรองประธานแต่งตั้ง/เปลี่ยนยศได้; Admin ตั้งยศให้ใครไม่ได้ และไม่มีใครตั้งเป็น Admin ผ่านหน้าเว็บ
        if ($this->isAdmin() || ! $this->atLeast(self::VicePresident)) {
            return [];
        }

        return array_values(array_filter(self::cases(), fn (self $r) => ! $r->isAdmin() && $r->level() < $this->level()));
    }

    public function canAssign(self $role): bool
    {
        return in_array($role, $this->assignable(), true);
    }
}
