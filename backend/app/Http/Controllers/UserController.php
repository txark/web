<?php

namespace App\Http\Controllers;

use App\Enums\GroupType;
use App\Enums\Role;
use App\Http\Resources\UserAdminResource;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * จัดการผู้ใช้: เพิ่ม Discord ID + กำหนดบทบาท / ปรับบทบาท / ระงับสิทธิ์ / ลบ (รองประธาน ประธาน และ Admin)
 * การลบคือซ่อนบัญชี (removed_at) + ระงับสิทธิ์ เพราะประวัติของบัญชีนั้นยังต้องอ้างถึงได้
 * กติกา: กำหนดได้เฉพาะบทบาทต่ำกว่าตัวเอง และแก้ไขได้เฉพาะบัญชีที่สิทธิ์ต่ำกว่าตัวเอง (ไม่รวมตัวเอง)
 */
class UserController extends Controller
{
    private const MESSAGES = [
        'discord_id.required' => 'กรุณากรอก Discord ID',
        'discord_id.regex' => 'Discord ID ต้องเป็นตัวเลข 17–20 หลัก',
        'discord_id.unique' => 'Discord ID นี้อยู่ในระบบแล้ว',
        'character_name.required' => 'กรุณากรอกชื่อตัวละคร',
        'character_name.max' => 'ชื่อตัวละครยาวเกินไป (ไม่เกิน 120 ตัวอักษร)',
        'role.required' => 'กรุณาเลือกบทบาท',
        'role.enum' => 'บทบาทไม่ถูกต้อง',
        'group_id.exists' => 'ไม่พบแก๊ง/ครอบครัวที่เลือก',
        'group_position.in' => 'ตำแหน่งต้องเป็น Leader หรือ Co-Leader',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'role' => ['nullable', Rule::enum(Role::class)],
            'status' => ['nullable', Rule::in(['active', 'pending', 'disabled'])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // เรียงตามระดับสิทธิ์สูง→ต่ำ แล้วตามชื่อ (CASE สร้างจาก enum ล้วน ไม่มีค่าจากผู้ใช้)
        $order = 'CASE role '.implode(' ', array_map(fn (Role $r) => "WHEN '{$r->value}' THEN {$r->sortWeight()}", Role::cases())).' ELSE 0 END';

        $users = User::query()
            ->with(['addedBy', 'group'])
            ->whereNull('removed_at')
            ->when($f['role'] ?? null, fn ($q, $v) => $q->where('role', $v))
            ->when($f['status'] ?? null, fn ($q, $s) => match ($s) {
                'disabled' => $q->whereNotNull('disabled_at'),
                'pending' => $q->whereNull('disabled_at')->whereNull('last_login_at'),
                'active' => $q->whereNull('disabled_at')->whereNotNull('last_login_at'),
            })
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereLike('name', "%{$term}%")
                ->orWhereLike('character_name', "%{$term}%")
                ->orWhereLike('discord_id', "%{$term}%")))
            ->orderByRaw($order.' DESC')
            ->orderBy('name')
            ->paginate($f['per_page'] ?? 50)
            ->withQueryString();

        return UserAdminResource::collection($users)->additional([
            // แก๊ง/ครอบครัวทั้งหมด ให้เลือกสังกัดของบัญชีบทบาท gang/family
            'groups' => Group::orderBy('name')->get(['id', 'name', 'short_name', 'type'])
                ->map(fn (Group $g) => ['id' => $g->id, 'name' => $g->name, 'short_name' => $g->short_name, 'type' => $g->type->value]),
            // บทบาทที่ผู้ใช้งานคนนี้กำหนดให้คนอื่นได้ (ใช้ทำตัวเลือกในฟอร์ม)
            'assignable_roles' => collect($request->user()->role->assignable())
                ->sortByDesc(fn (Role $r) => $r->sortWeight())
                ->map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label(), 'name' => $r->thaiName()])
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'discord_id' => ['required', 'regex:/^\d{17,20}$/', Rule::unique('users', 'discord_id')->whereNull('removed_at')],
            'character_name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::enum(Role::class)],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'group_position' => ['nullable', Rule::in(['leader', 'co_leader'])],
        ], self::MESSAGES);

        $role = Role::from($data['role']);
        $this->assertCanAssign($request->user(), $role);
        $groupId = $this->resolveGroupId($role, $data['group_id'] ?? null);

        $fields = [
            'name' => $data['character_name'], // ชื่อชั่วคราวจนกว่าจะเข้าระบบครั้งแรก (แล้วใช้ชื่อ Discord)
            'character_name' => $data['character_name'],
            'role' => $role,
            'group_id' => $groupId,
            'group_position' => $groupId ? ($data['group_position'] ?? 'leader') : null,
            'added_by' => $request->user()->id,
        ];

        // Discord ID เคยถูกลบไปแล้ว: นำบัญชีเดิมกลับมาใช้ (ประวัติเดิมยังผูกกับบัญชีนี้) แทนการสร้างซ้ำ
        $user = User::where('discord_id', $data['discord_id'])->whereNotNull('removed_at')->first();
        if ($user) {
            $user->forceFill($fields + ['removed_at' => null, 'disabled_at' => null, 'last_login_at' => null])->save();
        } else {
            $user = User::create(['discord_id' => $data['discord_id']] + $fields);
        }

        return (new UserAdminResource($user->load(['addedBy', 'group'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserAdminResource
    {
        $this->guardTarget($request->user(), $user);

        $data = $request->validate([
            'role' => ['sometimes', Rule::enum(Role::class)],
            'character_name' => ['sometimes', 'string', 'max:120'],
            'disabled' => ['sometimes', 'boolean'],
            'group_id' => ['sometimes', 'nullable', 'integer', 'exists:groups,id'],
            'group_position' => ['sometimes', 'nullable', Rule::in(['leader', 'co_leader'])],
        ], self::MESSAGES);

        if (isset($data['role'])) {
            $this->assertCanAssign($request->user(), Role::from($data['role']));
            $user->role = Role::from($data['role']);
        }

        // สังกัดแก๊ง/ครอบครัว: ใช้ได้เฉพาะบทบาท gang/family และประเภทกลุ่มต้องตรงกับบทบาท (เปลี่ยนบทบาทแล้วสังกัดเดิมไม่ตรง → ล้างทิ้ง)
        if (array_key_exists('group_id', $data) || isset($data['role'])) {
            $wanted = array_key_exists('group_id', $data) ? $data['group_id'] : $user->group_id;
            $user->group_id = $this->resolveGroupId($user->role, $wanted, array_key_exists('group_id', $data));
        }
        // ตำแหน่งในกลุ่ม: ไม่มีสังกัด = ไม่มีตำแหน่ง; มีสังกัดแล้วไม่ระบุ = คงของเดิม (เริ่มต้นหัวหน้า)
        if (array_key_exists('group_id', $data) || array_key_exists('group_position', $data) || isset($data['role'])) {
            $user->group_position = $user->group_id ? ($data['group_position'] ?? $user->group_position ?? 'leader') : null;
        }

        if (isset($data['character_name'])) {
            $user->character_name = $data['character_name'];
        }

        $nowDisabled = false;
        if (array_key_exists('disabled', $data)) {
            $nowDisabled = (bool) $data['disabled'] && ! $user->isDisabled();
            $user->disabled_at = $data['disabled'] ? ($user->disabled_at ?? now()) : null;
        }

        $user->save();

        if ($nowDisabled) {
            $this->revokeAccess($user); // เตะออกทันที ไม่ต้องรอให้ session หมดอายุ
        }

        return new UserAdminResource($user->load(['addedBy', 'group']));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->guardTarget($request->user(), $user);

        // ซ่อนบัญชี + ระงับสิทธิ์ + เตะออกทันที (ประวัติการใช้งานของบัญชียังอยู่ครบ)
        $user->forceFill(['removed_at' => now(), 'disabled_at' => $user->disabled_at ?? now(), 'group_id' => null])->save();
        $this->revokeAccess($user);

        return response()->json(null, 204);
    }

    /** สังกัดที่ใช้ได้จริงของบทบาทนี้: บทบาทอื่นนอกจาก gang/family = ไม่มี; ถ้าระบุมาโดยตรงแต่ประเภทไม่ตรงบทบาท → 422; ถ้าได้มาจากค่าเดิมแล้วไม่ตรง → ล้าง */
    private function resolveGroupId(Role $role, ?int $groupId, bool $explicit = true): ?int
    {
        if ($groupId === null || ! in_array($role, [Role::Gang, Role::Family], true)) {
            return null;
        }

        $group = Group::find($groupId);
        $matches = $group && $group->type === ($role === Role::Family ? GroupType::Family : GroupType::Gang);
        if (! $matches) {
            if (! $explicit) {
                return null;
            }
            throw ValidationException::withMessages(['group_id' => $role === Role::Family ? 'บทบาท Family ต้องสังกัดครอบครัวเท่านั้น' : 'บทบาท Gang ต้องสังกัดแก๊งเท่านั้น']);
        }

        return $group->id;
    }

    private function guardTarget(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            abort(403, 'ไม่สามารถแก้ไขบัญชีของตัวเอง');
        }

        if (! $actor->role->canManage($target->role)) {
            abort(403, 'บัญชีนี้มีสิทธิ์เท่ากับหรือสูงกว่าคุณ จึงแก้ไขไม่ได้');
        }
    }

    private function assertCanAssign(User $actor, Role $role): void
    {
        if (! $actor->role->canAssign($role)) {
            throw ValidationException::withMessages([
                'role' => "คุณกำหนดบทบาท {$role->label()} ให้ผู้อื่นไม่ได้ (กำหนดได้เฉพาะบทบาทที่ต่ำกว่าของคุณ)",
            ]);
        }
    }

    private function revokeAccess(User $user): void
    {
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }
}
