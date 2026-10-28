<?php

namespace App\Http\Controllers\Council;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\DutyShift;
use App\Models\User;
use App\Services\DutyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * สรุปชั่วโมงเวรของสภาแต่ละคน (รองประธานขึ้นไป)
 * นับเฉพาะส่วนของเวรที่ตกอยู่ในช่วงวันที่เลือก (เวรที่คร่อมวันถูกตัดตามขอบเขต)
 * วันที่ตีความตามเขตเวลา council.report_timezone (ค่าเริ่มต้น Asia/Bangkok)
 */
class DutyReportController extends Controller
{
    public function __construct(private DutyService $duty) {}

    public function index(Request $request): JsonResponse
    {
        [$from, $to, $meta] = $this->range($request);
        $this->duty->closeAllStale(); // เวรที่ค้างเกินกำหนดถูกปิดก่อนสรุป ตัวเลขจะได้ไม่เกินจริง

        $shifts = $this->shiftsIn($from, $to)->groupBy('user_id');

        $councilRoles = array_map(fn (Role $r) => $r->value, array_filter(Role::cases(), fn (Role $r) => $r->atLeast(Role::Member)));
        $users = User::query()
            ->where(fn ($q) => $q->whereIn('role', $councilRoles)->whereNull('disabled_at'))
            ->orWhereIn('id', $shifts->keys())
            ->get();

        $minSeconds = (int) (config('council.duty.short_shift_hours') * 3600);
        $rows = $users->map(function (User $u) use ($shifts, $from, $to, $minSeconds) {
            $mine = $shifts->get($u->id, collect());
            $total = $mine->sum(fn (DutyShift $s) => $this->clipped($s, $from, $to));

            return [
                'user' => $this->userData($u),
                'total_seconds' => $total,
                'shifts' => $mine->count(),
                'avg_seconds' => $mine->count() ? intdiv($total, $mine->count()) : 0,
                'short_count' => $mine->filter(fn (DutyShift $s) => $s->ended_reason === DutyShift::MANUAL && $this->fullSeconds($s) < $minSeconds)->count(),
                'forced_count' => $mine->filter(fn (DutyShift $s) => $s->endedBySystem())->count(),
                'on_duty' => $mine->contains(fn (DutyShift $s) => $s->ended_at === null),
                'last_started_at' => $mine->max('started_at')?->toIso8601String(),
            ];
        })->sortBy([['total_seconds', 'desc'], fn ($a, $b) => strcmp($a['user']['display_name'], $b['user']['display_name'])])->values();

        $total = $rows->sum('total_seconds');

        return response()->json([
            'data' => $rows,
            'meta' => $meta + [
                'members' => $rows->count(),
                'total_seconds' => $total,
                'avg_seconds_per_member' => $rows->count() ? intdiv($total, $rows->count()) : 0,
                'zero_members' => $rows->where('total_seconds', 0)->count(),
                'short_shift_hours' => config('council.duty.short_shift_hours'),
            ],
        ]);
    }

    /** รายการเวรของคนเดียวในช่วงที่เลือก (ใช้ตอนกดดูรายละเอียด) */
    public function show(Request $request, User $user): JsonResponse
    {
        [$from, $to, $meta] = $this->range($request);
        $this->duty->closeAllStale();

        $minSeconds = (int) (config('council.duty.short_shift_hours') * 3600);
        $shifts = $this->shiftsIn($from, $to, $user->id)->sortByDesc('started_at')->values()->map(fn (DutyShift $s) => [
            'id' => $s->id,
            'started_at' => $s->started_at->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'open' => $s->ended_at === null,
            'seconds' => $this->fullSeconds($s),
            'counted_seconds' => $this->clipped($s, $from, $to), // เฉพาะส่วนที่อยู่ในช่วงที่เลือก
            'reason' => $s->ended_reason,
            'forced' => $s->endedBySystem(),
            'short' => $s->ended_reason === DutyShift::MANUAL && $this->fullSeconds($s) < $minSeconds,
            'note' => $s->note,
        ]);

        return response()->json([
            'data' => ['user' => $this->userData($user), 'shifts' => $shifts],
            'meta' => $meta,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon, 2: array<string, mixed>} ขอบเขตเป็น UTC [from, to) */
    private function range(Request $request): array
    {
        $tz = config('council.report_timezone');
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [
            'from.date_format' => 'รูปแบบวันที่ไม่ถูกต้อง',
            'to.date_format' => 'รูปแบบวันที่ไม่ถูกต้อง',
            'to.after_or_equal' => 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่มต้น',
        ]);

        $toDay = Carbon::parse($data['to'] ?? now($tz)->toDateString(), $tz)->startOfDay();
        $fromDay = Carbon::parse($data['from'] ?? $toDay->copy()->subDays(29)->toDateString(), $tz)->startOfDay();

        if ($fromDay->diffInDays($toDay) > 366) {
            abort(422, 'เลือกช่วงวันที่ได้ไม่เกิน 1 ปี');
        }

        return [
            $fromDay->copy()->utc(),
            $toDay->copy()->addDay()->utc(),
            ['from' => $fromDay->toDateString(), 'to' => $toDay->toDateString(), 'timezone' => $tz],
        ];
    }

    private function shiftsIn(Carbon $from, Carbon $to, ?int $userId = null)
    {
        return DutyShift::query()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where('started_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $from))
            ->get();
    }

    private function endOf(DutyShift $s): Carbon
    {
        return $s->ended_at ?? now();
    }

    private function fullSeconds(DutyShift $s): int
    {
        return max(0, (int) $s->started_at->diffInSeconds($this->endOf($s)));
    }

    /** วินาทีของเวรนี้ที่ตกอยู่ในช่วง [from, to) */
    private function clipped(DutyShift $s, Carbon $from, Carbon $to): int
    {
        $start = $s->started_at->greaterThan($from) ? $s->started_at : $from;
        $end = $this->endOf($s)->lessThan($to) ? $this->endOf($s) : $to;

        return max(0, (int) $start->diffInSeconds($end, false));
    }

    private function userData(User $u): array
    {
        return [
            'id' => $u->id,
            'display_name' => $u->character_name ?: $u->name,
            'discord_name' => $u->name,
            'avatar' => $u->avatar,
            'role' => $u->role->value,
            'role_label' => $u->role->label(),
            'disabled' => $u->isDisabled(),
        ];
    }
}
