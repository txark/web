<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ข้อมูลภายในสภา: รวมเหตุผลสถานะ/ล็อก ประวัติสถานะ และสมาชิกที่ออกไปแล้ว
 *
 * @mixin \App\Models\Group
 */
class GroupAdminResource extends JsonResource
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
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_reason' => $this->status_reason,
            'status_since' => $this->status_changed_at,
            'slots_locked' => $this->slots_locked,
            'locked_reason' => $this->locked_reason,
            'registered_at' => $this->registered_at,
            'dissolved_at' => $this->deleted_at,
            // รายงานตัวล่าสุด: ครั้งล่าสุดที่บันทึก ถ้ายังไม่เคยใช้วันขึ้นทะเบียน (มีเมื่อเปิดดูรายละเอียดกลุ่ม)
            'last_report_at' => $this->when(array_key_exists('last_report_at', $this->getAttributes()), fn () => $this->getAttribute('last_report_at') ?? $this->registered_at),
            'short_name' => $this->short_name,
            'color_hex' => $this->color_hex,
            'color_name' => $this->color_name,
            'declared_members' => $this->declared_members,
            'temp_outfit_male' => $this->temp_outfit_male,
            'temp_outfit_female' => $this->temp_outfit_female,
            'has_temp_outfit' => (bool) $this->temp_outfit_path,
            'has_outfit_zip' => (bool) $this->outfit_zip_path,
            'protect_requested' => $this->protect_requested,
            'protect_from' => $this->protect_from,
            'outfit_counts' => $this->outfit_counts ?? [],
            'outfit_limits' => $this->outfitLimits(),
            'outfit_free_available' => $this->outfitFreeAvailable(),
            'protect_until' => $this->protect_until,
            'protect_days_left' => $this->protect_until && $this->protect_until->isFuture()
                ? (int) ceil(now()->diffInSeconds($this->protect_until) / 86400) : 0,
            'renamed_at' => $this->renamed_at,
            'leader' => $this->whenLoaded('leader', fn () => $this->leader?->character_name),
            'members_count' => $this->whenCounted('activeMembers'),
            'slots' => $this->whenLoaded('members', function () {
                $active = $this->members->whereNull('left_at');
                $limits = $this->slotLimits();

                return [
                    'main_max' => $limits['main'],
                    'reserve_max' => $limits['reserve'],
                    'main_count' => $active->where('is_reserve', false)->count(),
                    'reserve_count' => $active->where('is_reserve', true)->count(),
                    'min' => $this->minMembers(),
                ];
            }),
            'max_members' => array_sum($this->slotLimits()),
            'min_members' => $this->minMembers(),
            'members' => $this->whenLoaded('members', fn () => $this->members
                ->sortBy(fn ($m) => [$m->left_at ? 1 : 0, $m->position->order(), $m->character_name])
                ->values()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'character_name' => $m->character_name,
                    'position' => $m->position->value,
                    'position_label' => $m->position->label(),
                    'is_reserve' => $m->is_reserve,
                    'ic_phone' => $m->ic_phone,
                    'discord_id' => $m->discord_id,
                    'joined_at' => $m->joined_at,
                    'left_at' => $m->left_at,
                ])),
            'history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory
                ->sortByDesc('started_at')
                ->values()
                ->map(fn ($h) => [
                    'status' => $h->status->value,
                    'status_label' => $h->status->label(),
                    'reason' => $h->reason,
                    'changed_by' => $h->changedBy?->character_name ?: $h->changedBy?->name,
                    'started_at' => $h->started_at,
                    'ended_at' => $h->ended_at,
                ])),
        ];
    }
}
