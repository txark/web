<?php

namespace App\Models;

use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** ธุรกรรมของแก๊ง/ครอบครัว (ตารางเดียวทุกชนิด) พร้อมขั้นอนุมัติ — ใช้ผ่าน App\Services\TransactionService เท่านั้น */
#[Fillable([
    'number', 'group_id', 'kind', 'payload', 'status',
    'requested_by', 'requested_role', 'approval_required',
    'reviewed_by', 'review_reason', 'reviewed_at',
])]
class Transaction extends Model
{
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => TransactionKind::class,
            'status' => TransactionStatus::class,
            'payload' => 'array',
            'approval_required' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === TransactionStatus::Pending;
    }

    public function getActivitylogOptions(): LogOptions
    {
        // ไม่บันทึก payload (มีเบอร์โทร/Discord ID) ลง log ของโมเดล; ค่าก่อน-หลังของกลุ่มบันทึกแยกโดย TransactionService
        return LogOptions::defaults()->logOnly(['number', 'kind', 'status', 'group_id', 'review_reason'])->logOnlyDirty();
    }
}
