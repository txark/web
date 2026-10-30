<?php

namespace App\Enums;

/** ชนิดธุรกรรมของแก๊ง/ครอบครัว */
enum TransactionKind: string
{
    case Register = 'register';
    case Rename = 'rename';
    case Recolor = 'recolor';
    case ChangeLeader = 'change_leader';
    case ReleaseSlot = 'release_slot';
    case ReleaseFree = 'release_free';
    case Dissolve = 'dissolve';
    case Outfit = 'outfit';
    case OutfitAdd = 'outfit_add';     // เดิม: รวมเข้า Outfit แล้ว (คงไว้อ่านธุรกรรมเก่า)
    case OutfitCounts = 'outfit_counts';
    case OutfitFile = 'outfit_file';   // เดิม: รวมเข้า Outfit แล้ว (คงไว้อ่านธุรกรรมเก่า)

    public function label(): string
    {
        return match ($this) {
            self::Register => 'ขึ้นทะเบียน',
            self::Rename => 'เปลี่ยนชื่อ',
            self::Recolor => 'เปลี่ยนสี',
            self::ChangeLeader => 'เปลี่ยน Leader',
            self::ReleaseSlot => 'ออกสล็อต',
            self::ReleaseFree => 'ออกลอย',
            self::Dissolve => 'ยุบ',
            self::Outfit => 'ลงชุด/แก้ไขชุด',
            self::OutfitAdd => 'ลงชุดเพิ่ม',
            self::OutfitCounts => 'แก้จำนวนชุด',
            self::OutfitFile => 'เปลี่ยนไฟล์ชุด',
        };
    }

    /** ชนิดที่ต้องระบุกลุ่ม (ทุกชนิดยกเว้นขึ้นทะเบียน) */
    public function needsGroup(): bool
    {
        return $this !== self::Register;
    }
}
