<?php

namespace App\Enums;

/** หมวดของหน้ากฎ เรียงตามลำดับที่แสดง (กฎหมวดสภา CC ฉบับ 1.2 จาก https://official.wavevycity.com/rules#cat-CC) */
enum RuleSection: string
{
    case Cc = 'cc';
    case Visit = 'visit';
    case Evid = 'evid';
    case Fees = 'fees';
    case Summon = 'summon';
    case Reg = 'reg';
    case Gang = 'gang';
    case Buy = 'buy';
    case Deal = 'deal';
    case Match = 'match';
    case Prot = 'prot';
    case Max = 'max';
    case Duty = 'duty';

    public function label(): string
    {
        return match ($this) {
            self::Cc => 'CC · เกี่ยวกับสภา',
            self::Visit => 'VISIT · กฎสภา',
            self::Evid => 'EVID · หลักฐานที่ส่งสภา',
            self::Fees => 'อัตราค่าบริการ',
            self::Summon => 'SUMMON · ประกาศเรียก',
            self::Reg => 'REG · ขึ้นทะเบียนแก๊งและครอบครัว',
            self::Gang => 'GANG · สมาชิกและสัญลักษณ์แก๊ง',
            self::Buy => 'BUY · ซื้อเรื่องและเปิดสตอรี่',
            self::Deal => 'DEAL · สัญญาสตอรี่',
            self::Match => 'MATCH · ระหว่างเล่นสตอรี่',
            self::Prot => 'PROT · Protect และการจบสตอรี่',
            self::Max => 'MAX · มาตรการและบทลงโทษของสภา',
            self::Duty => 'DUTY · หน้าที่ของเจ้าหน้าที่สภา',
        };
    }

    /** กลุ่มในสารบัญ: จัดหมวดย่อยที่เกี่ยวข้องกันไว้ด้วยกัน */
    public function group(): string
    {
        return match ($this) {
            self::Cc, self::Visit, self::Evid => 'กฎสภา',
            self::Fees => 'ค่าบริการ',
            self::Summon => 'การประกาศเรียก',
            self::Reg, self::Gang => 'ทะเบียนแก๊งและครอบครัว',
            self::Buy, self::Deal, self::Match, self::Prot => 'สตอรี่',
            self::Max, self::Duty => 'มาตรการและเจ้าหน้าที่',
        };
    }

    public function sourceUrl(): string
    {
        return 'https://official.wavevycity.com/rules#cat-CC';
    }
}
