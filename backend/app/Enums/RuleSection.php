<?php

namespace App\Enums;

/** หมวดของหน้ากฎ เรียงตามลำดับที่แสดง (ตามลำดับเมนูของเว็บ WAVEVY COUNCIL เดิม) */
enum RuleSection: string
{
    // หน้า "กฏสภา"
    case General = 'general';
    case Supreme = 'supreme';
    case Ban = 'ban';
    case Conversion = 'conversion';
    case Blacklist = 'blacklist';
    case Sanction = 'sanction';

    // หน้าอื่นของเว็บเดิม
    case Call = 'call';
    case Story = 'story';
    case Protect = 'protect';
    case Gang = 'gang';
    case Family = 'family';
    case Fees = 'fees';
    case Overview = 'overview';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::General => 'กฎสภา',
            self::Supreme => 'มาตรการสูงสุดของสภา',
            self::Ban => 'การตัดสิทธิ์การเข้าร่วมกิจกรรม',
            self::Conversion => 'โทษบำเพ็ญของสภา',
            self::Blacklist => 'กฎ Blacklist',
            self::Sanction => 'กฎการคว่ำบาตร',
            self::Call => 'ประกาศเรียก',
            self::Story => 'กฎการเล่นสตอรี่',
            self::Protect => 'Protect',
            self::Fees => 'ค่าบริการสภา',
            self::Gang => 'ขึ้นทะเบียนแก๊ง',
            self::Family => 'ขึ้นทะเบียนครอบครัว',
            self::Overview => 'เกี่ยวกับสภา',
            self::Staff => 'กฎการปฏิบัติหน้าที่ของสภา',
        };
    }

    /** กลุ่มในสารบัญ: จัดหมวดย่อยที่เกี่ยวข้องกันไว้ด้วยกัน */
    public function group(): string
    {
        return match ($this) {
            self::General, self::Supreme, self::Ban, self::Conversion, self::Blacklist, self::Sanction => 'กฎสภา',
            self::Call, self::Story, self::Protect => 'สตอรี่และการประกาศเรียก',
            self::Fees, self::Gang, self::Family => 'ทะเบียนและค่าบริการ',
            self::Overview, self::Staff => 'เกี่ยวกับสภา',
        };
    }

    /** ส่วนท้าย URL ของหน้าต้นฉบับบน Google Sites (ตามที่เว็บเดิมใช้จริง) */
    private function sourceSlug(): string
    {
        return match ($this) {
            self::General, self::Supreme, self::Ban, self::Conversion, self::Blacklist, self::Sanction => 'กฏสภา',
            self::Call => 'ประกาศเรยก',
            self::Story => 'กฏการเลนสตอร',
            self::Protect => 'protect',
            self::Fees => 'คาบรการสภา',
            self::Gang, self::Family => 'เกยวกบ',
            self::Overview, self::Staff => 'home',
        };
    }

    public function sourceUrl(): string
    {
        return 'https://sites.google.com/view/wavevycouncil/'.rawurlencode($this->sourceSlug());
    }
}
