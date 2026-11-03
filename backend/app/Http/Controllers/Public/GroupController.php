<?php

namespace App\Http\Controllers\Public;

use App\Enums\GroupStatus;
use App\Enums\GroupType;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Enums\StoryStatus;
use App\Models\Group;
use App\Models\Story;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(GroupType::class)],
            'kind' => ['nullable', Rule::in(['gang', 'lady', 'family'])],   // gang = แก๊งทั่วไป · lady = แก๊งหญิงล้วน · family = ครอบครัว
            'status' => ['nullable', Rule::enum(GroupStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $groups = Group::query()
            ->with('leader')
            ->withCount('activeMembers')
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['kind'] ?? null, fn ($q, $v) => match ($v) {
                'family' => $q->where('type', GroupType::Family->value),
                'lady' => $q->where('type', GroupType::Gang->value)->where('is_lady', true),
                default => $q->where('type', GroupType::Gang->value)->where('is_lady', false),
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->whereLike('name', "%{$v}%"))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 24)
            ->withQueryString();

        return GroupResource::collection($groups);
    }

    public function show(Group $group): GroupResource
    {
        $group->load('leader')->loadCount('activeMembers');
        $group->setRelation('storyHistory', $this->storyHistory($group));

        return new GroupResource($group);
    }

    /** ประวัติการเล่นสตอรี่ของกลุ่ม (ล่าสุดก่อน, 30 รายการ) — เปิดเผยเฉพาะคู่แข่ง ประเภท วันที่ และสถานะ ไม่รวมผลแพ้ชนะและแต้ม */
    private function storyHistory(Group $group): array
    {
        return Story::query()
            ->with(['groupA', 'groupB'])
            ->whereIn('status', [StoryStatus::Active, StoryStatus::Ended])
            ->where(fn ($q) => $q->where('group_a_id', $group->id)->orWhere('group_b_id', $group->id))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->map(function (Story $s) use ($group) {
                $rival = $s->group_a_id === $group->id ? $s->groupB : $s->groupA;

                return [
                    'number' => $s->number,
                    'story_type' => $s->story_type,
                    'phase_label' => $s->status === StoryStatus::Active ? 'กำลังเล่น' : 'จบแล้ว',
                    'rival' => $rival ? ['name' => $rival->name, 'short_name' => $rival->short_name] : null,
                    'date' => $s->ended_at ?? $s->starts_at ?? $s->started_at,
                ];
            })
            ->all();
    }
}
