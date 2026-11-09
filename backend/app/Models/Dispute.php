<?php

namespace App\Models;

use App\Models\Concerns\HasDocNumber;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** ข้อพิพาท: บันทึกเหตุการณ์ของแก๊ง/ครอบครัวพร้อมหลักฐาน (ข้อมูลภายในสภา) */
#[Fillable(['title', 'details', 'occurred_at', 'status', 'created_by'])]
class Dispute extends Model
{
    use HasDocNumber, LogsActivity, SoftDeletes;

    public const DOC_PREFIX = 'DSP';

    public const STATUSES = ['open' => 'ยังไม่จบ', 'closed' => 'จบแล้ว'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'dispute_groups')->withTrashed();
    }

    public function council(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'dispute_council');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(DisputeNote::class)->orderBy('id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DisputeFile::class)->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['number', 'title', 'details', 'occurred_at', 'status'])->logOnlyDirty();
    }
}
