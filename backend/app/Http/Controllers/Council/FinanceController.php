<?php

namespace App\Http\Controllers\Council;

use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\CouncilExpense;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * รายรับ-รายจ่าย / ค่าบริการของสภา (รองประธานขึ้นไป)
 * รายรับ = ค่าบริการ (payload.fee.total) ของธุรกรรมที่อนุมัติแล้ว นับตามวันที่อนุมัติ · รายจ่าย = กรอกเอง
 * วันที่ตีความตามเขตเวลา council.report_timezone (ค่าเริ่มต้น Asia/Bangkok)
 */
class FinanceController extends Controller
{
    private const MESSAGES = [
        'spent_at.required' => 'กรุณาเลือกวันที่จ่าย',
        'spent_at.date_format' => 'วันที่ไม่ถูกต้อง',
        'spent_at.before_or_equal' => 'วันที่จ่ายต้องไม่เกินวันนี้',
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
            'expense_categories' => collect($cats)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
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
                    Carbon::parse($e['date'])->timezone($tz)->format($e['type'] === 'expense' ? 'Y-m-d' : 'Y-m-d H:i'),
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

    // ───────────────────────── รายจ่าย (กรอกเอง) ─────────────────────────

    public function storeExpense(Request $request): JsonResponse
    {
        $expense = CouncilExpense::create($this->validated($request) + ['created_by' => $request->user()->id]);

        return response()->json(['data' => $this->expenseRow($expense->load('creator'))], 201);
    }

    public function updateExpense(Request $request, CouncilExpense $expense): JsonResponse
    {
        $expense->update($this->validated($request));

        return response()->json(['data' => $this->expenseRow($expense->load('creator'))]);
    }

    public function destroyExpense(CouncilExpense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(['message' => 'ลบรายการแล้ว']);
    }

    // ───────────────────────── ภายใน ─────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'spent_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('council.report_timezone'))->toDateString()],
            'amount' => ['required', 'integer', 'min:1', 'max:999999999999'],
            'category' => ['required', Rule::in(array_keys(config('council.finance.expense_categories')))],
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

    /** รายรับ: ค่าบริการของธุรกรรมที่อนุมัติแล้วในช่วง (ข้ามธุรกรรมที่ค่าบริการเป็น 0) */
    private function incomes(array $r): Collection
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
                    'ref' => $t->number, 'by' => $this->nameOf($t->requester), 'note' => '', 'expense_id' => null,
                ];
            })
            ->filter(fn (array $e) => $e['amount'] > 0)->values();
    }

    /** รายจ่ายในช่วงวันที่ (วันที่จ่ายเป็นวันตามเวลาไทย) */
    private function expenses(array $r): Collection
    {
        return CouncilExpense::query()->with('creator:id,name,character_name')
            ->whereBetween('spent_at', [$r['from_date'], $r['to_date']])
            ->get()->map(fn (CouncilExpense $e) => $this->expenseRow($e));
    }

    /** @return array<string, mixed> */
    private function expenseRow(CouncilExpense $e): array
    {
        $cats = config('council.finance.expense_categories');

        return [
            'id' => 'e-'.$e->id, 'type' => 'expense',
            // วันที่จ่ายเก็บเป็นวัน ไม่มีเวลา: ตีเป็นเที่ยงวันตามเวลาไทยให้เรียงลำดับและไม่เลื่อนวัน
            'date' => Carbon::parse($e->spent_at->format('Y-m-d').' 12:00', config('council.report_timezone'))->utc()->toIso8601String(),
            'title' => $e->title, 'category' => $e->category, 'category_label' => $cats[$e->category] ?? $e->category,
            'amount' => (int) $e->amount, 'ref' => '', 'by' => $this->nameOf($e->creator), 'note' => (string) $e->note, 'expense_id' => $e->id,
            'spent_at' => $e->spent_at->format('Y-m-d'),
        ];
    }

    private function nameOf(?User $u): string
    {
        return $u ? ($u->character_name ?: $u->name) : '';
    }
}
