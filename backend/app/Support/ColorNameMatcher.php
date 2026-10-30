<?php

namespace App\Support;

/**
 * ตรวจว่า "ชื่อสี" ตรงกับโค้ด HEX จริงไหม (เช่น #FF0000 ต้องไม่ใช้ชื่อ "เขียว")
 *
 * วิธีคิด: แปลง HEX เป็น HSL แล้วหาว่าเป็นโทนสีไหน (อนุญาตโทนข้างเคียงที่ใกล้เคียง) จากนั้นชื่อสีต้องมีคำบอกสีหลัก
 * อย่างน้อยหนึ่งคำที่ตรงกับโทนนั้น เช่น "เขียวมรกต" "ม่วงน้ำเงิน" "Dark Red"
 * ชื่อที่ไม่มีคำบอกสีหลักเลย (เช่น "มรกต") จะไม่ผ่าน เพื่อให้ตรวจได้
 * หมายเหตุ: ตรรกะเดียวกันถูกจำลองไว้ใน frontend/js/pages/manage-groups.js เพื่อแจ้งเตือนก่อนส่ง (ตัวตัดสินจริงคือที่นี่)
 */
class ColorNameMatcher
{
    /** @var array<string, list<string>> โทนสี → คำที่ใช้เรียก (ตัวพิมพ์เล็ก) */
    private const WORDS = [
        'red' => ['แดง', 'red', 'crimson', 'scarlet', 'maroon'],
        'orange' => ['ส้ม', 'orange'],
        'yellow' => ['เหลือง', 'yellow'],
        'gold' => ['ทอง', 'gold'],
        'green' => ['เขียว', 'green', 'lime'],
        'cyan' => ['ฟ้า', 'cyan', 'sky', 'teal', 'turquoise'],
        'blue' => ['น้ำเงิน', 'blue', 'navy'],
        'purple' => ['ม่วง', 'purple', 'violet', 'lavender'],
        'pink' => ['ชมพู', 'pink', 'magenta', 'บานเย็น'],
        'brown' => ['น้ำตาล', 'brown'],
        'black' => ['ดำ', 'black'],
        'white' => ['ขาว', 'white'],
        'gray' => ['เทา', 'gray', 'grey'],
        'silver' => ['เงิน', 'silver'],
    ];

    /** @var array<string, string> ชื่อโทนสีสำหรับข้อความแจ้ง */
    private const LABELS = [
        'red' => 'แดง', 'orange' => 'ส้ม', 'yellow' => 'เหลือง', 'gold' => 'ทอง', 'green' => 'เขียว', 'cyan' => 'ฟ้า',
        'blue' => 'น้ำเงิน', 'purple' => 'ม่วง', 'pink' => 'ชมพู', 'brown' => 'น้ำตาล', 'black' => 'ดำ', 'white' => 'ขาว',
        'gray' => 'เทา', 'silver' => 'เงิน',
    ];

    /** @return string|null ข้อความผิดพลาดภาษาไทย หรือ null ถ้าชื่อสีตรงกับโค้ด */
    public static function check(string $hex, string $name): ?string
    {
        $accepted = self::tonesFor($hex);
        if ($accepted === null) {
            return null; // โค้ดไม่ถูกต้อง: ให้กฎรูปแบบ HEX แจ้งเอง
        }

        $found = self::tonesIn($name);
        if ($found === []) {
            return 'ชื่อสีต้องมีคำบอกสีหลัก เช่น '.self::examples($accepted);
        }
        if (array_intersect($found, $accepted) === []) {
            $named = implode(' / ', array_map(fn ($t) => self::LABELS[$t], $found));

            return 'ชื่อสี "'.trim($name).'" ('.$named.') ไม่ตรงกับโค้ด '.strtoupper($hex).' — โค้ดนี้เป็นโทนสี '.self::examples($accepted);
        }

        return null;
    }

    /** @return list<string>|null โทนสีที่ยอมรับสำหรับ HEX นี้ (null ถ้า HEX ไม่ถูกต้อง) */
    public static function tonesFor(string $hex): ?array
    {
        if (! preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            return null;
        }
        [$r, $g, $b] = [hexdec($m[1]) / 255, hexdec($m[2]) / 255, hexdec($m[3]) / 255];
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $s = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1));

        if ($d == 0) {
            $h = 0;
        } elseif ($max === $r) {
            $h = 60 * fmod((($g - $b) / $d), 6);
        } elseif ($max === $g) {
            $h = 60 * ((($b - $r) / $d) + 2);
        } else {
            $h = 60 * ((($r - $g) / $d) + 4);
        }
        $h = fmod($h + 360, 360);

        // สีเทา/ขาว/ดำ (ความอิ่มตัวต่ำ หรือมืด/สว่างมาก)
        if ($s < 0.12 || $l < 0.1 || $l > 0.94) {
            $t = ['gray'];
            if ($l < 0.3) {
                $t[] = 'black';
            }
            if ($l > 0.75) {
                $t[] = 'white';
            }
            if ($l >= 0.5) {
                $t[] = 'silver';
            }

            return $t;
        }

        $t = match (true) {
            $h < 12 || $h >= 348 => ['red'],
            $h < 40 => array_merge(['orange'], $h < 22 ? ['red'] : [], $h >= 30 ? ['gold'] : []),
            $h < 68 => array_merge(['yellow', 'gold'], $h < 48 ? ['orange'] : []),
            $h < 95 => ['yellow', 'green'],
            $h < 165 => ['green'],
            $h < 195 => ['green', 'cyan'],
            $h < 250 => ['cyan', 'blue'],
            $h < 270 => ['blue', 'purple'],
            $h < 300 => ['purple'],
            default => array_merge(['pink', 'purple'], $h >= 330 ? ['red'] : []),
        };

        // น้ำตาล = โทนส้ม/แดง/เหลืองที่มืด; ชมพู = โทนแดงที่สว่าง
        if ($h < 48 && $l < 0.45) {
            $t[] = 'brown';
        }
        if (($h < 15 || $h >= 330) && $l > 0.7) {
            $t[] = 'pink';
        }

        return array_values(array_unique($t));
    }

    /** @return list<string> โทนสีที่พบในชื่อ */
    public static function tonesIn(string $name): array
    {
        $n = mb_strtolower($name);
        // "น้ำเงิน" มีคำว่า "เงิน" อยู่ข้างใน จึงตัดออกก่อนหาคำว่าเงิน (silver)
        $withoutBlue = str_replace('น้ำเงิน', ' ', $n);

        $found = [];
        foreach (self::WORDS as $tone => $words) {
            $haystack = $tone === 'silver' ? $withoutBlue : $n;
            foreach ($words as $w) {
                if (str_contains($haystack, $w)) {
                    $found[] = $tone;
                    break;
                }
            }
        }

        return $found;
    }

    /** @param  list<string>  $tones */
    private static function examples(array $tones): string
    {
        return implode(' / ', array_map(fn ($t) => self::LABELS[$t], $tones));
    }
}
