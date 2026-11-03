<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** ตั้งค่ากลางการกู้เงิน (แถวเดียว) ค่าเริ่มต้นของสัญญาใหม่ — ยังไม่เคยตั้ง = ดอกเบี้ย 0 ค่าปรับ 0 ผ่อนได้สูงสุด 6 งวด */
#[Fillable(['default_interest', 'late_fee_type', 'late_fee_amount', 'max_installments', 'updated_by'])]
class CouncilLoanSetting extends Model
{
    public const FEE_TYPES = ['day' => 'ต่อวัน', 'week' => 'ต่อสัปดาห์', 'once' => 'ครั้งเดียว'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['default_interest' => 'float', 'late_fee_amount' => 'integer', 'max_installments' => 'integer'];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::create([])->fresh();
    }
}
