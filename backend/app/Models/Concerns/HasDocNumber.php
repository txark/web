<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * รหัสรายการ WC-{ชนิด}-{วันที่เวลาไทย}-{สุ่ม 6 ตัว} เช่น WC-ENT-20261107-A1B2C3
 * สร้างอัตโนมัติตอนบันทึกครั้งแรก ไม่ซ้ำกันในตารางเดียวกัน (รวมรายการที่ถูกลบ)
 */
trait HasDocNumber
{
    protected static function bootHasDocNumber(): void
    {
        static::creating(function (Model $m) {
            if (empty($m->getAttribute('number'))) {
                $m->setAttribute('number', static::newDocNumber($m->docPrefix()));
            }
        });
    }

    /** ตัวย่อชนิดรายการในรหัส (override ได้ เช่น ตามชนิดบันทึก) */
    public function docPrefix(): string
    {
        return static::DOC_PREFIX;
    }

    public static function newDocNumber(string $prefix, ?string $date = null): string
    {
        $date ??= now(config('council.report_timezone'))->format('Ymd');
        $q = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive(static::class), true)
            ? static::withTrashed() : static::query();

        do {
            $n = "WC-{$prefix}-{$date}-".strtoupper(Str::random(6));
        } while ((clone $q)->where('number', $n)->exists());

        return $n;
    }
}
