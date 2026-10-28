<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Rule */
class RuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'note_label' => $this->note ? ($this->note_label ?: 'หมายเหตุ') : null,
            'note' => $this->note,
            'penalty' => $this->penalty,
        ];
    }
}
