<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ประวัติการแก้ค่ากลางการกู้เงิน (ต่อท้ายอย่างเดียว) */
#[Fillable(['field', 'old_value', 'new_value', 'changed_by'])]
class CouncilLoanSettingLog extends Model
{
    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
