<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'discord_id' => $this->discord_id,
            'name' => $this->name,
            'avatar' => $this->avatar,
            'character_name' => $this->character_name,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'role_name' => $this->role->thaiName(),
        ];
    }
}
