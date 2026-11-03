<?php

namespace App\Http\Controllers\Council;

use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\CouncilEntry;
use App\Models\CouncilLoan;
use App\Models\CouncilPayRound;
use App\Models\CouncilLoanPayment;
use App\Models\CouncilTrade;
use App\Models\GroupRecord;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * การเงินของสภา (ประธานและรองประธาน)
 * รายรับอัตโนมัติ (ดึงจากสิ่งที่สภาบันทึกในระบบ): ค่าบริการ (payload.fee.total) ของธุรกรรมที่อนุมัติแล้ว นับตามวันที่อนุมัติ
 *   + ค่าปรับที่สภาบันทึกไว้ในทะเบียนแก๊ง/ครอบครัว (บันทึกชนิด fine จำนวนเงิน WVDL) นับตามวันที่บันทึก
 * รายการที่กรอกเอง (กองทุน / เงินกู้ / โบนัส ฯลฯ) เป็นได้ทั้งรายรับและรายจ่าย
 * วันที่ตีความตามเขตเวลา council.report_timezone (ค่าเริ่มต้น Asia/Bangkok)
 */
class FinanceController extends Controller
{
    private const MESSAGES = [
        'type.required' => 'กรุณาเลือกรายรับหรือรายจ่าย',
        'type.in' => 'ประเภทรายการไม่ถูกต้อง',
        'spent_at.required' => 'กรุณาเลือกวันที่',
        'spent_at.date_format' => 'วันที่ไม่ถูกต้อง',
        'spent_at.before_or_equal' => 'วันที่ต้องไม่เกินวันนี้',
        'amount.required' => 'กรุณากรอกจำนวนเงิน',
        'amount.integer' => 'จำนวนเงินต้องเป็นตัวเลข',
        'amount.min' => 'จำนวนเงินต้องมากกว่า 0',
        'amount.max' => 'จำนวนเงินมากเกินไป',
        'category.required' => 'กรุณาเลือกหมวด',
        'category.in' => 'หมวดไม่ถูกต้อง',
        'title.required' => 'กรุณากรอกรายการ',
        'title.max' => 'ชื่อรายการยาวเกินไป (ไม่เกิน 120 ตัวอักษร)',
        'note.max' => 'หมายเหตุยาวเกินไป (ไม่เกิน 1,000 ตัวอักษร)',
    ];

    // ───────────────────────── สรุป / รายการ / ส่งออก ─────────────────────────

    public function summary(Request $request): JsonResponse
    {
        $r = $this->range($request);
        $incomes = $this->incomes($r);
        $expenses = $this->expenses($r);
        $cats = config('council.finance.expense_categories');
        $manual = fn (string $type) => collect(config("council.finance.{$type}_categories"))->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values();

        $byKind = $incomes->groupBy('category')->map(fn (Collection $g, string $k) => [
            'key' => $k, 'label' => $g->first()['category_label'], 'count' => $g->count(), 'amount' => $g->sum('amount'),
        ])->sortByDesc('amount')->values();
        $byCategory = $expenses->groupBy('category')->map(fn (Collection $g, string $k) => [
            'key' => $k, 'label' => $cats[$k] ?? $k, 'count' => $g->count(), 'amount' => $g->sum('amount'),
        ])->sortByDesc('amount')->values();

        $income = (int) $incomes->sum('amount');
        $expense = (int) $expenses->sum('amount');

        return response()->json(['data' => [
            'from' => $r['from_date'], 'to' => $r['to_date'], 'currency' => config('council.fees.currency'),
            'income' => $income, 'expense' => $expense, 'balance' => $income - $expense,
            'income_count' => $incomes->count(), 'expense_count' => $expenses->count(),
            'by_kind' => $byKind, 'by_category' => $byCategory,
            'expense_categories' => $manual('expense'),
            'income_categories' => $manual('income'),
        ]]);
    }

    public function entries(Request $request): JsonResponse
    {
        $f = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $all = $this->filtered($request);

        $perPage = (int) ($f['per_page'] ?? 20);
        $page = (int) ($f['page'] ?? 1);

        return response()->json([
            'data' => $all->forPage($page, $perPage)->values(),
            'meta' => [
                'total' => $all->count(), 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($all->count() / $perPage)),
                'income' => (int) $all->where('type', 'income')->sum('amount'),
                'expense' => (int) $all->where('type', 'expense')->sum('amount'),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $r = $this->range($request);
        $rows = $this->filtered($request);
        $tz = config('council.report_timezone');
        $name = "finance-{$r['from_date']}_{$r['to_date']}.csv";

        return response()->streamDownload(function () use ($rows, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM ให้ Excel อ่านภาษาไทยถูก
            fputcsv($out, ['วันที่เวลา', 'ประเภท', 'หมวด/ชนิด', 'รายการ', 'อ้างอิง', 'ผู้บันทึก', 'รายรับ (WVDL)', 'รายจ่าย (WVDL)', 'หมายเหตุ']);
            foreach ($rows as $e) {
                fputcsv($out, [
                    Carbon::parse($e['date'])->timezone($tz)->format($e['entry_id'] ? 'Y-m-d' : 'Y-m-d H:i'),
                    $e['type'] === 'income' ? 'รายรับ' : 'รายจ่าย',
                    $e['category_label'], $e['title'], $e['ref'], $e['by'],
                    $e['type'] === 'income' ? $e['amount'] : '',
                    $e['type'] === 'expense' ? $e['amount'] : '',
                    $e['note'],
                ]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** กองกลางของสภา = รายรับ − รายจ่ายสะสมทั้งหมดของหน้าการเงิน (เงินกู้เบิกออกและรับคืนเข้ากองกลางนี้) @return array{income: int, expense: int, balance: int} */
    public function centralBalance(): array
    {
        $tz = config('council.report_timezone');
        $r = ['from' => Carbon::parse('2000-01-01', $tz)->utc(), 'to' => now()->utc(), 'from_date' => '2000-01-01', 'to_date' => now($tz)->toDateString()];
        $in = (int) $this->incomes($r)->sum('amount');
        $out = (int) $this->expenses($r)->sum('amount');

        return ['income' => $in, 'expense' => $out, 'balance' => $in - $out];
    }

    // ───────────────────────── รายการที่กรอกเอง (รายรับ/รายจ่าย) ─────────────────────────

    public function store(Request $request): JsonResponse
    {
        $record = CouncilEntry::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return response()->json(['data' => $this->entryRow($record->load('creator'))], 201);
    }

    public function update(Request $request, CouncilEntry $record): JsonResponse
    {
        $record->update($this->validated($request));

        return response()->json(['data' => $this->entryRow($record->load('creator'))]);
    }

    public function destroy(CouncilEntry $record): JsonResponse
    {
        $record->delete();

        return response()->json(['message' => 'ลบรายการแล้ว']);
    }

    // ───────────────────────── ภายใน ─────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $type = $request->input('type');
        $cats = in_array($type, ['income', 'expense'], true) ? array_keys(config("council.finance.{$type}_categories")) : [];

        return $request->validate([
            'type' => ['required', Rule::in(['income', 'expense'])],
            'spent_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('council.report_timezone'))->toDateString()],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'category' => ['required', Rule::in($cats)],
            'title' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], self::MESSAGES);
    }

    /** ช่วงวันที่ (เวลาไทย) ค่าเริ่มต้น = ตั้งแต่ต้นเดือนถึงวันนี้ @return array{from: Carbon, to: Carbon, from_date: string, to_date: string} */
    private function range(Request $request): array
    {
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], ['from.date_format' => 'วันที่เริ่มไม่ถูกต้อง', 'to.date_format' => 'วันที่สิ้นสุดไม่ถูกต้อง', 'to.after_or_equal' => 'วันที่สิ้นสุดต้องไม่ก่อนวันที่เริ่ม']);
        $tz = config('council.report_timezone');

        $from = isset($f['from']) ? Carbon::parse($f['from'], $tz)->startOfDay() : now($tz)->startOfMonth();
        $to = isset($f['to']) ? Carbon::parse($f['to'], $tz)->endOfDay() : now($tz)->endOfDay();

        return ['from' => $from->copy()->utc(), 'to' => $to->copy()->utc(), 'from_date' => $from->toDateString(), 'to_date' => $to->toDateString()];
    }

    /** รายการรวมรายรับ+รายจ่าย ตามช่วงวันที่และตัวกรอง เรียงใหม่สุดก่อน */
    private function filtered(Request $request): Collection
    {
        $f = $request->validate([
            'type' => ['nullable', Rule::in(['income', 'expense'])],
            'category' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $r = $this->range($request);
        $type = $f['type'] ?? null;

        $rows = collect()
            ->when($type !== 'expense', fn (Collection $c) => $c->merge($this->incomes($r)))
            ->when($type !== 'income', fn (Collection $c) => $c->merge($this->expenses($r)));

        if ($cat = $f['category'] ?? null) {
            $rows = $rows->where('category', $cat);
        }
        if ($q = mb_strtolower(trim($f['q'] ?? ''))) {
            $rows = $rows->filter(fn (array $e) => str_contains(mb_strtolower($e['title'].' '.$e['ref'].' '.$e['by'].' '.$e['note'].' '.$e['category_label']), $q));
        }

        return $rows->sortByDesc(fn (array $e) => $e['date'])->values();
    }

    /** รายรับ: ค่าบริการของธุรกรรมที่อนุมัติแล้วในช่วง (ข้ามธุรกรรมที่ค่าบริการเป็น 0) + รายรับที่กรอกเอง */
    private function incomes(array $r): Collection
    {
        // ->toBase() ก่อนรวม: คอลเลกชันของ Eloquent merge ได้เฉพาะโมเดล แต่ตรงนี้เป็นแถวข้อมูลธรรมดา
        return $this->serviceFees($r)->toBase()->merge($this->fines($r))->merge($this->trades($r, 'sell'))->merge($this->manual($r, 'income')->toBase())->merge($this->loanRepayments($r))->merge($this->loanCancellations($r))->values();
    }

    /** ค่าปรับที่สภาบันทึกไว้ในทะเบียนในช่วง (เฉพาะค่าปรับเป็นเงิน ไม่รวมส่งบำเพ็ญเป็นไม้) ลงวันที่ตามเวลาที่บันทึก */
    private function fines(array $r): Collection
    {
        return GroupRecord::query()->with(['group', 'recorder:id,name,character_name'])
            ->where('kind', 'fine')
            ->where('created_at', '>=', $r['from'])->where('created_at', '<=', $r['to'])
            ->get()->toBase()
            ->filter(fn (GroupRecord $g) => in_array($g->data['penalty_type'] ?? 'fine', ['fine', 'both'], true) && (int) ($g->data['amount'] ?? 0) > 0)
            ->map(fn (GroupRecord $g) => [
                'id' => 'f-'.$g->id, 'type' => 'income', 'date' => $g->created_at->copy()->utc()->toIso8601String(),
                'title' => 'ค่าปรับ · '.($g->group?->name ?? '—'), 'category' => 'fine', 'category_label' => 'ค่าปรับ',
                'amount' => (int) $g->data['amount'],
                'ref' => 'บันทึกทะเบียน #'.$g->id, 'by' => $this->nameOf($g->recorder),
                'note' => trim(($g->data['category_label'] ?? '').(isset($g->data['charge']) ? ' · '.mb_strimwidth((string) $g->data['charge'], 0, 120, '…') : '')),
                'entry_id' => null, 'group_id' => $g->group_id,
            ])->values();
    }

    private function serviceFees(array $r): Collection
    {
        return Transaction::query()
            ->with(['group:id,name,short_name', 'requester:id,name,character_name'])
            ->where('status', TransactionStatus::Approved)
            ->whereRaw('COALESCE(reviewed_at, created_at) >= ?', [$r['from']->format('Y-m-d H:i:s')])
            ->whereRaw('COALESCE(reviewed_at, created_at) <= ?', [$r['to']->format('Y-m-d H:i:s')])
            ->get()
            ->map(function (Transaction $t) {
                $total = (int) ($t->payload['fee']['total'] ?? 0);
                $kind = $t->kind instanceof TransactionKind ? $t->kind : TransactionKind::from($t->kind);

                return [
                    'id' => 't-'.$t->id, 'type' => 'income', 'date' => ($t->reviewed_at ?? $t->created_at)->copy()->utc()->toIso8601String(),
                    'title' => $kind->label().($t->group ? ' · '.$t->group->name : ''),
                    'category' => $kind->value, 'category_label' => $kind->label(), 'amount' => $total,
                    'ref' => $t->number, 'by' => $this->nameOf($t->requester), 'note' => '', 'entry_id' => null,
                ];
            })
            ->filter(fn (array $e) => $e['amount'] > 0)->values();
    }

    /** รายจ่าย: รายการที่กรอกเองชนิดรายจ่ายในช่วงวันที่ */
    private function expenses(array $r): Collection
    {
        return $this->manual($r, 'expense')->toBase()->merge($this->loanDisbursements($r))->merge($this->loanReversals($r))->merge($this->trades($r, 'buy'))->merge($this->bonuses($r))->values();
    }

    /** ซื้อ-ขายกับแก๊ง/ครอบครัวในช่วง: สภาขาย (sell) = รายรับ · สภาซื้อ (buy) = รายจ่าย หมวดซื้อ-ขาย */
    private function trades(array $r, string $direction): Collection
    {
        return CouncilTrade::query()->with(['group', 'creator:id,name,character_name'])
            ->where('direction', $direction)
            ->whereBetween('traded_at', [$r['from_date'], $r['to_date']])
            ->get()->toBase()->map(fn (CouncilTrade $t) => [
                'id' => 'x-'.$t->id, 'type' => $direction === 'sell' ? 'income' : 'expense',
                'date' => Carbon::parse($t->traded_at->format('Y-m-d').' 12:00', config('council.report_timezone'))->utc()->toIso8601String(),
                'title' => ($direction === 'sell' ? 'สภาขาย' : 'สภาซื้อ').' · '.$t->item.' ×'.number_format($t->quantity).' · '.($t->group?->name ?? '—'),
                'category' => 'trade', 'category_label' => 'ซื้อ-ขาย', 'amount' => (int) $t->amount,
                'ref' => 'ซื้อ-ขาย #'.$t->id, 'by' => $this->nameOf($t->creator), 'note' => (string) $t->note, 'entry_id' => null, 'trade_id' => $t->id,
            ]);
    }

    /** โบนัสเวรที่ตัดรอบในช่วง = รายจ่ายหมวดโบนัส (1 แถวต่อรอบ) ลงวันที่ตามเวลาตัดรอบ (รอบที่ย้อนแล้วไม่นับ) */
    private function bonuses(array $r): Collection
    {
        $tz = config('council.report_timezone');

        return CouncilPayRound::query()->with(['items', 'creator:id,name,character_name'])
            ->where('ended_at', '>=', $r['from'])->where('ended_at', '<=', $r['to'])
            ->get()->toBase()->map(function (CouncilPayRound $p) use ($tz) {
                $fmt = fn (Carbon $d) => $d->copy()->timezone($tz)->format('d/m/Y H:i');

                return [
                    'id' => 'b-'.$p->id, 'type' => 'expense', 'date' => $p->ended_at->copy()->utc()->toIso8601String(),
                    'title' => 'โบนัสเวร รอบ '.$fmt($p->started_at).' – '.$fmt($p->ended_at).' · '.$p->items->count().' คน',
                    'category' => 'bonus', 'category_label' => 'โบนัส', 'amount' => (int) $p->items->sum('amount'),
                    'ref' => 'โบนัสเวร #'.$p->id, 'by' => $this->nameOf($p->creator), 'note' => (string) $p->note, 'entry_id' => null, 'bonus_id' => $p->id,
                ];
            })->filter(fn (array $e) => $e['amount'] > 0)->values();
    }

    /** เงินกู้ที่ปล่อยในช่วง = รายจ่ายหมวดเงินกู้ (จัดการที่แท็บเงินกู้ ไม่แก้ในตารางนี้) */
    private function loanDisbursements(array $r): Collection
    {
        return CouncilLoan::query()->with(['group', 'creator:id,name,character_name'])
            ->whereBetween('loaned_at', [$r['from_date'], $r['to_date']])
            ->get()->toBase()->map(fn (CouncilLoan $l) => $this->loanRow('l-'.$l->id, 'expense', 'ปล่อยกู้', $l, $l->loaned_at->format('Y-m-d'), $l->principal, $l->creator, (string) $l->note));
    }

    /** การรับคืนเงินกู้ในช่วง = รายรับหมวดเงินกู้ (เฉพาะรายการต้นฉบับ รายการยกเลิกแสดงเป็นรายจ่ายแยก ยอดจึงหักล้างกันโดยรายการเดิมยังอยู่) */
    private function loanRepayments(array $r): Collection
    {
        return CouncilLoanPayment::query()->with(['loan.group', 'creator:id,name,character_name'])
            ->whereNull('reversal_of')->whereHas('loan')
            ->whereBetween('paid_at', [$r['from_date'], $r['to_date']])
            ->get()->toBase()->map(fn (CouncilLoanPayment $p) => $this->loanRow('p-'.$p->id, 'income', 'รับคืนเงินกู้', $p->loan, $p->paid_at->format('Y-m-d'), $p->amount, $p->creator, (string) $p->note));
    }

    /** รายการยกเลิกรับคืนในช่วง = รายจ่ายหมวดเงินกู้ (หักล้างรายการรับคืนเดิม) */
    private function loanReversals(array $r): Collection
    {
        return CouncilLoanPayment::query()->with(['loan.group', 'creator:id,name,character_name'])
            ->whereNotNull('reversal_of')->whereHas('loan')
            ->whereBetween('paid_at', [$r['from_date'], $r['to_date']])
            ->get()->toBase()->map(fn (CouncilLoanPayment $p) => $this->loanRow('x-'.$p->id, 'expense', 'ยกเลิกรับคืนเงินกู้', $p->loan, $p->paid_at->format('Y-m-d'), $p->amount, $p->creator, (string) $p->reason));
    }

    /** สัญญาที่ยกเลิกในช่วง = รายรับหมวดเงินกู้ (ยอดกู้คืนเข้ากองทุน หักล้างรายจ่ายปล่อยกู้เดิม) */
    private function loanCancellations(array $r): Collection
    {
        $tz = config('council.report_timezone');

        return CouncilLoan::query()->with(['group', 'canceller:id,name,character_name'])
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $r['from'])->where('cancelled_at', '<=', $r['to'])
            ->get()->toBase()->map(fn (CouncilLoan $l) => $this->loanRow('c-'.$l->id, 'income', 'ยกเลิกสัญญาเงินกู้', $l, $l->cancelled_at->copy()->timezone($tz)->format('Y-m-d'), $l->principal, $l->canceller, (string) $l->cancel_reason));
    }

    /** @return array<string, mixed> */
    private function loanRow(string $id, string $type, string $verb, CouncilLoan $loan, string $day, int $amount, ?User $by, string $note): array
    {
        return [
            'id' => $id, 'type' => $type,
            'date' => Carbon::parse($day.' 12:00', config('council.report_timezone'))->utc()->toIso8601String(),
            'title' => $verb.' · '.($loan->group?->name ?? '—'), 'category' => 'loan', 'category_label' => 'เงินกู้',
            'amount' => $amount, 'ref' => 'เงินกู้ #'.$loan->id, 'by' => $this->nameOf($by), 'note' => $note, 'entry_id' => null, 'loan_id' => $loan->id,
        ];
    }

    /** รายการที่กรอกเองตามชนิดในช่วงวันที่ (วันที่เป็นวันตามเวลาไทย) */
    private function manual(array $r, string $type): Collection
    {
        return CouncilEntry::query()->with('creator:id,name,character_name')
            ->where('type', $type)
            ->whereBetween('spent_at', [$r['from_date'], $r['to_date']])
            ->get()->map(fn (CouncilEntry $e) => $this->entryRow($e));
    }

    /** @return array<string, mixed> */
    private function entryRow(CouncilEntry $e): array
    {
        $cats = config("council.finance.{$e->type}_categories");

        return [
            'id' => 'e-'.$e->id, 'type' => $e->type,
            // วันที่เก็บเป็นวัน ไม่มีเวลา: ตีเป็นเที่ยงวันตามเวลาไทยให้เรียงลำดับและไม่เลื่อนวัน
            'date' => Carbon::parse($e->spent_at->format('Y-m-d').' 12:00', config('council.report_timezone'))->utc()->toIso8601String(),
            'title' => $e->title, 'category' => $e->category, 'category_label' => $cats[$e->category] ?? $e->category,
            'amount' => (int) $e->amount, 'ref' => '', 'by' => $this->nameOf($e->creator), 'note' => (string) $e->note, 'entry_id' => $e->id,
            'spent_at' => $e->spent_at->format('Y-m-d'),
        ];
    }

    private function nameOf(?User $u): string
    {
        return $u ? ($u->character_name ?: $u->name) : '';
    }
}
