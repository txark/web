<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ไฟล์หลักฐานของข้อพิพาท (เก็บในดิสก์ภายใน เปิดผ่าน API ที่ตรวจสิทธิ์เท่านั้น) */
#[Fillable(['dispute_id', 'kind', 'path', 'name', 'mime', 'size', 'uploaded_by'])]
class DisputeFile extends Model
{
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class)->withTrashed();
    }
}
