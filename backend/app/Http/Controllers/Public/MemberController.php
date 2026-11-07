<?php

namespace App\Http\Controllers\Public;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class MemberController extends Controller
{
    /** รายชื่อสมาชิกสภาตามตำแหน่ง (ประธาน → สภาฝึกหัด) แสดงเฉพาะชื่อ IC และตำแหน่ง */
    public function index(): JsonResponse
    {
        $roles = collect(Role::cases())
            ->filter(fn (Role $r) => $r->isCouncil())
            ->sortByDesc(fn (Role $r) => $r->sortWeight())
            ->values();

        $users = User::query()
            ->whereIn('role', $roles->map->value->all())
            ->whereNull('disabled_at')
            ->whereNull('removed_at')
            ->orderBy('character_name')
            ->get(['character_name', 'name', 'role'])
            ->groupBy(fn (User $u) => $u->role->value);

        return response()->json(['data' => $roles->map(fn (Role $r) => [
            'role' => $r->value,
            'label' => $r->thaiName(),
            'members' => $users->get($r->value, collect())
                ->map(fn (User $u) => ['name' => $u->character_name ?: $u->name])
                ->values(),
        ])->values()]);
    }
}
