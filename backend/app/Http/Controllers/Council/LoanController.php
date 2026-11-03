<?php

namespace App\Http\Controllers\Council;

use App\Enums\MemberPosition;
use App\Http\Controllers\Controller;
use App\Models\CouncilLoan;
use App\Models\CouncilLoanPayment;
use App\Models\CouncilLoanSetting;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * สัญญากู้เงินของสภา (ประธานและรองประธาน): แก๊ง/ครอบครัวขอกู้ ประธานหรือรองเป็นผู้บันทึกสัญญา เบิกจากกองทุนของสภา
 *
 * บันทึกแล้วแก้/ลบไม่ได้ — ผิดให้ "ยกเลิกสัญญา" หรือ "ยกเลิกรายการรับคืน" ซึ่งเป็นรายการต่อท้าย ทุกการกระทำลง activity log
 * ยอดกู้ (ต้นเงิน) = รายจ่าย · การรับคืน (รวมดอกเบี้ยและค่าปรับ) = รายรับ ของหน้าการเงิน (FinanceController ดึงมารวมให้เอง)
 * ค่าเริ่มต้นของดอกเบี้ย/ค่าปรับ/จำนวนงวดมาจากตั้งค่ากลาง (LoanSettingController) สัญญาใหม่ปรับเฉพาะสัญญาได้ และเก็บค่าตอนทำสัญญา
 */
class LoanController extends Controller
{
    private const MESSAGES = [
        'group_id.required' => 'กรุณาเลือกแก๊ง/ครอบครัว',
        'group_id.exists' => 'ไม่พบแก๊ง/ครอบครัวที่เลือก',
        'borrower_name.required' => 'กรุณากรอกชื่อผู้กู้',
        'borrower_name.max' => 'ชื่อผู้กู้ยาวเกินไป (ไม่เกิน 120 ตัวอักษร)',
        'reason.required' => 'กรุณากรอกเหตุผลการกู้',
        'reason.max' => 'เหตุผลยาวเกินไป (ไม่เกิน 1,000 ตัวอักษร)',
        'principal.required' => 'กรุณากรอกจำนวนเงินที่ต้องการกู้',
        'principal.integer' => 'จำนวนเงินต้องเป็นตัวเลข',
        'principal.min' => 'จำนวนเงินต้องมากกว่า 0',
        'principal.max' => 'จำนวนเงินมากเกินไป',
        'interest_rate.numeric' => 'ดอกเบี้ยต้องเป็นตัวเลข',
        'interest_rate.min' => 'ดอกเบี้ยต้องไม่ติดลบ',
        'interest_rate.max' => 'ดอกเบี้ยต่อสัปดาห์ต้องไม่เกิน 100%',
        'installments.integer' => 'จำนวนงวดต้องเป็นตัวเลข',
        'installments.min' => 'จำนวนงวดต้องอย่างน้อย 1 งวด',
        'late_fee_type.in' => 'วิธีคิดค่าปรับไม่ถูกต้อง',
        'late_fee_value.numeric' => 'ค่าปรับต้องเป็นตัวเลข',
        'late_fee_value.min' => 'ค่าปรับต้องไม่ติดลบ',
        'late_fee_value.max' => 'ค่าปรับมากเกินไป',
        'amount.required' => 'กรุณากรอกจำนวนเงินที่รับคืน',
        'amount.integer' => 'จำนวนเงินต้องเป็นตัวเลข',
        'amount.min' => 'จำนวนเงินต้องมากกว่า 0',
        'amount.max' => 'จำนวนเงินมากเกินไป',
        'loaned_at.required' => 'กรุณาเลือกวันที่กู้',
        'loaned_at.date_format' => 'วันที่ไม่ถูกต้อง',
        'loaned_at.before_or_equal' => 'วันที่กู้ต้องไม่เกินวันนี้',
        'due_at.date_format' => 'วันกำหนดคืนไม่ถูกต้อง',
        'due_at.after_or_equal' => 'วันกำหนดคืนต้องไม่ก่อนวันที่กู้',
        'paid_at.required' => 'กรุณาเลือกวันที่รับคืน',
        'paid_at.date_format' => 'วันที่ไม่ถูกต้อง',
        'paid_at.before_or_equal' => 'วันที่รับคืนต้องไม่เกินวันนี้',
        'paid_at.after_or_equal' => 'วันที่รับคืนต้องไม่ก่อนวันที่กู้',
        'note.max' => 'หมายเหตุยาวเกินไป (ไม่เกิน 1,000 ตัวอักษร)',
        'reason_cancel.required' => 'กรุณาระบุเหตุผล',
    ];

    /** เงินกู้ของแก๊ง/ครอบครัวนี้ (ใหม่→เก่า) สำหรับไทม์ไลน์ของทะเบียน · ข้อมูลการเงิน เปิดได้เฉพาะรองประธานขึ้นไป (กำหนดที่ route) */
    public function forGroup(Group $group): JsonResponse
    {
        $loans = CouncilLoan::query()
            ->where('group_id', $group->id)
            ->with(['group', 'payments.creator:id,name,character_name', 'creator:id,name,character_name,role', 'canceller:id,name,character_name'])
            ->orderByDesc('loaned_at')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $loans->map(function (CouncilLoan $l) {
            $row = $this->row($l, false);
            unset($row['payments']);

            return $row;
        })->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'partial', 'overdue', 'paid', 'cancelled', 'unpaid'])], // unpaid = ยังไม่คืนครบ (open + partial + overdue)
            'q' => ['nullable', 'string', 'max:100'],
            'group_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = trim($f['q'] ?? '');
        $loans = CouncilLoan::query()
            ->with(['group', 'payments.creator:id,name,character_name', 'creator:id,name,character_name,role', 'canceller:id,name,character_name'])
            ->when($f['group_id'] ?? null, fn ($query, $id) => $query->where('group_id', $id))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->whereHas('group', fn ($g) => $g->withTrashed()->where(fn ($n) => $n->whereLike('name', "%{$q}%")->orWhereLike('short_name', "%{$q}%")))
                ->orWhereLike('borrower_name', "%{$q}%")->orWhereLike('reason', "%{$q}%")->orWhereLike('note', "%{$q}%")))
            ->get();

        // เรียงตามกำหนดคืนใกล้สุดก่อน (สัญญาที่ยังต้องคืนขึ้นก่อน แล้วคืนครบ/ยกเลิก; ไม่มีกำหนดไว้ท้าย) ตามแบบร่าง
        $loans = $loans->sortBy(fn (CouncilLoan $l) => [['paid' => 1, 'cancelled' => 2][$l->status()] ?? 0, $this->nextDue($l) ?? '9999-12-31', -$l->id])->values();

        // สรุปคิดจากทุกสถานะของผลค้นหา (ไม่ขึ้นกับตัวกรองสถานะ) ให้ตัวเลขบนการ์ดคงที่เมื่อสลับตัวกรอง
        $live = $loans->filter(fn (CouncilLoan $l) => $l->status() !== 'cancelled');
        $overdue = $live->filter(fn (CouncilLoan $l) => $l->status() === 'overdue');
        $summary = [
            'lent' => (int) $live->sum('principal'),
            'interest' => (int) $live->sum(fn (CouncilLoan $l) => $l->interestTotal()),
            'late_fees' => (int) $live->sum(fn (CouncilLoan $l) => $l->lateFeeTotal()),
            'repaid' => (int) $live->sum(fn (CouncilLoan $l) => $l->repaid()),
            'outstanding' => (int) $live->sum(fn (CouncilLoan $l) => $l->outstanding()),
            'count' => $live->count(),
            'overdue_count' => $overdue->count(),
            'overdue_amount' => (int) $overdue->sum(fn (CouncilLoan $l) => $l->outstanding()),
            'currency' => config('council.fees.currency'),
        ];

        if ($status = $f['status'] ?? null) {
            $loans = $loans->filter(fn (CouncilLoan $l) => $status === 'unpaid' ? in_array($l->status(), ['open', 'partial', 'overdue'], true) : $l->status() === $status)->values();
        }

        $perPage = (int) ($f['per_page'] ?? 20);
        $page = (int) ($f['page'] ?? 1);

        return response()->json([
            'data' => $loans->forPage($page, $perPage)->map(fn (CouncilLoan $l) => $this->row($l, false))->values(),
            'meta' => [
                'total' => $loans->count(), 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($loans->count() / $perPage)),
                'summary' => $summary, 'central' => $this->central(), 'settings' => $this->settings(),
            ],
        ]);
    }

    /** รายละเอียดสัญญา 1 รายการ พร้อมตารางงวดและประวัติรับคืน (ใช้กับ popup บันทึกการคืนและเอกสารสัญญา) */
    public function show(CouncilLoan $loan): JsonResponse
    {
        return response()->json(['data' => $this->row($this->fresh($loan)), 'meta' => ['central' => $this->central(), 'settings' => $this->settings()]]);
    }

    public function store(Request $request): JsonResponse
    {
        $settings = CouncilLoanSetting::current();
        $d = $request->validate([
            'group_id' => ['required', 'integer', Rule::exists('groups', 'id')->whereNull('deleted_at')],
            'borrower_name' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:1000'],
            'principal' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:'.$settings->max_installments],
            'late_fee_type' => ['nullable', Rule::in(array_keys(CouncilLoanSetting::FEE_TYPES))],
            'late_fee_value' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'loaned_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'due_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:loaned_at'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], self::MESSAGES + ['installments.max' => "ผ่อนได้ไม่เกิน {$settings->max_installments} งวด (ตามตั้งค่ากลาง)"]);

        // ค่าที่ไม่ได้ส่งมาใช้ตามตั้งค่ากลาง (ดึงอัตโนมัติ) แล้วเก็บค่าตอนทำสัญญาไว้กับสัญญา
        $d['interest_rate'] = round((float) ($d['interest_rate'] ?? $settings->default_interest), 2);
        $d['installments'] = (int) ($d['installments'] ?? 1);
        $d['late_fee_type'] = $d['late_fee_type'] ?? $settings->late_fee_type;
        $d['late_fee_value'] = round((float) ($d['late_fee_value'] ?? $settings->late_fee_amount), 2);

        if ($d['installments'] === 1 && empty($d['due_at'])) {
            throw ValidationException::withMessages(['due_at' => 'กรุณาเลือกวันกำหนดคืน']);
        }
        if ($d['installments'] > 1) {
            $d['due_at'] = null; // ผ่อนรายสัปดาห์: วันครบกำหนดแต่ละงวดคำนวณจากวันที่กู้ (+7 วันต่องวด)
        }

        // ยศผู้เขียนสัญญา + ตำแหน่งของผู้กู้ในกลุ่ม (หัวหน้า/รองหัวหน้า ถ้าชื่อตรงกับทะเบียน) เก็บลงหนังสือสัญญา
        $name = mb_strtolower(trim($d['borrower_name']));
        $position = GroupMember::query()->where('group_id', $d['group_id'])->whereNull('left_at')
            ->whereIn('position', [MemberPosition::Leader->value, MemberPosition::CoLeader->value])
            ->get()->first(fn (GroupMember $m) => mb_strtolower(trim((string) $m->character_name)) === $name);

        $loan = CouncilLoan::create($d + [
            'created_by' => $request->user()->id,
            'creator_rank' => $request->user()->role->thaiName(),
            'borrower_position' => $position?->position->label(),
        ]);
        if ($loan->installments > 1) {
            $loan->update(['due_at' => $loan->fresh()->finalDue()]); // เก็บวันครบกำหนดงวดสุดท้ายไว้แสดง
        }

        return response()->json(['data' => $this->row($this->fresh($loan)), 'meta' => ['central' => $this->central(), 'settings' => $this->settings()]], 201);
    }

    /**
     * เก็บรูป PNG หนังสือสัญญา (หน้าเว็บสร้างรูปแล้วอัปโหลดมา) แล้วคืนลิงก์รูปสำหรับวางใน Discord
     * ทุกครั้งที่สร้างใหม่ ลิงก์เดิมใช้ไม่ได้ (ไฟล์เก่าถูกลบ) · เปิดดูได้โดยไม่ต้องล็อกอินแต่ต้องมีโทเคน 40 ตัวอักษร
     */
    public function shareImage(Request $request, CouncilLoan $loan): JsonResponse
    {
        $request->validate(['image' => ['required', 'file', 'mimes:png', 'max:8192']], ['image.required' => 'ไม่พบรูปที่อัปโหลด', 'image.mimes' => 'รูปต้องเป็นไฟล์ PNG', 'image.max' => 'รูปใหญ่เกินไป (ไม่เกิน 8 MB)']);

        $old = $loan->share_token;
        $token = Str::random(40);
        Storage::disk('local')->putFileAs('loan-contracts', $request->file('image'), "{$token}.png");
        $loan->forceFill(['share_token' => $token])->save();
        if ($old) {
            Storage::disk('local')->delete("loan-contracts/{$old}.png");
        }
        activity('loans')->causedBy($request->user())->performedOn($loan)->event('shared')
            ->withProperties(['number' => 'WV-LN-'.str_pad((string) $loan->id, 6, '0', STR_PAD_LEFT)])->log('สร้างลิงก์รูปหนังสือสัญญากู้ WV-LN-'.str_pad((string) $loan->id, 6, '0', STR_PAD_LEFT));

        return response()->json(['token' => $token, 'url' => url("/api/public/loan-contracts/{$token}.png")]);
    }

    /** รูปหนังสือสัญญากู้ตามลิงก์ (สาธารณะ ไม่ต้องล็อกอิน) */
    public function publicImage(string $token)
    {
        $loan = CouncilLoan::query()->where('share_token', $token)->first();
        abort_unless($loan && Storage::disk('local')->exists("loan-contracts/{$token}.png"), 404);

        return Storage::disk('local')->response("loan-contracts/{$token}.png", "{$token}.png", [
            'Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=3600', 'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** ยกเลิกสัญญา (รายการต่อท้าย): ต้องไม่มีรายการรับคืนที่ยังมีผล ยอดกู้คืนเข้ากองทุนและลงบันทึกในสรุปการเงิน */
    public function cancel(Request $request, CouncilLoan $loan): JsonResponse
    {
        $d = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'กรุณาระบุเหตุผลที่ยกเลิกสัญญา', 'reason.max' => 'เหตุผลยาวเกินไป']);

        if ($loan->isCancelled()) {
            throw ValidationException::withMessages(['reason' => 'สัญญานี้ถูกยกเลิกไปแล้ว']);
        }
        if ($loan->activePayments()->isNotEmpty()) {
            throw ValidationException::withMessages(['reason' => 'สัญญานี้มีการรับคืนแล้ว ต้องยกเลิกรายการรับคืนทั้งหมดก่อนจึงจะยกเลิกสัญญาได้']);
        }

        $loan->update(['cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancel_reason' => $d['reason']]);

        return response()->json(['data' => $this->row($this->fresh($loan)), 'meta' => ['central' => $this->central(), 'settings' => $this->settings()]]);
    }

    public function storePayment(Request $request, CouncilLoan $loan): JsonResponse
    {
        if ($loan->isCancelled()) {
            throw ValidationException::withMessages(['amount' => 'สัญญานี้ถูกยกเลิกแล้ว รับคืนไม่ได้']);
        }

        $d = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'paid_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today(), 'after_or_equal:'.$loan->loaned_at->format('Y-m-d')],
            'note' => ['nullable', 'string', 'max:1000'],
        ], self::MESSAGES);

        DB::transaction(function () use ($loan, $d, $request) {
            DB::table('council_loans')->where('id', $loan->id)->lockForUpdate()->first(); // กันสองคนรับคืนพร้อมกันจนเกินยอด
            $loan->unsetRelation('payments');

            $outstanding = $loan->outstanding();
            if ($d['amount'] > $outstanding) {
                throw ValidationException::withMessages(['amount' => $outstanding === 0 ? 'สัญญานี้คืนครบแล้ว' : 'จำนวนที่รับคืนเกินยอดคงค้าง ('.number_format($outstanding).')']);
            }
            $loan->payments()->create($d + ['created_by' => $request->user()->id]);

            // วันที่รับคืนย้อนหลังอาจเปลี่ยนว่างวดไหนชำระช้า (ค่าปรับเปลี่ยน) ถ้าทำให้ยอดรับคืนเกินยอดที่ต้องชำระ ให้ปฏิเสธ (ย้อนกลับทั้งหมด)
            $loan->unsetRelation('payments');
            if ($loan->repaid() > $loan->totalPayable()) {
                throw ValidationException::withMessages(['paid_at' => 'วันที่รับคืนนี้ทำให้ยอดรับคืนเกินยอดที่ต้องชำระ กรุณาตรวจวันที่และจำนวนเงิน']);
            }
        });

        return response()->json(['data' => $this->row($this->fresh($loan)), 'meta' => ['central' => $this->central(), 'settings' => $this->settings()]], 201);
    }

    /** ยกเลิกรายการรับคืน (รายการต่อท้าย): รายการเดิมยังอยู่ ยอดรับคืนหักออก และลงสรุปการเงินเป็นรายจ่ายหมวดเงินกู้ */
    public function reversePayment(Request $request, CouncilLoan $loan, CouncilLoanPayment $payment): JsonResponse
    {
        abort_unless($payment->loan_id === $loan->id, 404);
        $d = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'กรุณาระบุเหตุผลที่ยกเลิกรายการ', 'reason.max' => 'เหตุผลยาวเกินไป']);

        if ($payment->isReversal()) {
            throw ValidationException::withMessages(['reason' => 'นี่คือรายการยกเลิก ยกเลิกซ้ำไม่ได้']);
        }
        if ($loan->payments()->where('reversal_of', $payment->id)->exists()) {
            throw ValidationException::withMessages(['reason' => 'รายการนี้ถูกยกเลิกไปแล้ว']);
        }
        if ($loan->isCancelled()) {
            throw ValidationException::withMessages(['reason' => 'สัญญานี้ถูกยกเลิกแล้ว']);
        }

        $loan->payments()->create([
            'reversal_of' => $payment->id, 'amount' => $payment->amount, 'paid_at' => $this->today(),
            'reason' => $d['reason'], 'note' => $d['reason'], 'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $this->row($this->fresh($loan)), 'meta' => ['central' => $this->central(), 'settings' => $this->settings()]], 201);
    }

    // ───────────────────────── ภายใน ─────────────────────────

    /** วันครบกำหนดของงวดที่ยังไม่ชำระงวดแรก (ใช้เรียงรายการ) */
    private function nextDue(CouncilLoan $l): ?string
    {
        $s = collect($l->schedule())->first(fn (array $x) => ! in_array($x['status'], ['paid', 'paid_late'], true));

        return $s['due_at'] ?? null;
    }

    /** @return array<string, mixed> */
    private function settings(): array
    {
        $s = CouncilLoanSetting::current();

        return ['default_interest' => $s->default_interest, 'late_fee_type' => $s->late_fee_type, 'late_fee_amount' => $s->late_fee_amount, 'max_installments' => $s->max_installments];
    }

    /** กองทุนของสภา: ยอดสะสมทั้งหมดของหน้าการเงิน (เงินกู้เบิกออก/รับคืนเข้ากองทุนนี้อยู่แล้ว) @return array<string, int|string> */
    private function central(): array
    {
        return app(FinanceController::class)->centralBalance() + ['currency' => config('council.fees.currency')];
    }

    private function today(): string
    {
        return now(config('council.report_timezone'))->toDateString();
    }

    private function fresh(CouncilLoan $loan): CouncilLoan
    {
        return $loan->fresh(['group', 'payments.creator:id,name,character_name', 'creator:id,name,character_name,role', 'canceller:id,name,character_name']);
    }

    /** @return array<string, mixed> */
    private function row(CouncilLoan $l, bool $withSchedule = true): array
    {
        $status = $l->status();
        $g = $l->group;
        $schedule = $l->schedule();
        $overdueFirst = collect($schedule)->firstWhere('status', 'overdue');
        $nextUnpaid = collect($schedule)->first(fn (array $s) => ! in_array($s['status'], ['paid', 'paid_late'], true));
        $parts = $l->outstandingParts();
        $reversed = $l->payments->whereNotNull('reversal_of')->pluck('reversal_of')->all();

        return [
            'id' => $l->id,
            'group' => $g ? ['id' => $g->id, 'name' => $g->name, 'short_name' => $g->short_name, 'type' => $g->type->value, 'is_lady' => (bool) $g->is_lady, 'logo_url' => $g->logo_url, 'color_hex' => $g->color_hex, 'deleted' => $g->trashed()] : null,
            'borrower_name' => (string) $l->borrower_name, 'reason' => (string) $l->reason,
            'principal' => $l->principal, 'interest_rate' => $l->interest_rate, 'installments' => $l->installments,
            'late_fee_type' => $l->late_fee_type, 'late_fee_value' => $l->late_fee_value, 'late_fee_label' => CouncilLoanSetting::FEE_TYPES[$l->late_fee_type] ?? $l->late_fee_type,
            'interest_total' => $l->interestTotal(), 'late_fee_total' => $l->lateFeeTotal(), 'total_payable' => $l->totalPayable(),
            'repaid' => $l->repaid(), 'outstanding' => $l->outstanding(), 'outstanding_pi' => $parts['pi'], 'outstanding_fee' => $parts['fee'],
            'loaned_at' => $l->loaned_at->format('Y-m-d'), 'due_at' => $l->due_at?->format('Y-m-d'), 'final_due' => $schedule[count($schedule) - 1]['due_at'],
            'next_due' => $nextUnpaid ? ['no' => $nextUnpaid['no'], 'due_at' => $nextUnpaid['due_at'], 'amount' => max(0, $nextUnpaid['amount'] - $nextUnpaid['paid'])] : null,
            'status' => $status,
            'status_label' => ['open' => 'กำลังกู้', 'partial' => 'คืนบางส่วน', 'overdue' => 'เกินกำหนด', 'paid' => 'คืนครบ', 'cancelled' => 'ยกเลิกแล้ว'][$status],
            'days_overdue' => $overdueFirst ? $overdueFirst['days_late'] : 0,
            'note' => (string) $l->note, 'by' => $this->nameOf($l->creator),
            'by_rank' => $l->creator_rank ?: ($l->creator?->role?->thaiName() ?? ''), 'borrower_position' => (string) $l->borrower_position,
            'cancelled_at' => $l->cancelled_at?->toIso8601String(), 'cancel_reason' => (string) $l->cancel_reason, 'cancelled_by' => $this->nameOf($l->canceller),
            'created_at' => $l->created_at?->toIso8601String(),
            'schedule' => $withSchedule ? $schedule : null,
            // รายการรับคืนทั้งหมด (รวมรายการยกเลิก) ใหม่สุดก่อน: reversed = รายการนี้ถูกยกเลิกแล้ว · is_reversal = นี่คือรายการยกเลิก
            'payments' => $l->payments->sortByDesc(fn ($p) => $p->paid_at->format('Y-m-d').str_pad((string) $p->id, 10, '0', STR_PAD_LEFT))->values()->map(fn (CouncilLoanPayment $p) => [
                'id' => $p->id, 'amount' => $p->amount, 'paid_at' => $p->paid_at->format('Y-m-d'), 'note' => (string) $p->note, 'by' => $this->nameOf($p->creator),
                'is_reversal' => $p->isReversal(), 'reversal_of' => $p->reversal_of, 'reversed' => in_array($p->id, $reversed, true), 'reason' => (string) $p->reason,
            ])->all(),
        ];
    }

    private function nameOf(?User $u): string
    {
        return $u ? ($u->character_name ?: $u->name) : '';
    }
}
