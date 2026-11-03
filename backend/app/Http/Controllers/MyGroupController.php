<?php

namespace App\Http\Controllers;

use App\Enums\GroupType;
use App\Enums\Role;
use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Http\Resources\TransactionResource;
use App\Models\ActivityReportRound;
use App\Models\Group;
use App\Models\Story;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionService;
use App\Support\GroupPortal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ส่วนของแก๊ง/ครอบครัว: ดูกลุ่มของตัวเอง ยื่นขึ้นทะเบียน/เปลี่ยนหัว-รอง/ลงชุดแก้ไขชุด/แก้ชื่อ สี โลโก้ ให้สภาอนุมัติ
 * กลุ่มของผู้ใช้ = users.group_id (ระบุตอนกำหนดบทบาท gang/family)
 * ตรรกะธุรกรรมทั้งหมดอยู่ใน TransactionService (ยื่นแล้วเป็น pending เสมอ)
 */
class MyGroupController extends Controller
{
    public function __construct(private TransactionService $service) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->groupUser($request);
        $portal = GroupPortal::status();

        if (! $portal['open']) {
            return response()->json(['data' => ['portal' => $portal]]);
        }

        $group = $this->groupOf($user);
        $group?->load('leadership');

        $requests = Transaction::query()
            ->with(['group', 'requester', 'reviewer'])
            // คำขอที่ตัวเองยื่นในฐานะแก๊ง/ครอบครัว (ไม่รวมธุรกรรมที่บัญชีนี้เคยทำสมัยเป็นสภา) + ธุรกรรมของกลุ่มที่สังกัด
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('requested_by', $user->id)->whereIn('requested_role', [Role::Gang->value, Role::Family->value]))
                ->when($group, fn ($q) => $q->orWhere('group_id', $group->id)))
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return response()->json(['data' => [
            'portal' => $portal,
            'type' => $this->typeOf($user)->value,
            'my_position' => $group ? $user->group_position : null,
            'group' => $group ? [
                'id' => $group->id,
                'type' => $group->type->value,
                'type_label' => $group->type->label(),
                'is_lady' => $group->is_lady,
                'name' => $group->name,
                'short_name' => $group->short_name,
                'color_hex' => $group->color_hex,
                'color_name' => $group->color_name,
                'logo_url' => $group->logo_url,
                'status' => $group->status->value,
                'status_label' => $group->status->label(),
                'protect_active' => (bool) ($group->protect_until && $group->protect_until->isFuture()),
                'registered_at' => $group->registered_at,
                'in_story' => (bool) Story::activeFor($group),
                'leadership' => $group->leadership
                    ->sortBy(fn ($m) => [$m->position->order(), $m->character_name])->values()
                    ->map(fn ($m) => ['name' => $m->character_name, 'position' => $m->position->value, 'position_label' => $m->position->label()]),
                'outfit_limits' => $group->outfitLimits(),
                'outfit_counts' => $group->outfit_counts ?? [],
                'outfit_free_available' => $group->outfitFreeAvailable(),
                'max_co_leaders' => $group->maxCoLeaders(),
            ] : null,
            'requests' => TransactionResource::collection($requests),
            'fees' => config('council.fees'),
            'activity' => $this->activityInfo($group),
        ]]);
    }

    /** ประเมินค่าบริการก่อนยื่น */
    public function quote(Request $request): JsonResponse
    {
        $user = $this->groupUser($request);
        $d = $request->validate([
            'kind' => ['required', Rule::in(array_map(fn ($k) => $k->value, TransactionService::GROUP_SUBMITTABLE))],
            'payload' => ['sometimes', 'array'],
        ]);
        $group = $this->groupOf($user);

        return response()->json(['data' => $this->service->quote(TransactionKind::from($d['kind']), $group, $d['payload'] ?? [])]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->groupUser($request);
        $d = $request->validate([
            'kind' => ['required', Rule::in(array_map(fn ($k) => $k->value, TransactionService::GROUP_SUBMITTABLE))],
            'payload' => ['sometimes', 'array'],
        ], ['kind.required' => 'กรุณาเลือกเรื่องที่จะยื่น', 'kind.in' => 'เรื่องที่ยื่นไม่ถูกต้อง']);

        $kind = TransactionKind::from($d['kind']);
        $payload = $request->all()['payload'] ?? [];
        $group = $this->groupOf($user);

        if ($kind === TransactionKind::Register) {
            if ($group) {
                throw ValidationException::withMessages(['kind' => 'คุณมีกลุ่มที่ขึ้นทะเบียนแล้ว ยื่นขึ้นทะเบียนซ้ำไม่ได้']);
            }
            $payload['type'] = $this->typeOf($user)->value;
            $this->assertSubmitterInPayload($user, $payload);
            $hasPending = Transaction::where('requested_by', $user->id)->where('kind', TransactionKind::Register->value)
                ->where('status', TransactionStatus::Pending->value)->exists();
            if ($hasPending) {
                throw ValidationException::withMessages(['kind' => 'คุณมีคำขอขึ้นทะเบียนที่รอสภาอนุมัติอยู่แล้ว']);
            }
        } else {
            if (! $group) {
                throw ValidationException::withMessages(['kind' => 'ยังไม่มีกลุ่มที่ขึ้นทะเบียน จึงยื่นเรื่องนี้ไม่ได้']);
            }
            $hasPending = Transaction::where('group_id', $group->id)->where('kind', $kind->value)
                ->where('status', TransactionStatus::Pending->value)->exists();
            if ($hasPending) {
                throw ValidationException::withMessages(['kind' => "มีคำขอ{$kind->label()}ของกลุ่มนี้รอสภาอนุมัติอยู่แล้ว (ยกเลิกคำขอเดิมก่อนถ้าจะยื่นใหม่)"]);
            }
        }

        $tx = $this->service->submit($user, $kind, $group, $payload, true);

        return (new TransactionResource($tx))->response()->setStatusCode(201);
    }

    public function cancel(Request $request, Transaction $transaction): TransactionResource
    {
        $this->groupUser($request);

        return new TransactionResource($this->service->cancel($transaction, $request->user())->load(['group', 'requester', 'reviewer']));
    }

    /** รอบรายงานความเคลื่อนไหวที่เปิดอยู่ (หรือรอบถัดไป) และกลุ่มนี้ยื่นในรอบนี้แล้วหรือยัง */
    private function activityInfo(?Group $group): array
    {
        $round = ActivityReportRound::open()->orderBy('closes_at')->first();
        $next = $round ? null : ActivityReportRound::whereNull('closed_early_at')->where('opens_at', '>', now())->orderBy('opens_at')->first();
        $reported = $round && $group && Transaction::query()->where('group_id', $group->id)->where('kind', TransactionKind::ActivityReport->value)
            ->where('payload->round_id', $round->id)->whereIn('status', [TransactionStatus::Pending->value, TransactionStatus::Approved->value])->exists();

        return [
            'open' => $round ? ['id' => $round->id, 'title' => $round->title, 'opens_at' => $round->opens_at, 'closes_at' => $round->closes_at] : null,
            'next' => $next ? ['title' => $next->title, 'opens_at' => $next->opens_at, 'closes_at' => $next->closes_at] : null,
            'reported' => (bool) $reported,
            'min_characters' => TransactionService::ACTIVITY_MIN_CHARACTERS,
        ];
    }

    // ───────────────────────── ช่วย ─────────────────────────

    private function groupUser(Request $request): User
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [Role::Gang, Role::Family], true), 403, 'ส่วนนี้สำหรับแก๊งและครอบครัวเท่านั้น');

        return $user;
    }

    private function typeOf(User $user): GroupType
    {
        return $user->role === Role::Family ? GroupType::Family : GroupType::Gang;
    }

    /** กลุ่มที่บัญชีนี้สังกัด (ตั้งที่หน้าจัดการผู้ใช้ หรือผูกให้เองเมื่อสภาอนุมัติขึ้นทะเบียน) */
    private function groupOf(User $user): ?Group
    {
        $group = $user->group;

        return $group && ! $group->trashed() && $group->type === $this->typeOf($user) ? $group : null;
    }

    /** ผู้ยื่นขึ้นทะเบียนต้องเป็นหัวหน้าหรือรองในใบคำขอ (Discord ID ตรงกับบัญชีที่ล็อกอิน) */
    private function assertSubmitterInPayload(User $user, array $payload): void
    {
        $ids = array_filter([$payload['leader']['discord_id'] ?? null, ...array_column($payload['co_leaders'] ?? [], 'discord_id')]);
        if (! in_array((string) $user->discord_id, array_map('strval', $ids), true)) {
            throw ValidationException::withMessages(['leader.discord_id' => 'Discord ID ของหัวหน้าหรือรองหัวหน้าในคำขอ ต้องมีบัญชีของคุณ (ผู้ยื่น) อยู่ด้วย']);
        }
    }
}
