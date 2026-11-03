<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * สูตรโบนัสเวรที่ผู้ใช้พิมพ์เอง เช่น "(เวลาเข้าเวร ÷ 1440) × paycheck + โบนัสพิเศษ"
 *
 * รองรับ + − × ÷ (หรือ - * /) วงเล็บ ตัวเลข และตัวแปรชื่อภาษาไทย/อังกฤษ ไม่มี eval — ใช้ตัวแยกวิเคราะห์ (recursive descent) ของตัวเอง
 * ตัวแปร "เวลาเข้าเวร" = นาทีเวรของแต่ละคน (ระบบใส่ให้) ตัวแปรอื่นเป็นค่าเดียวกันทุกคน (ตั้งในหน้าตัดรอบ)
 * ผลลัพธ์ปัดลงเป็นจำนวนเต็ม (ปัดทศนิยม 6 ตำแหน่งก่อนเพื่อกันเศษลอยตัว) ติดลบนับเป็น 0
 */
class PayFormula
{
    public const MINUTES = 'เวลาเข้าเวร';

    private const MAX_LENGTH = 300;

    private const MAX_TOKENS = 200;

    private const MAX_DEPTH = 40;

    /** @var list<array{0: string, 1: string}> */
    private array $tokens = [];

    private int $pos = 0;

    /**
     * แยกสูตรเป็นต้นไม้นิพจน์ (โยน InvalidArgumentException พร้อมข้อความภาษาไทยถ้าสูตรผิด)
     *
     * @return array<string, mixed>
     */
    public static function parse(string $source): array
    {
        $p = new self;
        $p->tokens = $p->tokenize($source);
        $p->pos = 0;
        $ast = $p->expression(0);
        if ($p->pos < count($p->tokens)) {
            throw new InvalidArgumentException($p->tokens[$p->pos][1] === ')' ? 'มีวงเล็บปิดเกินมา' : 'สูตรไม่ถูกต้อง ใกล้ "'.$p->tokens[$p->pos][1].'"');
        }

        return $ast;
    }

    /**
     * ชื่อตัวแปรทั้งหมดที่สูตรใช้ (ไม่ซ้ำ)
     *
     * @param  array<string, mixed>  $ast
     * @return list<string>
     */
    public static function variables(array $ast): array
    {
        return match ($ast['t']) {
            'var' => [$ast['name']],
            'num' => [],
            'neg' => self::variables($ast['a']),
            default => array_values(array_unique([...self::variables($ast['a']), ...self::variables($ast['b'])])),
        };
    }

    /**
     * คำนวณสูตรด้วยค่าตัวแปร
     *
     * @param  array<string, mixed>  $ast
     * @param  array<string, float|int>  $vars
     */
    public static function evaluate(array $ast, array $vars): float
    {
        switch ($ast['t']) {
            case 'num':
                return $ast['v'];
            case 'var':
                return (float) ($vars[$ast['name']] ?? 0);
            case 'neg':
                return -self::evaluate($ast['a'], $vars);
        }
        $a = self::evaluate($ast['a'], $vars);
        $b = self::evaluate($ast['b'], $vars);

        return match ($ast['t']) {
            'add' => $a + $b,
            'sub' => $a - $b,
            'mul' => $a * $b,
            'div' => $b == 0.0 ? throw new InvalidArgumentException('สูตรหารด้วยศูนย์') : $a / $b,
        };
    }

    /** ผลลัพธ์เป็นจำนวนเงินเต็ม (ปัดลง ไม่ติดลบ) */
    public static function amount(float $value): int
    {
        return max(0, (int) min(floor(round($value, 6)), 999999999999));
    }

    /** ชื่อตัวแปรที่ตั้งได้: ขึ้นต้นด้วยตัวอักษรหรือ _ ตามด้วยตัวอักษร/สระวรรณยุกต์/ตัวเลข/_ */
    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^[\p{L}_][\p{L}\p{M}\p{N}_]*$/u', $name);
    }

    // ───────────────────────── ภายใน ─────────────────────────

    /** @return list<array{0: string, 1: string}> */
    private function tokenize(string $src): array
    {
        $src = trim(strtr($src, ['−' => '-', '–' => '-', '×' => '*', '÷' => '/', '（' => '(', '）' => ')']));
        if ($src === '') {
            throw new InvalidArgumentException('กรุณากรอกสูตร');
        }
        if (mb_strlen($src) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('สูตรยาวเกินไป (ไม่เกิน '.self::MAX_LENGTH.' ตัวอักษร)');
        }

        $tokens = [];
        $offset = 0;
        $len = strlen($src);
        while ($offset < $len) {
            if (! preg_match('/\G\s*(?:(\d+(?:\.\d+)?)|([\p{L}_][\p{L}\p{M}\p{N}_]*)|([+\-*\/()]))/Au', $src, $m, 0, $offset)) {
                if (trim(substr($src, $offset)) === '') {
                    break;
                }
                $bad = mb_substr(ltrim(substr($src, $offset)), 0, 1);
                throw new InvalidArgumentException('ตัวอักษรที่ไม่รองรับในสูตร: "'.$bad.'" (ใช้ได้เฉพาะ + − × ÷ วงเล็บ ตัวเลข และชื่อตัวแปร)');
            }
            $offset += strlen($m[0]);
            $tokens[] = isset($m[3]) && $m[3] !== '' ? ['op', $m[3]] : (isset($m[2]) && $m[2] !== '' ? ['id', $m[2]] : ['num', $m[1]]);
            if (count($tokens) > self::MAX_TOKENS) {
                throw new InvalidArgumentException('สูตรซับซ้อนเกินไป');
            }
        }

        // คืนรูป [ชนิด, ข้อความ] โดยตัวดำเนินการ/วงเล็บใช้ข้อความเป็นชนิดด้วยตอนอ่าน
        return array_map(fn (array $t) => $t[0] === 'op' ? [$t[1], $t[1]] : $t, $tokens);
    }

    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    /** @return array<string, mixed> */
    private function expression(int $depth): array
    {
        $this->guard($depth);
        $left = $this->term($depth);
        while (($t = $this->peek()) && in_array($t[0], ['+', '-'], true)) {
            $this->pos++;
            $left = ['t' => $t[0] === '+' ? 'add' : 'sub', 'a' => $left, 'b' => $this->term($depth)];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function term(int $depth): array
    {
        $left = $this->unary($depth);
        while (($t = $this->peek()) && in_array($t[0], ['*', '/'], true)) {
            $this->pos++;
            $left = ['t' => $t[0] === '*' ? 'mul' : 'div', 'a' => $left, 'b' => $this->unary($depth)];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function unary(int $depth): array
    {
        $this->guard($depth);
        $t = $this->peek();
        if ($t && $t[0] === '-') {
            $this->pos++;

            return ['t' => 'neg', 'a' => $this->unary($depth + 1)];
        }
        if ($t && $t[0] === '+') {
            $this->pos++;

            return $this->unary($depth + 1);
        }

        return $this->primary($depth);
    }

    /** @return array<string, mixed> */
    private function primary(int $depth): array
    {
        $t = $this->peek();
        if (! $t) {
            throw new InvalidArgumentException('สูตรไม่สมบูรณ์ (ขาดตัวเลข ตัวแปร หรือวงเล็บปิดท้ายสูตร)');
        }
        $this->pos++;
        if ($t[0] === 'num') {
            return ['t' => 'num', 'v' => (float) $t[1]];
        }
        if ($t[0] === 'id') {
            return ['t' => 'var', 'name' => $t[1]];
        }
        if ($t[0] === '(') {
            $inner = $this->expression($depth + 1);
            $close = $this->peek();
            if (! $close || $close[0] !== ')') {
                throw new InvalidArgumentException('วงเล็บเปิดไม่มีวงเล็บปิด');
            }
            $this->pos++;

            return $inner;
        }

        throw new InvalidArgumentException('สูตรไม่ถูกต้อง ใกล้ "'.$t[1].'"');
    }

    private function guard(int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('สูตรซ้อนวงเล็บมากเกินไป');
        }
    }
}
