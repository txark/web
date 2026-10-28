<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ประกาศ (ภายในสภาเท่านั้น: ไม่มีหน้า/API สาธารณะ)
 * AnnouncementAdminResource เพิ่มสถานะเผยแพร่และผู้สร้าง
 *
 * @mixin \App\Models\Announcement
 */
class AnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'title' => $this->title,
            'body' => $this->body,
            'group' => $this->whenLoaded('group', fn () => $this->group
                ? ['id' => $this->group->id, 'name' => $this->group->name, 'type_label' => $this->group->type->label()]
                : null),
            'due_at' => $this->due_at,
            'published_at' => $this->published_at,
        ];
    }
}
