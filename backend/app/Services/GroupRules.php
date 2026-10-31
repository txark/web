<?php

namespace App\Services;

use App\Enums\GroupType;
use App\Enums\MemberPosition;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Validation\ValidationException;

/** กติกาแก๊ง/ครอบครัวตามกฎสภา: ตรวจก่อนเปลี่ยนแปลง โยน ValidationException พร้อมข้อความไทย */
class GroupRules
{
    /** คูลดาวน์เปลี่ยนชื่อ: ต้องอยู่กับสภาครบ N วัน และเปลี่ยนซ้ำได้หลัง M วัน (ปลดคูลดาวน์ได้เมื่อสภายืนยัน) */
    public function assertCanRename(Group $group, bool $skipCooldown): void
    {
        if ($skipCooldown) {
            return;
        }

        $afterReg = $group->registered_at->copy()->addDays(config('council.groups.rename_after_registered_days'));
        if (now()->lt($afterReg)) {
            $this->fail('name', 'เปลี่ยนชื่อได้หลังขึ้นทะเบียนครบ '.config('council.groups.rename_after_registered_days').' วัน (ได้ตั้งแต่ '.$this->date($afterReg).')');
        }

        if ($group->renamed_at) {
            $next = $group->renamed_at->copy()->addDays(config('council.groups.rename_cooldown_days'));
            if (now()->lt($next)) {
                $this->fail('name', 'อยู่ในคูลดาวน์เปลี่ยนชื่อ เปลี่ยนได้อีกครั้งตั้งแต่ '.$this->date($next));
            }
        }
    }

    public function assertSlotsOpen(Group $group): void
    {
        // สตอรี่ที่ถึงเวลาล็อกแล้วต้องล็อกก่อนตรวจ (กรณีตัวรันตารางงานไม่ทำงาน)
        if (! $group->slots_locked && app(StoryService::class)->syncLocks() > 0) {
            $group->refresh();
        }
        if ($group->slots_locked) {
            abort(409, 'สล็อตสมาชิกถูกล็อกอยู่ จึงเพิ่ม/ลบ/เปลี่ยนตำแหน่งไม่ได้'.($group->locked_reason ? " ({$group->locked_reason})" : ''));
        }
    }

    public function assertCanAddMember(Group $group, string $characterName, MemberPosition $position, bool $skipCooldown, bool $reserve = false): void
    {
        $this->assertSlotsOpen($group);
        $this->assertSlotAvailable($group, $position, $reserve);
        $this->assertPositionFree($group, $position);

        // ตัวละครเดียวอยู่ได้ 1 กลุ่มต่อประเภท (แก๊ง 1 + ครอบครัว 1)
        $already = GroupMember::query()
            ->whereNull('left_at')
            ->whereRaw('lower(character_name) = ?', [mb_strtolower($characterName)])
            ->whereHas('group', fn ($q) => $q->where('type', $group->type->value))
            ->with('group')
            ->first();
        if ($already) {
            $this->fail('character_name', "{$characterName} อยู่ใน{$group->type->label()} {$already->group->name} อยู่แล้ว");
        }

        if (! $skipCooldown) {
            $days = config('council.groups.rejoin_cooldown_days');
            $recent = GroupMember::query()
                ->whereRaw('lower(character_name) = ?', [mb_strtolower($characterName)])
                ->where('left_at', '>', now()->subDays($days))
                ->orderByDesc('left_at')
                ->first();
            if ($recent) {
                $this->fail('character_name', "{$characterName} เพิ่งออกจากกลุ่ม อยู่ในคูลดาวน์ {$days} วัน เข้ากลุ่มใหม่ได้ตั้งแต่ ".$this->date($recent->left_at->copy()->addDays($days)));
            }
        }
    }

    /**
     * ตัวจริง/ตัวสำรองมีที่ว่างไหม (หัวหน้า/รองหัวหน้าต้องเป็นตัวจริง; ครอบครัวไม่มีตัวสำรอง)
     * $ignore = สมาชิกที่กำลังย้ายช่อง ไม่นับรวมตัวเอง
     */
    public function assertSlotAvailable(Group $group, MemberPosition $position, bool $reserve, ?GroupMember $ignore = null): void
    {
        $limits = $group->slotLimits();

        if ($reserve && $position !== MemberPosition::Member) {
            $this->fail('position', 'หัวหน้า/รองหัวหน้าต้องเป็นตัวจริง');
        }
        if ($reserve && $limits['reserve'] === 0) {
            $this->fail('is_reserve', "{$group->type->label()}ไม่มีตัวสำรอง");
        }

        $max = $reserve ? $limits['reserve'] : $limits['main'];
        $count = $group->activeMembers()->where('is_reserve', $reserve)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->getKey()))->count();

        if ($count >= $max) {
            $this->fail($reserve ? 'is_reserve' : 'character_name', ($reserve ? 'ตัวสำรอง' : 'ตัวจริง')."เต็มแล้ว (สูงสุด {$max} คน)");
        }
    }

    /** หัวหน้าได้ 1 คน รองหัวหน้าได้ตามที่กำหนด */
    public function assertPositionFree(Group $group, MemberPosition $position, ?GroupMember $ignore = null): void
    {
        $limit = match ($position) {
            MemberPosition::Leader => 1,
            MemberPosition::CoLeader => $group->maxCoLeaders(),
            default => null,
        };
        if ($limit === null) {
            return;
        }

        $count = $group->activeMembers()->where('position', $position->value)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->getKey()))->count();
        if ($count >= $limit) {
            $this->fail('position', "ตำแหน่ง{$position->label()}เต็มแล้ว (สูงสุด {$limit} คน)");
        }
    }

    public function assertValidName(GroupType $type, string $name, ?int $ignoreId = null): void
    {
        if ($type === GroupType::Family && stripos($name, 'gang') !== false) {
            $this->fail('name', 'ชื่อครอบครัวห้ามมีคำว่า Gang');
        }

        $dup = Group::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($dup) {
            $this->fail('name', 'ชื่อนี้ถูกใช้แล้ว');
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function date($d): string
    {
        return $d->copy()->timezone(config('app.timezone'))->format('j/n/').($d->year + 543);
    }
}
