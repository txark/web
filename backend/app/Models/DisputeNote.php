<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** บันทึกเพิ่มเติมของข้อพิพาท: ต่อท้ายอย่างเดียว แก้หรือลบไม่ได้ */
#[Fillable(['dispute_id', 'user_id', 'body'])]
class DisputeNote extends Model
{
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
