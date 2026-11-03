<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** ช่วงเวลาเปิดใช้งานระบบส่วนแก๊ง/ครอบครัว (ค่าจาก config council.group_portal; เวลาไทย; ข้ามเที่ยงคืนได้) */
class GroupPortal
{
    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    public static function isOpen(?CarbonInterface $now = null): bool
    {
        $tz = (string) config('council.group_portal.timezone');
        $now = ($now ?? now())->copy()->setTimezone($tz);
        $m = $now->hour * 60 + $now->minute;
        $open = self::minutes(config('council.group_portal.open_at'));
        $close = self::minutes(config('council.group_portal.close_at'));

        return $open <= $close ? ($m >= $open && $m < $close) : ($m >= $open || $m < $close);
    }

    /** @return array{open: bool, open_at: string, close_at: string, label: string} */
    public static function status(?CarbonInterface $now = null): array
    {
        $open = (string) config('council.group_portal.open_at');
        $close = (string) config('council.group_portal.close_at');

        return [
            'open' => self::isOpen($now),
            'open_at' => $open,
            'close_at' => $close,
            'label' => "{$open} - {$close} น.",
        ];
    }
}
