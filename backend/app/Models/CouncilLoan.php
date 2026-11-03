<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * สัญญาเงินกู้ที่สภาปล่อยให้แก๊ง/ครอบครัว (เบิกจากกองทุนของสภา)
 *
 * บันทึกแล้วแก้/ลบไม่ได้: ถ้าผิดให้ยกเลิกสัญญา (cancelled_*) หรือยกเลิกรายการรับคืนด้วยรายการต่อท้าย
 *
 * เงื่อนไข (เก็บค่าตอนทำสัญญา ไม่เปลี่ยนตามค่ากลางที่แก้ทีหลัง):
 * - ดอกเบี้ยแบบง่าย % ต่อสัปดาห์ = ต้น × % × จำนวนสัปดาห์ (= จำนวนงวด) ไม่ทบต้น แบ่งเท่าๆ กันทุกงวด
 * - ผ่อนรายสัปดาห์ N งวด งวดที่ k ครบกำหนด = วันให้กู้ + 7k วัน (งวดเดียวใช้ due_at)
 * - ค่าปรับเมื่อเลยกำหนดต่องวด: day = จำนวนเงิน × จำนวนวันที่เลย · week = จำนวนเงิน × สัปดาห์ที่เลย (ปัดขึ้น) · once = จำนวนเงินครั้งเดียวต่อสัญญา
 *   นับถึงวันที่ชำระงวดนั้นครบ (หรือถึงวันนี้ถ้ายังไม่ครบ)
 * - การชำระตัดงวดตามลำดับ: งวดที่ k ครบเมื่อยอดสะสมที่รับคืนถึงยอดสะสมของงวดนั้น ค่าปรับเป็นยอดแยกที่ชำระท้ายสุด
 */
#[Fillable(['group_id', 'borrower_name', 'reason', 'principal', 'interest_rate', 'installments', 'late_fee_type', 'late_fee_value', 'loaned_at', 'due_at', 'note', 'created_by', 'creator_rank', 'borrower_position', 'share_token', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class CouncilLoan extends Model
{
    use LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'principal' => 'integer', 'interest_rate' => 'float', 'installments' => 'integer', 'late_fee_value' => 'float',
            'loaned_at' => 'date:Y-m-d', 'due_at' => 'date:Y-m-d', 'cancelled_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CouncilLoanPayment::class, 'loan_id');
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /** @return Collection<int, CouncilLoanPayment> รายการรับคืนที่ยังมีผล (ไม่นับรายการยกเลิก และรายการที่ถูกยกเลิกแล้ว) */
    public function activePayments(): Collection
    {
        $all = $this->relationLoaded('payments') ? $this->payments : $this->payments()->get();
        $reversed = $all->whereNotNull('reversal_of')->pluck('reversal_of')->all();

        return $all->filter(fn (CouncilLoanPayment $p) => $p->reversal_of === null && ! in_array($p->id, $reversed, true))->values();
    }

    /** ยอดที่รับคืนแล้ว (หักรายการที่ยกเลิก) */
    public function repaid(): int
    {
        return (int) $this->activePayments()->sum('amount');
    }

    /** ดอกเบี้ยรวมทั้งสัญญา = ต้นเงิน × % ต่อสัปดาห์ × จำนวนสัปดาห์ (= จำนวนงวด) ปัดเป็นจำนวนเต็ม */
    public function interestTotal(): int
    {
        return (int) round($this->principal * ($this->interest_rate / 100) * max(1, $this->installments));
    }

    private static function today(): string
    {
        return Carbon::now(config('council.report_timezone'))->toDateString();
    }

    private static function daysBetween(string $from, string $to): int
    {
        return max(0, (int) Carbon::parse($from)->startOfDay()->diffInDays(Carbon::parse($to)->startOfDay(), false));
    }

    /**
     * ตารางงวดผ่อน พร้อมสถานะและค่าปรับ ณ วันนี้ (เวลาไทย)
     *
     * @return list<array<string, mixed>>
     */
    public function schedule(): array
    {
        $n = max(1, (int) $this->installments);
        $loaned = Carbon::parse($this->loaned_at->format('Y-m-d'));
        $interest = $this->interestTotal();
        $pBase = intdiv($this->principal, $n);
        $iBase = intdiv($interest, $n);
        $today = self::today();
        $unit = (int) round($this->late_fee_value);

        $pays = $this->activePayments()->sortBy(fn (CouncilLoanPayment $p) => $p->paid_at->format('Y-m-d').str_pad((string) $p->id, 12, '0', STR_PAD_LEFT))->values();
        $repaid = (int) $pays->sum('amount');

        $rows = [];
        $cum = 0;
        $onceCharged = false;
        foreach (range(1, $n) as $k) {
            $principal = $k < $n ? $pBase : $this->principal - $pBase * ($n - 1);
            $int = $k < $n ? $iBase : $interest - $iBase * ($n - 1);
            $amount = $principal + $int;
            $before = $cum;
            $cum += $amount;

            $due = $n === 1 ? $this->due_at?->format('Y-m-d') : $loaned->copy()->addDays(7 * $k)->toDateString();

            // วันที่ยอดสะสมที่รับคืนถึงยอดสะสมของงวดนี้ (วันที่ชำระครบงวด)
            $satisfied = null;
            $run = 0;
            foreach ($pays as $p) {
                $run += $p->amount;
                if ($run >= $cum) {
                    $satisfied = $p->paid_at->format('Y-m-d');
                    break;
                }
            }

            $daysLate = $due === null ? 0 : self::daysBetween($due, $satisfied ?? $today);
            $fee = match ($this->late_fee_type) {
                'week' => (int) ceil($daysLate / 7) * $unit,
                'once' => $daysLate > 0 && ! $onceCharged ? $unit : 0,
                default => $daysLate * $unit,
            };
            if ($this->late_fee_type === 'once' && $fee > 0) {
                $onceCharged = true;
            }

            $status = $satisfied !== null
                ? ($daysLate > 0 ? 'paid_late' : 'paid')
                : ($due !== null && $today > $due ? 'overdue' : ($due === $today ? 'due' : 'upcoming'));

            $rows[] = [
                'no' => $k, 'due_at' => $due, 'principal' => $principal, 'interest' => $int, 'amount' => $amount, 'cumulative' => $cum,
                'paid' => max(0, min($amount, $repaid - $before)), 'days_late' => $daysLate, 'late_fee' => $fee, 'status' => $status, 'paid_on' => $satisfied,
            ];
        }

        return $rows;
    }

    public function lateFeeTotal(): int
    {
        return $this->isCancelled() ? 0 : (int) array_sum(array_column($this->schedule(), 'late_fee'));
    }

    /** ยอดที่ต้องชำระทั้งหมด ณ วันนี้ = ต้นเงิน + ดอกเบี้ย + ค่าปรับที่เกิดแล้ว */
    public function totalPayable(): int
    {
        return $this->principal + $this->interestTotal() + $this->lateFeeTotal();
    }

    /** ยอดคงค้าง (รวมดอกเบี้ยและค่าปรับ) สัญญาที่ยกเลิกแล้ว = 0 */
    public function outstanding(): int
    {
        return $this->isCancelled() ? 0 : max(0, $this->totalPayable() - $this->repaid());
    }

    /**
     * แยกยอดคงค้างเป็นส่วนต้น+ดอกเบี้ย กับส่วนค่าปรับ (รับคืนตัดต้น+ดอกเบี้ยก่อน ค่าปรับท้ายสุด)
     *
     * @return array{pi: int, fee: int}
     */
    public function outstandingParts(): array
    {
        if ($this->isCancelled()) {
            return ['pi' => 0, 'fee' => 0];
        }
        $pi = $this->principal + $this->interestTotal();
        $repaid = $this->repaid();

        return ['pi' => max(0, $pi - $repaid), 'fee' => max(0, $this->lateFeeTotal() - max(0, $repaid - $pi))];
    }

    /** cancelled · paid = คืนครบ · overdue = มีงวดที่เลยกำหนดแล้วยังไม่ชำระ · partial = คืนบางส่วน · open = กำลังกู้ */
    public function status(): string
    {
        if ($this->isCancelled()) {
            return 'cancelled';
        }
        if ($this->outstanding() === 0) {
            return 'paid';
        }
        if (collect($this->schedule())->contains(fn (array $s) => $s['status'] === 'overdue')) {
            return 'overdue';
        }

        return $this->repaid() > 0 ? 'partial' : 'open';
    }

    /** วันครบกำหนดงวดสุดท้าย (ไม่มีกำหนด = null) */
    public function finalDue(): ?string
    {
        $s = $this->schedule();

        return $s[count($s) - 1]['due_at'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['group_id', 'borrower_name', 'reason', 'principal', 'interest_rate', 'installments', 'late_fee_type', 'late_fee_value', 'loaned_at', 'due_at', 'note', 'cancelled_at', 'cancel_reason'])->logOnlyDirty();
    }
}
