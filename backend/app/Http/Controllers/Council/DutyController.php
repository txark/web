<?php

namespace App\Http\Controllers\Council;

use App\Http\Controllers\Controller;
use App\Models\DutyShift;
use App\Models\User;
use App\Services\DutyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** เข้า/ออกเวรของตัวเอง (ทำแทนคนอื่นไม่ได้) */
class DutyController extends Controller
{
    public function __construct(private DutyService $duty) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($request->user(), $this->duty->current($request->user()));
    }

    public function start(Request $request): JsonResponse
    {
        return $this->respond($request->user(), $this->duty->start($request->user()));
    }

    /** หน้าเว็บเรียกเป็นระยะเพื่อบอกว่ายังเปิดอยู่ */
    public function heartbeat(Request $request): JsonResponse
    {
        return $this->respond($request->user(), $this->duty->heartbeat($request->user()));
    }

    public function end(Request $request): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ], ['note.max' => 'หมายเหตุยาวเกินไป (ไม่เกิน 500 ตัวอักษร)']);

        $this->duty->end($request->user(), $data['note'] ?? null);

        return $this->respond($request->user(), null);
    }

    private function respond(User $user, ?DutyShift $shift): JsonResponse
    {
        $forced = $shift ? null : $this->duty->lastForcedEnd($user);

        return response()->json(['data' => [
            'on_duty' => $shift !== null,
            'started_at' => $shift?->started_at?->toIso8601String(),
            'required' => $this->duty->required($user), // false = บทบาทนี้ไม่ถูกบังคับให้เข้าเวร
            'max_hours' => config('council.duty.max_hours'),
            'short_shift_hours' => config('council.duty.short_shift_hours'), // ออกก่อนเท่านี้ต้องใส่หมายเหตุ
            'server_time' => now()->toIso8601String(), // ให้หน้าเว็บนับเวลาตามเซิร์ฟเวอร์ ไม่ใช่นาฬิกาเครื่อง
            // เวรล่าสุดที่ระบบบังคับออกให้ (หน้าแรกใช้แจ้งผู้ใช้ครั้งเดียว)
            'last_forced_end' => $forced ? [
                'id' => $forced->id,
                'ended_at' => $forced->ended_at->toIso8601String(),
                'started_at' => $forced->started_at->toIso8601String(),
                'note' => $forced->note,
                'reason' => $forced->ended_reason,
            ] : null,
        ]]);
    }
}
