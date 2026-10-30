<?php

namespace Tests\Unit;

use App\Support\PenaltyAmount;
use PHPUnit\Framework\TestCase;

class PenaltyAmountTest extends TestCase
{
    public function test_a_single_amount_is_suggested(): void
    {
        $p = PenaltyAmount::parse('ฝ่าฝืนปรับ 500,000 WVDL / รายบุคคล');
        $this->assertSame(500000, $p['suggested']);
        $this->assertSame([500000], $p['amounts']);
    }

    public function test_a_range_or_several_amounts_are_not_auto_suggested(): void
    {
        $range = PenaltyAmount::parse('ฝ่าฝืนปรับ 500,000 - 5,000,000 WVDL / รายบุคคล หรือบำเพ็ญ 1,000 ไม้');
        $this->assertNull($range['suggested']);
        $this->assertSame([500000, 5000000], $range['amounts']);
        $this->assertSame(500000, $range['min']);
        $this->assertSame(5000000, $range['max']);

        $steps = PenaltyAmount::parse('ครั้งที่ 1 ปรับ 1,000,000 WVDL / ครั้งที่ 2 ปรับ 2,000,000 WVDL');
        $this->assertNull($steps['suggested']);
        $this->assertSame([1000000, 2000000], $steps['amounts']);
    }

    public function test_penance_units_are_read_from_the_penalty_text(): void
    {
        $p = PenaltyAmount::parse('ปรับ 1,000,000 WVDL ต่อคน หรือบำเพ็ญ 1,000 ไม้ ต่อคน');
        $this->assertSame(1000000, $p['suggested']);
        $this->assertSame(1000, $p['penance']['suggested']);
        $this->assertSame([1000], $p['penance']['units']);

        $only = PenaltyAmount::parse('ฝ่าฝืนบำเพ็ญ 1,000 ไม้ / 1 คำ');
        $this->assertSame([], $only['amounts']);
        $this->assertSame(1000, $only['penance']['suggested']);

        $none = PenaltyAmount::parse('ใบเหลือง');
        $this->assertNull($none['penance']['suggested']);
        $this->assertSame([], $none['penance']['units']);
    }

    public function test_other_units_and_non_money_penalties_are_ignored(): void
    {
        $this->assertSame([2000000], PenaltyAmount::parse('ฝ่าฝืนปรับ 2,000,000 WVDL บำเพ็ญ 2,000 ไม้')['amounts']);
        $this->assertSame([], PenaltyAmount::parse('ใบเหลือง')['amounts']);
        $this->assertSame([], PenaltyAmount::parse('บำเพ็ญ 1,000 ไม้ / 1 คำ')['amounts']);
        $this->assertSame([], PenaltyAmount::parse(null)['amounts']);
        $this->assertNull(PenaltyAmount::parse('ใบแดง')['suggested']);
    }
}
