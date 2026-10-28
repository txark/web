<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ข้อมูลสาธารณะของแก๊ง/ครอบครัว
 * ไม่รวมเหตุผลของสถานะ (status_reason) และเหตุผลการล็อก เพราะเป็นข้อมูลภายในสภา
 *
 * @mixin \App\Models\Group
 */
class GroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'is_lady' => $this->is_lady,
            'name' => $this->name,
            'description' => $this->description,
            'logo_url' => $this->logo_url,
            'short_name' => $this->short_name,
            'color_hex' => $this->color_hex,
            'color_name' => $this->color_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_since' => $this->status_changed_at,
            'slots_locked' => $this->slots_locked,
            'registered_at' => $this->registered_at,
            'leader' => $this->whenLoaded('leader', fn () => $this->leader?->character_name),
            'members_count' => $this->whenCounted('activeMembers'),
            'members' => $this->whenLoaded('activeMembers', fn () => $this->activeMembers
                ->sortBy(fn ($m) => [$m->position->order(), $m->character_name])
                ->values()
                ->map(fn ($m) => [
                    'character_name' => $m->character_name,
                    'position' => $m->position->value,
                    'position_label' => $m->position->label(),
                ])),
        ];
    }
}
