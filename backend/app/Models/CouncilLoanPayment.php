<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use App\Models\Concerns\HasDocNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * การรับคืนเงินกู้แต่ละครั้ง — บันทึกแล้วแก้/ลบไม่ได้
 * ถ้าผิดบันทึก "รายการยกเลิกรับคืน" ต่อท้าย (reversal_of = id รายการที่ยกเลิก) ยอดรับคืนจึงหักออก และรายการเดิมยังอยู่ให้ตรวจสอบ
 */
#[Fillable(['loan_id', 'reversal_of', 'amount', 'paid_at', 'note', 'reason', 'created_by'])]
class CouncilLoanPayment extends Model
{
    use HasDocNumber;

    public const DOC_PREFIX = 'PAY';

    use LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'paid_at' => 'date:Y-m-d'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(CouncilLoan::class, 'loan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversal(): bool
    {
        return $this->reversal_of !== null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['loan_id', 'reversal_of', 'amount', 'paid_at', 'note', 'reason'])->logOnlyDirty();
    }
}
