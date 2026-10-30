<?php

namespace App\Support;

/**
 * อ่านจำนวนเงินค่าปรับจากข้อความ "บทลงโทษ" ของข้อกฎ (เช่น "ฝ่าฝืนปรับ 500,000 WVDL / รายบุคคล")
 * ตัดตัวเลขที่เป็นหน่วยอื่นออก (เช่น "บำเพ็ญ 2,000 ไม้") และเก็บเฉพาะจำนวนเงินตั้งแต่ 100,000 ขึ้นไป
 */
class PenaltyAmount
{
    /**
     * @return array{amounts: list<int>, suggested: int|null, min: int|null, max: int|null, penance: array{units: list<int>, suggested: int|null, min: int|null, max: int|null}}
     *                                                                                      suggested = มีจำนวนเงินเดียวที่ชัดเจน (ใช้เติมช่องให้อัตโนมัติ); ถ้ามีหลายจำนวน (ช่วง/หลายครั้ง) เป็น null ให้ผู้ใช้เลือกเอง
     */
    public static function parse(?string $text): array
    {
        $amounts = [];
        if ($text !== null && preg_match_all('/(\d{1,3}(?:,\d{3})+)(\s*(?:WVDL|IC|ไม้))?/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $unit = trim($hit[2] ?? '');
                $value = (int) str_replace(',', '', $hit[1]);
                if ($unit === 'ไม้' || $value < 100000) {
                    continue;
                }
                $amounts[] = $value;
            }
        }

        $amounts = array_values(array_unique($amounts));
        sort($amounts);

        $penance = self::penance($text);

        return [
            'amounts' => $amounts,
            'suggested' => count($amounts) === 1 ? $amounts[0] : null,
            'min' => $amounts[0] ?? null,
            'max' => $amounts ? $amounts[count($amounts) - 1] : null,
            'penance' => $penance,
        ];
    }

    /**
     * จำนวนไม้ที่ต้องบำเพ็ญ จากข้อความ "บำเพ็ญ 2,000 ไม้" (ทางเลือกแทนค่าปรับ)
     *
     * @return array{units: list<int>, suggested: int|null, min: int|null, max: int|null}
     */
    public static function penance(?string $text): array
    {
        $units = [];
        if ($text !== null && preg_match_all('/บำเพ็ญ\s*(\d{1,3}(?:,\d{3})+|\d+)\s*ไม้/u', $text, $m)) {
            foreach ($m[1] as $n) {
                $units[] = (int) str_replace(',', '', $n);
            }
        }
        $units = array_values(array_unique($units));
        sort($units);

        return [
            'units' => $units,
            'suggested' => count($units) === 1 ? $units[0] : null,
            'min' => $units[0] ?? null,
            'max' => $units ? $units[count($units) - 1] : null,
        ];
    }
}
