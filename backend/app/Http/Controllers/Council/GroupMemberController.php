<?php

namespace App\Http\Controllers\Council;

use App\Enums\MemberPosition;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupAdminResource;
use App\Models\Group;
use App\Models\GroupMember;
use App\Services\GroupRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** สมาชิกแก๊ง/ครอบครัว: เพิ่ม / เปลี่ยนตำแหน่ง / นำออก (เก็บประวัติด้วย left_at) */
class GroupMemberController extends Controller
{
    private const MESSAGES = [
        'character_name.required' => 'กรุณากรอกชื่อตัวละคร',
        'character_name.max' => 'ชื่อตัวละครยาวเกินไป (ไม่เกิน 120 ตัวอักษร)',
        'position.enum' => 'ตำแหน่งไม่ถูกต้อง',
    ];

    public function __construct(private GroupRules $rules) {}

    public function store(Request $request, Group $group): GroupAdminResource
    {
        $data = $request->validate([
            'character_name' => ['required', 'string', 'max:120'],
            'position' => ['sometimes', Rule::enum(MemberPosition::class)],
            'is_reserve' => ['sometimes', 'boolean'],
            'skip_cooldown' => ['sometimes', 'boolean'],
        ], self::MESSAGES);

        $name = trim($data['character_name']);
        $position = MemberPosition::from($data['position'] ?? 'member');
        $skip = (bool) ($data['skip_cooldown'] ?? false);
        $reserve = (bool) ($data['is_reserve'] ?? false);

        $this->rules->assertCanAddMember($group, $name, $position, $skip, $reserve);

        $group->members()->create([
            'character_name' => $name,
            'position' => $position,
            'is_reserve' => $reserve,
            'joined_at' => now(),
        ]);

        if ($skip) {
            activity('groups')->causedBy($request->user())->performedOn($group)->log("ปลดคูลดาวน์เข้ากลุ่ม: {$name}");
        }

        return $this->respond($group);
    }

    public function update(Request $request, Group $group, GroupMember $member): GroupAdminResource
    {
        $this->own($group, $member);
        $data = $request->validate([
            'position' => ['sometimes', Rule::enum(MemberPosition::class)],
            'is_reserve' => ['sometimes', 'boolean'],
        ], self::MESSAGES);

        $this->rules->assertSlotsOpen($group);
        $position = isset($data['position']) ? MemberPosition::from($data['position']) : $member->position;
        $reserve = $data['is_reserve'] ?? $member->is_reserve;

        if ($position !== $member->position) {
            $this->rules->assertPositionFree($group, $position, $member);
        }
        if ($reserve !== $member->is_reserve || $position !== $member->position) {
            // ย้ายตัวจริง↔สำรอง หรือเลื่อนตำแหน่ง: ตรวจที่ว่างของช่องปลายทาง
            $this->rules->assertSlotAvailable($group, $position, $reserve, $member);
        }
        $member->update(['position' => $position, 'is_reserve' => $reserve]);

        return $this->respond($group);
    }

    public function destroy(Group $group, GroupMember $member): GroupAdminResource
    {
        $this->own($group, $member);
        $this->rules->assertSlotsOpen($group);

        $member->update(['left_at' => now()]);

        return $this->respond($group);
    }

    private function own(Group $group, GroupMember $member): void
    {
        abort_unless($member->group_id === $group->id && $member->left_at === null, 404);
    }

    private function respond(Group $group): GroupAdminResource
    {
        return new GroupAdminResource($group->fresh()->load(['leader', 'members', 'statusHistory.changedBy'])->loadCount('activeMembers'));
    }
}
