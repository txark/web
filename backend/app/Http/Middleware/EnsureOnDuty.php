<?php

namespace App\Http\Middleware;

use App\Services\DutyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ส่วนงานสภาใช้ได้เฉพาะตอนเข้าเวร: ->middleware('duty') (วางหลัง role:) */
class EnsureOnDuty
{
    public function __construct(private DutyService $duty) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->duty->required($user)) {
            $shift = $this->duty->current($user);

            if (! $shift) {
                return response()->json([
                    'message' => 'ต้องเข้าเวรก่อนจึงจะใช้งานส่วนนี้ได้',
                    'code' => 'off_duty',
                ], 403);
            }

            $this->duty->touch($shift); // ใช้งานอยู่ = ยังไม่ได้ปิดเว็บ
        }

        return $next($request);
    }
}
