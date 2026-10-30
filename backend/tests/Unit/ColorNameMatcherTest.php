<?php

namespace Tests\Unit;

use App\Support\ColorNameMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ColorNameMatcherTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function matching(): array
    {
        return [
            'แดงบริสุทธิ์' => ['#FF0000', 'แดง'],
            'แดงเข้ม' => ['#B91C1C', 'แดงเข้ม'],
            'เขียวมรกต' => ['#10B981', 'เขียวมรกต'],
            'เขียวล้วน' => ['#00FF00', 'เขียว'],
            'ฟ้า' => ['#0EA5E9', 'ฟ้า'],
            'น้ำเงิน' => ['#0000FF', 'น้ำเงิน'],
            'ม่วงน้ำเงิน (มีคำที่ตรงอย่างน้อย 1 คำ)' => ['#6366F1', 'ม่วงน้ำเงิน'],
            'ส้มทอง' => ['#F59E0B', 'ส้มทอง'],
            'เหลือง' => ['#FFFF00', 'เหลือง'],
            'ชมพู' => ['#FF69B4', 'ชมพู'],
            'ม่วง' => ['#800080', 'ม่วง'],
            'น้ำตาล' => ['#8B4513', 'น้ำตาล'],
            'ดำ' => ['#000000', 'ดำ'],
            'ขาว' => ['#FFFFFF', 'ขาว'],
            'เทา' => ['#808080', 'เทา'],
            'เงิน' => ['#C0C0C0', 'เงิน'],
            'อังกฤษ ตัวพิมพ์ใหญ่' => ['#FF0000', 'Dark RED'],
            'อังกฤษ green' => ['#00FF00', 'Lime Green'],
            'ตัวพิมพ์เล็กของ hex' => ['#ff0000', 'แดง'],
        ];
    }

    #[DataProvider('matching')]
    public function test_names_that_match_the_hex_pass(string $hex, string $name): void
    {
        $this->assertNull(ColorNameMatcher::check($hex, $name));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function mismatching(): array
    {
        return [
            'แดงแต่ใช้ชื่อเขียว' => ['#FF0000', 'เขียว'],
            'เขียวแต่ใช้ชื่อแดง' => ['#00FF00', 'แดง'],
            'น้ำเงินแต่ใช้ชื่อเหลือง' => ['#0000FF', 'เหลือง'],
            'ดำแต่ใช้ชื่อขาว' => ['#000000', 'ขาว'],
            'ขาวแต่ใช้ชื่อดำ' => ['#FFFFFF', 'ดำ'],
            'ม่วงแต่ใช้ชื่อส้ม' => ['#800080', 'ส้ม'],
            'อังกฤษไม่ตรง' => ['#FF0000', 'Blue'],
        ];
    }

    #[DataProvider('mismatching')]
    public function test_names_that_do_not_match_the_hex_are_rejected(string $hex, string $name): void
    {
        $message = ColorNameMatcher::check($hex, $name);
        $this->assertNotNull($message);
        $this->assertStringContainsString(strtoupper($hex), $message);
    }

    public function test_a_name_without_any_basic_color_word_is_rejected(): void
    {
        $this->assertStringContainsString('คำบอกสีหลัก', (string) ColorNameMatcher::check('#10B981', 'มรกต'));
        $this->assertStringContainsString('คำบอกสีหลัก', (string) ColorNameMatcher::check('#FF0000', 'abc'));
    }

    public function test_silver_does_not_match_inside_the_word_blue(): void
    {
        // "น้ำเงิน" มีคำว่า "เงิน" อยู่ข้างใน แต่ต้องไม่ถูกนับเป็นสีเงิน
        $this->assertSame(['blue'], ColorNameMatcher::tonesIn('น้ำเงิน'));
        $this->assertNotNull(ColorNameMatcher::check('#FF0000', 'น้ำเงิน'));
    }

    public function test_invalid_hex_is_left_to_the_format_rule(): void
    {
        $this->assertNull(ColorNameMatcher::check('red', 'เขียว'));
    }
}
