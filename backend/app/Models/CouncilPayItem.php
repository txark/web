<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** โบนัสเวรของสมาชิกสภาหนึ่งคนในรอบ (เก็บสำเนานาที เงินเดือน และยอด ณ ตอนตัดรอบ) */
#[Fillable(['round_id', 'user_id', 'minutes', 'salary', 'base', 'special', 'amount'])]
class CouncilPayItem extends Model
{
    public function round(): BelongsTo
    {
        return $this->belongsTo(CouncilPayRound::class, 'round_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
