<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** รอบโบนัสเวรของสภา: นับตั้งแต่เวลาตัดรอบครั้งก่อนถึงเวลากดตัดรอบ (ย้อนได้เฉพาะรอบล่าสุด = ลบแบบซ่อน) */
#[Fillable(['started_at', 'ended_at', 'formula', 'variables', 'note', 'created_by'])]
class CouncilPayRound extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'variables' => 'array'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CouncilPayItem::class, 'round_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['started_at', 'ended_at', 'formula', 'variables', 'note'])->logOnlyDirty();
    }
}
