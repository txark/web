<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** ประกาศเรียก: สภาเรียกแก๊ง/ครอบครัว (ผู้ถูกเรียก) เข้าพบตามที่แก๊ง/ครอบครัวอีกฝ่าย (ผู้แจ้งเรียก) แจ้ง */
#[Fillable(['number', 'summoned_group_id', 'summoner_group_id', 'by_council', 'reason', 'place', 'dimension', 'created_by'])]
class CouncilSummon extends Model
{
    use LogsActivity, SoftDeletes;

    protected function casts(): array
    {
        return ['by_council' => 'boolean'];
    }

    public const DEFAULT_PLACE = 'หน้าที่ทำการสภา';

    public const MAX_DIMENSION = 10;

    /** 0 = มิติหลัก (ไม่ใช่มิติย่อยหมายเลขใด) */
    public const MAIN_DIMENSION = 0;

    public function summoned(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'summoned_group_id')->withTrashed();
    }

    public function summoner(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'summoner_group_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'summoned_group_id', 'summoner_group_id', 'by_council', 'reason', 'place', 'dimension'])->logOnlyDirty();
    }
}
