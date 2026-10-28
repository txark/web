<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Resources\UserAdminResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * จัดการผู้ใช้: เพิ่ม Discord ID + กำหนดบทบาท / ปรับบทบาท / ระงับสิทธิ์ (รองประธานขึ้นไป)
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
        $order = 'CASE role '.implode(' ', array_map(fn (Role $r) => "WHEN '{$r->value}' THEN {$r->level()}", Role::cases())).' ELSE 0 END';

        $users = User::query()
            ->with('addedBy')
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
            // บทบาทที่ผู้ใช้งานคนนี้กำหนดให้คนอื่นได้ (ใช้ทำตัวเลือกในฟอร์ม)
            'assignable_roles' => collect($request->user()->role->assignable())
                ->sortByDesc(fn (Role $r) => $r->level())
                ->map(fn (Role $r) => ['value' => $r->value, 'label' => $r->label(), 'name' => $r->thaiName()])
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'discord_id' => ['required', 'regex:/^\d{17,20}$/', 'unique:users,discord_id'],
            'character_name' => ['required', 'string', 'max:120'],
            'role' => ['required', Rule::enum(Role::class)],
        ], self::MESSAGES);

        $role = Role::from($data['role']);
        $this->assertCanAssign($request->user(), $role);

        $user = User::create([
            'discord_id' => $data['discord_id'],
            'name' => $data['character_name'], // ชื่อชั่วคราวจนกว่าจะเข้าระบบครั้งแรก (แล้วใช้ชื่อ Discord)
            'character_name' => $data['character_name'],
            'role' => $role,
            'added_by' => $request->user()->id,
        ]);

        return (new UserAdminResource($user->load('addedBy')))->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user): UserAdminResource
    {
        $this->guardTarget($request->user(), $user);

        $data = $request->validate([
            'role' => ['sometimes', Rule::enum(Role::class)],
            'character_name' => ['sometimes', 'string', 'max:120'],
            'disabled' => ['sometimes', 'boolean'],
        ], self::MESSAGES);

        if (isset($data['role'])) {
            $this->assertCanAssign($request->user(), Role::from($data['role']));
            $user->role = Role::from($data['role']);
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

        return new UserAdminResource($user->load('addedBy'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->guardTarget($request->user(), $user);

        // คนที่เคยเข้าระบบแล้วมีประวัติการใช้งาน จึงลบไม่ได้ ให้ระงับสิทธิ์แทน (ประวัติใน log ยังอยู่ครบ)
        if ($user->last_login_at !== null) {
            return response()->json(['message' => 'ผู้ใช้ที่เคยเข้าระบบแล้วลบไม่ได้ ให้ระงับสิทธิ์แทน'], 409);
        }

        $user->delete();

        return response()->json(null, 204);
    }

    private function guardTarget(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            abort(403, 'ไม่สามารถแก้ไขบัญชีของตัวเอง');
        }

        if ($actor->role->level() <= $target->role->level()) {
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
