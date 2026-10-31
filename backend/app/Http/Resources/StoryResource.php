<?php

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Story */
class StoryResource extends JsonResource
{
    public const PHASE_LABELS = ['waiting' => 'รอเข้าสู่การเล่น', 'playing' => 'กำลังเล่น', 'ended' => 'สิ้นสุด', 'cancelled' => 'ยกเลิก'];

    public function toArray(Request $request): array
    {
        $group = fn (?Group $g) => $g ? [
            'id' => $g->id,
            'name' => $g->name,
            'short_name' => $g->short_name,
            'type' => $g->type->value,
            'type_label' => $g->type->label(),
            'is_lady' => $g->is_lady,
            'logo_url' => $g->logo_url,
            'color_hex' => $g->color_hex,
            'color_name' => $g->color_name,
            'dissolved' => $g->trashed(),
        ] : null;
        $person = fn ($u) => $u ? ['id' => $u->id, 'name' => $u->character_name ?: $u->name, 'role_label' => $u->role->thaiName()] : null;
        $phase = $this->phase();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'story_type' => $this->story_type,
            'status' => $this->status->value,
            'phase' => $phase,
            'phase_label' => self::PHASE_LABELS[$phase],
            'group_a' => $group($this->groupA),
            'group_b' => $group($this->groupB),
            'play_format' => $this->play_format,
            'play_format_random' => $this->play_format_random,
            'starts_at' => $this->starts_at,
            'lock_at' => $this->lock_at,
            'lock_applied' => $this->lock_applied,
            'fights' => $this->fights,
            'venue' => $this->venue,
            'venue_random' => $this->venue_random,
            'toxic_level' => $this->toxic_level,
            'stake_amount' => $this->stake_amount,
            'stake_late_a' => $this->stake_late_a,
            'stake_late_b' => $this->stake_late_b,
            'guns' => $this->guns,
            'ticket_policy' => $this->ticket_policy,
            'allow_reserve_swap' => $this->allow_reserve_swap,
            'allow_slot_change' => $this->allow_slot_change,
            'extra_conditions' => $this->extra_conditions,
            'warnings' => $this->warnings ?? [],
            'created_at' => $this->started_at,
            'created_by' => $person($this->starter),
            'end_kind' => $this->end_kind,
            'score_a' => $this->score_a,
            'score_b' => $this->score_b,
            'winner_group_id' => $this->winner_group_id,
            'surrender_group_id' => $this->surrender_group_id,
            'stake_final_a' => $this->stake_final_a,
            'stake_final_b' => $this->stake_final_b,
            'protect_a' => $this->protect_a,
            'protect_b' => $this->protect_b,
            'protect_until_a' => $this->protect_until_a,
            'protect_until_b' => $this->protect_until_b,
            'end_note' => $this->end_note,
            'ended_at' => $this->ended_at,
            'ended_by' => $person($this->ender),
        ];
    }
}
