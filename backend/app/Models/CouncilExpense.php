<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** รายจ่ายของสภาที่กรอกเอง (ลบแบบซ่อน เก็บประวัติไว้ใน activity log) */
#[Fillable(['spent_at', 'amount', 'category', 'title', 'note', 'created_by'])]
class CouncilExpense extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spent_at' => 'date:Y-m-d',
            'amount' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['spent_at', 'amount', 'category', 'title', 'note'])->logOnlyDirty();
    }
}
