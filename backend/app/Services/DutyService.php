<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\DutyShift;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * เข้า/ออกเวรของสภา: ผู้ใช้ระดับสภาต้องมีเวรเปิดอยู่จึงใช้งานส่วนงานสภาได้
 *
 * - ออกเวรเองก่อนครบ short_shift_hours (3 ชม.) ต้องระบุหมายเหตุ
 * - หน้าเว็บส่งสัญญาณ (heartbeat) ทุกไม่กี่สิบวินาที; ถ้าเงียบเกิน heartbeat_grace_minutes (10 นาที)
 *   (ปิดแท็บ/เบราว์เซอร์/คอม/เน็ตหลุด) ระบบบังคับออกเวรให้ โดยนับเวลาถึงสัญญาณสุดท้าย
 *   พร้อมหมายเหตุ "บังคับออกเวรโดยระบบ"
 */
class DutyService
{
    /** ต้องเข้าเวรก่อนใช้งานไหม: ระดับสภาขึ้นไป ยกเว้นบทบาทที่ตั้งยกเว้นไว้ และปิดการบังคับได้ด้วย config */
    public function required(User $user): bool
    {
        return config('council.duty.enforced')
            && $user->atLeast(Role::Member)
            && ! in_array($user->role->value, config('council.duty.exempt_roles'), true);
    }

    /** เวรที่เปิดอยู่ (เวรที่หมดสัญญาณหรือเกินชั่วโมงสูงสุดถูกปิดให้ตรงนี้ แล้วถือว่าไม่ได้เข้าเวร) */
    public function current(User $user): ?DutyShift
    {
        $shift = DutyShift::where('user_id', $user->id)->whereNull('ended_at')->first();

        return $shift && $this->closeIfStale($shift) ? null : $shift;
    }

    /** ปิดเวรที่ค้าง; คืน true ถ้าปิดแล้ว */
    public function closeIfStale(DutyShift $shift): bool
    {
        $maxEnd = $shift->started_at->copy()->addHours(config('council.duty.max_hours'));
        $lastSeen = $shift->last_seen_at ?? $shift->started_at;

        if ($lastSeen->lt(now()->subMinutes(config('council.duty.heartbeat_grace_minutes')))) {
            // เวลาออกเวร = สัญญาณสุดท้าย (ไม่นับช่วงที่ไม่ได้อยู่หน้าเว็บ)
            $this->forceEnd($shift, min($lastSeen, $maxEnd), DutyShift::ABANDONED, 'ไม่มีสัญญาณจากหน้าเว็บ (ปิดเว็บ/เบราว์เซอร์/คอมพิวเตอร์)');

            return true;
        }

        if ($maxEnd->lt(now())) {
            $this->forceEnd($shift, $maxEnd, DutyShift::MAX_HOURS, 'เปิดเวรเกิน '.config('council.duty.max_hours').' ชั่วโมง');

            return true;
        }

        return false;
    }

    /** ปิดเวรที่ถูกทิ้งทั้งหมด (ใช้กับตารางงานรายนาที เพื่อให้ปิดทันเวลาแม้ไม่มีใครเรียก API) */
    public function closeAllStale(): int
    {
        $closed = 0;
        DutyShift::whereNull('ended_at')->with('user')->each(function (DutyShift $shift) use (&$closed) {
            if ($this->closeIfStale($shift)) {
                $closed++;
            }
        });

        return $closed;
    }

    public function start(User $user): DutyShift
    {
        if ($shift = $this->current($user)) {
            return $shift; // เข้าเวรอยู่แล้ว (เช่น กดซ้ำจากอีกแท็บ)
        }

        try {
            $shift = DutyShift::create(['user_id' => $user->id, 'started_at' => now(), 'last_seen_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return DutyShift::where('user_id', $user->id)->whereNull('ended_at')->firstOrFail();
        }

        activity('duty')->causedBy($user)->performedOn($shift)->event('start')->log('เข้าเวร');

        return $shift;
    }

    /** สัญญาณว่าผู้ใช้ยังอยู่หน้าเว็บ */
    public function heartbeat(User $user): ?DutyShift
    {
        $shift = $this->current($user);

        if ($shift) {
            $this->touch($shift);
        }

        return $shift;
    }

    /** ต่ออายุสัญญาณล่าสุด (เขียนไม่ถี่เกินไป แม้เปิดหลายแท็บหรือเรียก API รัวๆ) */
    public function touch(DutyShift $shift): void
    {
        if (! $shift->last_seen_at || $shift->last_seen_at->lt(now()->subSeconds(15))) {
            $shift->forceFill(['last_seen_at' => now()])->save();
        }
    }

    /** ออกเวรเอง: ก่อนครบเวลาขั้นต่ำต้องมีหมายเหตุ */
    public function end(User $user, ?string $note = null): ?DutyShift
    {
        $shift = $this->current($user);
        if (! $shift) {
            return null;
        }

        $note = trim((string) $note);
        $seconds = (int) $shift->started_at->diffInSeconds(now());
        $minSeconds = (int) (config('council.duty.short_shift_hours') * 3600);

        if ($seconds < $minSeconds && $note === '') {
            throw ValidationException::withMessages([
                'note' => 'ออกเวรก่อนครบ '.config('council.duty.short_shift_hours').' ชั่วโมง กรุณาระบุหมายเหตุ',
            ]);
        }

        $shift->update(['ended_at' => now(), 'ended_reason' => DutyShift::MANUAL, 'note' => $note !== '' ? $note : null]);

        activity('duty')->causedBy($user)->performedOn($shift)->event('end')
            ->withProperties(['seconds' => $seconds, 'note' => $shift->note, 'short' => $seconds < $minSeconds])
            ->log('ออกเวร (นาน '.$this->humanDuration($seconds).')'.($shift->note ? ' — '.$shift->note : ''));

        return $shift;
    }

    /** เวรล่าสุดที่ระบบบังคับออกให้ (ไว้แจ้งผู้ใช้ตอนกลับมา) */
    public function lastForcedEnd(User $user): ?DutyShift
    {
        $last = DutyShift::where('user_id', $user->id)->whereNotNull('ended_at')->orderByDesc('ended_at')->orderByDesc('id')->first();

        return $last?->endedBySystem() ? $last : null;
    }

    private function forceEnd(DutyShift $shift, $endedAt, string $reason, string $detail): void
    {
        $shift->update(['ended_at' => $endedAt, 'ended_reason' => $reason, 'note' => DutyShift::SYSTEM_NOTE]);

        $seconds = (int) $shift->started_at->diffInSeconds($endedAt);
        activity('duty')->causedBy($shift->user)->performedOn($shift)->event('system_end')
            ->withProperties(['seconds' => $seconds, 'reason' => $reason, 'detail' => $detail])
            ->log(DutyShift::SYSTEM_NOTE.' (นาน '.$this->humanDuration($seconds).') — '.$detail);
    }

    private function humanDuration(float|int $seconds): string
    {
        $s = (int) $seconds;

        return sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
    }
}
