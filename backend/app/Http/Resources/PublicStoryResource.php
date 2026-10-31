<?php

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ข้อมูลหนังสือสัญญาสำหรับลิงก์แชร์ (ไม่ต้องล็อกอิน) — เฉพาะที่แสดงในหนังสือสัญญา
 * ไม่รวมคำเตือนภายใน บันทึกการยกเลิก หรือรหัสผู้ใช้
 *
 * @mixin \App\Models\Story
 */
class PublicStoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $group = fn (?Group $g) => $g ? [
            'name' => $g->name,
            'short_name' => $g->short_name,
            'type' => $g->type->value,
            'is_lady' => $g->is_lady,
            'logo_url' => $g->logo_url,
            'color_hex' => $g->color_hex,
        ] : null;
        $phase = $this->phase();

        return [
            'number' => $this->number,
            'story_type' => $this->story_type,
            'status' => $this->status->value,
            'phase' => $phase,
            'phase_label' => StoryResource::PHASE_LABELS[$phase],
            'group_a' => $group($this->groupA),
            'group_b' => $group($this->groupB),
            'play_format' => $this->play_format,
            'play_format_random' => $this->play_format_random,
            'starts_at' => $this->starts_at,
            'lock_at' => $this->lock_at,
            'fights' => $this->fights,
            'venue' => $this->venue,
            'venue_random' => $this->venue_random,
            'toxic_level' => $this->toxic_level,
            'stake_amount' => $this->stake_amount,
            'guns' => $this->guns,
            'ticket_policy' => $this->ticket_policy,
            'allow_reserve_swap' => $this->allow_reserve_swap,
            'allow_slot_change' => $this->allow_slot_change,
            'extra_conditions' => $this->extra_conditions,
            'created_at' => $this->started_at,
            'created_by' => $this->starter ? ['name' => $this->starter->character_name ?: $this->starter->name, 'role_label' => $this->starter->role->thaiName()] : null,
        ];
    }
}
