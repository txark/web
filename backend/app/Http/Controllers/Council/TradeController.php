<?php

namespace App\Http\Controllers\Council;

use App\Http\Controllers\Controller;
use App\Models\CouncilTrade;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ซื้อ-ขายระหว่างสภากับแก๊ง/ครอบครัวที่ขึ้นทะเบียน (ประธานและรองประธาน)
 * สภาขายให้กลุ่ม = รายรับ · สภาซื้อจากกลุ่ม = รายจ่าย ของหน้าการเงิน (FinanceController ดึงมารวมให้เอง)
 */
class TradeController extends Controller
{
    private const MAX_AMOUNT = 999999999999;

    private const MESSAGES = [
        'group_id.required' => 'กรุณาเลือกแก๊ง/ครอบครัว',
        'group_id.exists' => 'ไม่พบแก๊ง/ครอบครัวที่เลือก',
        'direction.required' => 'กรุณาเลือกสภาขาย หรือสภาซื้อ',
        'direction.in' => 'ประเภทรายการไม่ถูกต้อง',
        'item.required' => 'กรุณากรอกสินค้า/บริการ',
        'item.max' => 'ชื่อสินค้า/บริการยาวเกินไป (ไม่เกิน 120 ตัวอักษร)',
        'quantity.required' => 'กรุณากรอกจำนวน',
        'quantity.integer' => 'จำนวนต้องเป็นตัวเลข',
        'quantity.min' => 'จำนวนต้องมากกว่า 0',
        'quantity.max' => 'จำนวนมากเกินไป',
        'unit_price.required' => 'กรุณากรอกราคาต่อหน่วย',
        'unit_price.integer' => 'ราคาต้องเป็นตัวเลข',
        'unit_price.min' => 'ราคาต้องมากกว่า 0',
        'unit_price.max' => 'ราคามากเกินไป',
        'traded_at.required' => 'กรุณาเลือกวันที่',
        'traded_at.date_format' => 'วันที่ไม่ถูกต้อง',
        'traded_at.before_or_equal' => 'วันที่ต้องไม่เกินวันนี้',
        'note.max' => 'หมายเหตุยาวเกินไป (ไม่เกิน 1,000 ตัวอักษร)',
    ];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'direction' => ['nullable', Rule::in(['sell', 'buy'])],
            'q' => ['nullable', 'string', 'max:100'],
            'group_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = trim($f['q'] ?? '');
        $all = CouncilTrade::query()->with(['group', 'creator:id,name,character_name'])
            ->when($f['group_id'] ?? null, fn ($query, $id) => $query->where('group_id', $id))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->whereLike('item', "%{$q}%")->orWhereLike('note', "%{$q}%")
                ->orWhereHas('group', fn ($g) => $g->withTrashed()->where(fn ($n) => $n->whereLike('name', "%{$q}%")->orWhereLike('short_name', "%{$q}%")))))
            ->orderByDesc('traded_at')->orderByDesc('id')
            ->get();

        // สรุปคิดจากทั้งสองทิศทางของผลค้นหา (ไม่ขึ้นกับตัวกรองประเภท) ให้การ์ดคงที่เมื่อสลับแท็บ
        $sold = (int) $all->where('direction', 'sell')->sum('amount');
        $bought = (int) $all->where('direction', 'buy')->sum('amount');
        $summary = [
            'sold' => $sold, 'bought' => $bought, 'net' => $sold - $bought,
            'sell_count' => $all->where('direction', 'sell')->count(), 'buy_count' => $all->where('direction', 'buy')->count(),
            'currency' => config('council.fees.currency'),
        ];

        $rows = ($d = $f['direction'] ?? null) ? $all->where('direction', $d)->values() : $all;
        $perPage = (int) ($f['per_page'] ?? 20);
        $page = (int) ($f['page'] ?? 1);

        return response()->json([
            'data' => $rows->forPage($page, $perPage)->map(fn (CouncilTrade $t) => $this->row($t))->values(),
            'meta' => ['total' => $rows->count(), 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($rows->count() / $perPage)), 'summary' => $summary],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->rules(true), self::MESSAGES);
        $trade = CouncilTrade::create($this->withAmount($d) + ['created_by' => $request->user()->id]);

        return response()->json(['data' => $this->row($trade->load(['group', 'creator:id,name,character_name']))], 201);
    }

    /** แก้รายละเอียด (เปลี่ยนกลุ่มไม่ได้ ให้ลบแล้วสร้างใหม่) */
    public function update(Request $request, CouncilTrade $trade): JsonResponse
    {
        $d = $request->validate($this->rules(false), self::MESSAGES);
        $trade->update($this->withAmount($d));

        return response()->json(['data' => $this->row($trade->load(['group', 'creator:id,name,character_name']))]);
    }

    public function destroy(CouncilTrade $trade): JsonResponse
    {
        $trade->delete();

        return response()->json(['message' => 'ลบรายการซื้อ-ขายแล้ว']);
    }

    // ───────────────────────── ภายใน ─────────────────────────

    /** @return array<string, mixed> */
    private function rules(bool $withGroup): array
    {
        return ($withGroup ? ['group_id' => ['required', 'integer', Rule::exists('groups', 'id')->whereNull('deleted_at')]] : []) + [
            'direction' => ['required', Rule::in(['sell', 'buy'])],
            'item' => ['required', 'string', 'max:120'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit_price' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'traded_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('council.report_timezone'))->toDateString()],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** ยอดรวม = จำนวน x ราคาต่อหน่วย คำนวณฝั่งเซิร์ฟเวอร์เสมอ ไม่เชื่อค่าที่ส่งมา
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function withAmount(array $d): array
    {
        $amount = (int) $d['quantity'] * (int) $d['unit_price'];
        if ($amount > self::MAX_AMOUNT) {
            throw ValidationException::withMessages(['unit_price' => 'ยอดรวมมากเกินไป']);
        }

        return $d + ['amount' => $amount];
    }

    /** @return array<string, mixed> */
    private function row(CouncilTrade $t): array
    {
        $g = $t->group;

        return [
            'id' => $t->id, 'number' => $t->number, 'direction' => $t->direction, 'direction_label' => $t->direction === 'sell' ? 'สภาขาย' : 'สภาซื้อ',
            'group' => $g ? ['id' => $g->id, 'name' => $g->name, 'short_name' => $g->short_name, 'type' => $g->type->value, 'is_lady' => (bool) $g->is_lady, 'deleted' => $g->trashed()] : null,
            'item' => $t->item, 'quantity' => $t->quantity, 'unit_price' => $t->unit_price, 'amount' => $t->amount,
            'traded_at' => $t->traded_at->format('Y-m-d'), 'note' => (string) $t->note, 'by' => $this->nameOf($t->creator),
        ];
    }

    private function nameOf(?User $u): string
    {
        return $u ? ($u->character_name ?: $u->name) : '';
    }
}
