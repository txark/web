<?php

namespace App\Models;

use App\Enums\StoryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** สัญญาสตอรี่ระหว่างสองฝั่ง — ใช้ผ่าน App\Services\StoryService เท่านั้น */
#[Fillable([
    'number', 'share_token', 'story_type', 'group_a_id', 'group_b_id', 'status', 'play_format', 'play_format_random', 'starts_at', 'lock_at', 'lock_applied', 'fights', 'venue', 'venue_random',
    'toxic_level', 'stake_amount', 'stake_late_a', 'stake_late_b', 'guns', 'ticket_policy', 'allow_reserve_swap', 'allow_slot_change',
    'extra_conditions', 'warnings', 'locked_a', 'locked_b', 'started_at', 'started_by',
    'end_kind', 'score_a', 'score_b', 'winner_group_id', 'surrender_group_id', 'stake_final_a', 'stake_final_b', 'protect_a', 'protect_b', 'protect_until_a', 'protect_until_b', 'end_note', 'ended_at', 'ended_by',
])]
class Story extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StoryStatus::class,
            'protect_until_a' => 'datetime',
            'protect_until_b' => 'datetime',
            'protect_a' => 'boolean',
            'protect_b' => 'boolean',
            'play_format_random' => 'boolean',
            'venue_random' => 'boolean',
            'stake_late_a' => 'boolean',
            'stake_late_b' => 'boolean',
            'allow_reserve_swap' => 'boolean',
            'allow_slot_change' => 'boolean',
            'warnings' => 'array',
            'locked_a' => 'boolean',
            'locked_b' => 'boolean',
            'lock_applied' => 'boolean',
            'starts_at' => 'datetime',
            'lock_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function groupA(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_a_id')->withTrashed();
    }

    public function groupB(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_b_id')->withTrashed();
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function ender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function isActive(): bool
    {
        return $this->status === StoryStatus::Active;
    }

    /** waiting = รอเข้าสู่การเล่น (ก่อนเวลาเริ่มเล่น) · playing = กำลังเล่น · ended = สิ้นสุด · cancelled = ยกเลิก */
    public function phase(): string
    {
        return match ($this->status) {
            StoryStatus::Ended => 'ended',
            StoryStatus::Cancelled => 'cancelled',
            default => $this->starts_at && now()->lt($this->starts_at) ? 'waiting' : 'playing',
        };
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', StoryStatus::Active->value);
    }

    /** สตอรี่ที่กำลังดำเนินอยู่ (ยังไม่สิ้นสุด) ของกลุ่มนี้ (ฝั่งใดฝั่งหนึ่ง) */
    public static function activeFor(Group|int $group): ?self
    {
        $id = $group instanceof Group ? $group->id : $group;

        return static::active()->where(fn ($q) => $q->where('group_a_id', $id)->orWhere('group_b_id', $id))->first();
    }
}
