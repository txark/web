<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use App\Models\Concerns\HasDocNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** บันทึกของทะเบียน: รายงานตัว (report) / ค่าปรับ (fine) / ปรับ Protect (protect) */
#[Fillable(['group_id', 'kind', 'data', 'note', 'recorded_by'])]
class GroupRecord extends Model
{
    use HasDocNumber;

    /** ตัวย่อตามชนิดบันทึก: ค่าปรับ FINE · โทษบำเพ็ญ PEN · รายงานตัว RPT · Protect PRT · ชุด OUT */
    public function docPrefix(): string
    {
        return match ($this->kind) {
            'fine' => ($this->data['penalty_type'] ?? 'fine') === 'penance' ? 'PEN' : 'FINE',
            'report' => 'RPT',
            'protect' => 'PRT',
            'outfit' => 'OUT',
            default => 'REC',
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class)->withTrashed();
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
