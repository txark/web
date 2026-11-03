<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** รอบรายงานความเคลื่อนไหว (เปิดเป็นช่วงเวลา) */
#[Fillable(['title', 'opens_at', 'closes_at', 'closed_early_at', 'created_by'])]
class ActivityReportRound extends Model
{
    protected function casts(): array
    {
        return ['opens_at' => 'datetime', 'closes_at' => 'datetime', 'closed_early_at' => 'datetime'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return $this->closed_early_at === null && $this->opens_at->lte(now()) && $this->closes_at->gt(now());
    }

    /** เปิดอยู่ ณ ตอนนี้ */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('closed_early_at')->where('opens_at', '<=', now())->where('closes_at', '>', now());
    }

    /** @return 'open'|'upcoming'|'closed' */
    public function phase(): string
    {
        return match (true) {
            $this->closed_early_at !== null || $this->closes_at->lte(now()) => 'closed',
            $this->opens_at->gt(now()) => 'upcoming',
            default => 'open',
        };
    }
}
