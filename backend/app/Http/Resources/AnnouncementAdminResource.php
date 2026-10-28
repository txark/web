<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** @mixin \App\Models\Announcement */
class AnnouncementAdminResource extends AnnouncementResource
{
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'is_published' => $this->is_published,
            'created_by' => $this->created_by,
            'author' => $this->whenLoaded('author', fn () => $this->author?->character_name ?: $this->author?->name),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
