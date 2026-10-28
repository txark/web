<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'started_at', 'last_seen_at', 'ended_at', 'ended_reason', 'note'])]
class DutyShift extends Model
{
    /** ออกเวรเอง */
    public const MANUAL = 'manual';

    /** ปิดหน้าเว็บ/เบราว์เซอร์/คอมโดยไม่ออกเวร (หมดสัญญาณ) */
    public const ABANDONED = 'abandoned';

    /** เปิดเวรค้างเกินชั่วโมงสูงสุด */
    public const MAX_HOURS = 'max_hours';

    public const SYSTEM_NOTE = 'บังคับออกเวรโดยระบบ';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function endedBySystem(): bool
    {
        return in_array($this->ended_reason, [self::ABANDONED, self::MAX_HOURS], true);
    }
}
