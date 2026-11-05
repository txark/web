<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** ลิงก์รูปต้องมาจาก https://images.wavevycity.com เท่านั้น (ไม่รับรูปจากเว็บอื่นหรืออัปโหลดไฟล์เอง) */
class WavevyImageUrl implements ValidationRule
{
    public const HOST = 'images.wavevycity.com';

    public static function valid(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 500 || preg_match('/\s/', $value)) {
            return false;
        }
        $p = parse_url($value);

        return $p !== false
            && ($p['scheme'] ?? '') === 'https'
            && strtolower($p['host'] ?? '') === self::HOST
            && ! isset($p['user']) && ! isset($p['pass']) && ! isset($p['port'])
            && strlen($p['path'] ?? '') > 1;   // ต้องชี้ไปที่ไฟล์/พาธ ไม่ใช่แค่หน้าแรกของเว็บ
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::valid($value)) {
            $fail('ลิงก์โลโก้ต้องขึ้นต้นด้วย https://'.self::HOST.'/ เท่านั้น');
        }
    }
}
