<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ข้อมูลผู้ใช้สำหรับหน้าจัดการ (เห็นเฉพาะรองประธานขึ้นไป)
 *
 * @mixin \App\Models\User
 */
class UserAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id' => $this->id,
            'discord_id' => $this->discord_id,
            'name' => $this->name,
            'avatar' => $this->avatar,
            'character_name' => $this->character_name,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'role_name' => $this->role->thaiName(),
            // pending = เพิ่มไว้แล้วแต่ยังไม่เคยเข้าระบบ
            'status' => $this->isDisabled() ? 'disabled' : ($this->last_login_at ? 'active' : 'pending'),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'added_by' => $this->whenLoaded('addedBy', fn () => $this->addedBy ? ['id' => $this->addedBy->id, 'name' => $this->addedBy->name] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            // แก้ไขได้ไหม: ไม่ใช่ตัวเอง และมีสิทธิ์สูงกว่าเจ้าของบัญชี
            'can_manage' => $actor && $actor->id !== $this->id && $actor->role->level() > $this->role->level(),
        ];
    }
}
