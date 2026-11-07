<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use App\Models\Concerns\HasDocNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** ซื้อ-ขายระหว่างสภากับแก๊ง/ครอบครัว (direction: sell = สภาขายให้กลุ่ม = รายรับ · buy = สภาซื้อจากกลุ่ม = รายจ่าย) */
#[Fillable(['group_id', 'direction', 'item', 'quantity', 'unit_price', 'amount', 'traded_at', 'note', 'created_by'])]
class CouncilTrade extends Model
{
    use HasDocNumber;

    public const DOC_PREFIX = 'TRD';

    use LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer', 'amount' => 'integer', 'traded_at' => 'date:Y-m-d'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['group_id', 'direction', 'item', 'quantity', 'unit_price', 'amount', 'traded_at', 'note'])->logOnlyDirty();
    }
}
