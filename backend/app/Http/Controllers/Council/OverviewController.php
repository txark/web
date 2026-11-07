<?php

namespace App\Http\Controllers\Council;

use App\Enums\GroupType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;

/** ภาพรวมการทำงานหน้าแรกของสภา (Council ขึ้นไป): ตัวเลขหลัก + ธุรกรรมล่าสุด (อ่านอย่างเดียว) */
class OverviewController extends Controller
{
    public function show(): JsonResponse
    {
        $groups = Group::query()->get(['type']);

        $recent = Transaction::query()->with(['group:id,name', 'requester:id,name,character_name'])
            ->latest('id')->limit(6)->get()
            ->map(fn (Transaction $t) => [
                'id' => $t->id,
                'number' => $t->number,
                'kind' => $t->kind->label(),
                'group' => $t->group?->name,
                'by' => $t->requester ? ($t->requester->character_name ?: $t->requester->name) : null,
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'at' => $t->created_at->toIso8601String(),
            ])->values();

        $onDuty = DutyShift::query()->whereNull('ended_at')->with('user:id,role')->get()
            ->filter(fn (DutyShift $s) => $s->user && ! $s->user->role->isAdmin())->count();

        return response()->json(['data' => [
            'gangs' => $groups->where('type', GroupType::Gang)->count(),
            'families' => $groups->where('type', GroupType::Family)->count(),
            'pending' => Transaction::where('status', TransactionStatus::Pending->value)->count(),
            'on_duty' => $onDuty,
            'recent' => $recent,
            'protect' => Group::query()->where('protect_until', '>', now())->orderBy('protect_until')->limit(5)
                ->get(['name', 'protect_until'])
                ->map(fn (Group $g) => ['name' => $g->name, 'until' => $g->protect_until->toIso8601String()])->values(),
        ]]);
    }
}
