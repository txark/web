<?php

namespace Tests\Unit;

use App\Services\PayFormula;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ตัวคำนวณสูตรโบนัสเวร: ลำดับการคำนวณ วงเล็บ เครื่องหมายหลายแบบ ตัวแปรภาษาไทย และข้อผิดพลาด */
class PayFormulaTest extends TestCase
{
    private function calc(string $formula, array $vars = []): float
    {
        return PayFormula::evaluate(PayFormula::parse($formula), $vars);
    }

    public function test_arithmetic_follows_precedence_and_parentheses(): void
    {
        $this->assertSame(7.0, $this->calc('1 + 2 * 3'));
        $this->assertSame(9.0, $this->calc('(1 + 2) * 3'));
        $this->assertSame(2.5, $this->calc('10 / 4'));
        $this->assertSame(1.0, $this->calc('10 - 4 - 5'));            // ซ้ายไปขวา
        $this->assertSame(2.0, $this->calc('16 / 4 / 2'));
        $this->assertSame(-3.0, $this->calc('-3'));
        $this->assertSame(5.0, $this->calc('+5'));
        $this->assertSame(-6.0, $this->calc('2 * -3'));
        $this->assertSame(3.0, $this->calc('--3'));
        $this->assertSame(8.0, $this->calc('((2 + 2)) * (1 + 1)'));
        $this->assertSame(1.5, $this->calc('1.5'));
    }

    public function test_thai_and_ascii_operators_are_equivalent(): void
    {
        $this->assertSame($this->calc('(100 ÷ 4) × 3 − 5'), $this->calc('(100 / 4) * 3 - 5'));
        $this->assertSame(70.0, $this->calc('(100 ÷ 4) × 3 − 5'));
        $this->assertSame(7.0, $this->calc('（1 + 2） * 3 - 2'));      // วงเล็บเต็มความกว้าง
    }

    public function test_variables_including_the_minutes_variable_and_thai_names(): void
    {
        $vars = [PayFormula::MINUTES => 2140, 'paycheck' => 300000, 'โบนัสพิเศษ' => 50000];
        $v = $this->calc('(เวลาเข้าเวร ÷ 1440) × paycheck + โบนัสพิเศษ', $vars);
        $this->assertSame(495833, PayFormula::amount($v));                // ตัวอย่างในแบบร่าง: ปวีณา 2,140 นาที
        $this->assertSame(0.0, $this->calc('missing + 0'));               // ตัวแปรที่ไม่มีค่านับเป็น 0
        $this->assertSame(['เวลาเข้าเวร', 'paycheck', 'โบนัสพิเศษ'], PayFormula::variables(PayFormula::parse('(เวลาเข้าเวร ÷ 1440) × paycheck + โบนัสพิเศษ + paycheck')));
        $this->assertSame(['a_1', 'ค่า2'], PayFormula::variables(PayFormula::parse('a_1 * ค่า2')));
    }

    public function test_amount_floors_clamps_and_ignores_float_noise(): void
    {
        $this->assertSame(445833, PayFormula::amount(445833.3333));
        $this->assertSame(3, PayFormula::amount(2.9999999999));           // เศษลอยตัวไม่ทำให้ปัดลงผิด
        $this->assertSame(0, PayFormula::amount(-12.5));
        $this->assertSame(0, PayFormula::amount(0.4));
        $this->assertSame(999999999999, PayFormula::amount(1e15));
        $this->assertSame(3, PayFormula::amount($this->calc('0.1 * 30')));
    }

    #[DataProvider('badFormulas')]
    public function test_invalid_formulas_are_rejected_with_a_message(string $formula, string $contains): void
    {
        try {
            $this->calc($formula, ['a' => 1]);
            $this->fail("ควรปฏิเสธสูตร: {$formula}");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badFormulas(): array
    {
        return [
            'ว่าง' => ['   ', 'กรุณากรอกสูตร'],
            'วงเล็บเปิดไม่ปิด' => ['(1 + 2', 'วงเล็บ'],
            'วงเล็บปิดเกิน' => ['1 + 2)', 'วงเล็บปิดเกิน'],
            'ขาดตัวถูกดำเนินการ' => ['1 +', 'ไม่สมบูรณ์'],
            'เครื่องหมายซ้อน' => ['1 * / 2', 'ไม่ถูกต้อง'],
            'ตัวเลขสองตัวติดกัน' => ['1 2', 'ไม่ถูกต้อง'],
            'ตัวอักษรแปลก' => ['1 + 2 ^ 3', 'ไม่รองรับ'],
            'เครื่องหมายเปอร์เซ็นต์' => ['5 %', 'ไม่รองรับ'],
            'หารด้วยศูนย์' => ['10 / (a - 1)', 'หารด้วยศูนย์'],
            'ยาวเกิน' => [str_repeat('1+', 160).'1', 'ยาวเกินไป'],
            'ซ้อนวงเล็บลึกเกิน' => [str_repeat('(', 60).'1'.str_repeat(')', 60), 'ซ้อนวงเล็บมากเกินไป'],
        ];
    }

    public function test_variable_name_rules(): void
    {
        foreach (['paycheck', 'โบนัสพิเศษ', 'a1', '_x', 'ค่าตอบแทน_1', 'เงินเดือน'] as $ok) {
            $this->assertTrue(PayFormula::validName($ok), $ok);
        }
        foreach (['', '1a', 'a b', 'a-b', 'a+b', 'x!', '(a)'] as $bad) {
            $this->assertFalse(PayFormula::validName($bad), $bad);
        }
    }
}
