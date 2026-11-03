<?php

namespace App\Http\Controllers\Council;

use App\Enums\RuleSection;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ประกาศปรับ: รายการ "บันทึกค่าปรับ" ของทะเบียน (GroupRecord ชนิด fine) ดูย้อนหลังรายวัน เพื่อคัดลอกเป็นประกาศ
 * การบันทึกค่าปรับทำผ่าน POST council/groups/{group}/records (kind = fine) เหมือนเดิม ไม่มีตารางแยก
 */
class FineController extends Controller
{
    /** รายการบันทึกค่าปรับ/โทษของวันที่เลือก (ใหม่→เก่า) ตัวกรองวันที่ใช้ร่วมกับหน้าประกาศโทษ */
    private function dayQuery(Request $request, array &$f)
    {
        $f = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],   // วันที่ (เวลาไทย) ที่ต้องการดู
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ], ['date.date_format' => 'วันที่ไม่ถูกต้อง']);

        $query = GroupRecord::query()->where('kind', 'fine')
            ->with(['group', 'recorder:id,name,character_name'])
            ->orderByDesc('created_at')->orderByDesc('id');

        if (! empty($f['date'])) {
            $start = Carbon::createFromFormat('Y-m-d', $f['date'], config('council.report_timezone'))->startOfDay();
            $query->whereBetween('created_at', [
                $start->copy()->setTimezone(config('app.timezone')),
                $start->copy()->endOfDay()->setTimezone(config('app.timezone')),
            ]);
        }

        return $query;
    }

    /** ประกาศโทษ: เฉพาะรายการที่มีโทษบำเพ็ญ (ไม้) — ค่าปรับอย่างเดียวไม่แสดง */
    /**
     * โทษบำเพ็ญที่บันทึกไว้: รายวัน (ค่าเริ่มต้น) หรือ scope=pending = ที่ยังไม่รับโทษทุกวัน (ไว้ติดตามค้างรับโทษ)
     */
    public function penances(Request $request): JsonResponse
    {
        if ($request->query('scope') === 'pending') {
            $rows = GroupRecord::query()->with(['group', 'recorder'])->where('kind', 'fine')->orderByDesc('id')->limit(500)->get()
                ->filter(fn (GroupRecord $r) => (int) ($r->data['penance_units'] ?? 0) > 0 && empty($r->data['served_at']))->values();

            return response()->json(['data' => $rows->map(fn (GroupRecord $r) => $this->row($r))->values(), 'meta' => ['total' => $rows->count()]]);
        }

        $f = [];
        $rows = $this->dayQuery($request, $f)->limit(500)->get()
            ->filter(fn (GroupRecord $r) => (int) ($r->data['penance_units'] ?? 0) > 0)->values();

        return response()->json([
            'data' => $rows->map(fn (GroupRecord $r) => $this->row($r))->values(),
            'meta' => ['total' => $rows->count()],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $f = [];
        $query = $this->dayQuery($request, $f);

        $page = $query->paginate($f['per_page'] ?? 200);

        return response()->json([
            'data' => $page->getCollection()->map(fn (GroupRecord $r) => $this->row($r))->values(),
            'meta' => ['total' => $page->total()] + app(GroupRecordController::class)->fineMeta() + [
                'categories' => collect(RuleSection::cases())->map(fn (RuleSection $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    /** ทำเครื่องหมายว่ากลุ่มรับโทษบำเพ็ญเรียบร้อยแล้ว (หรือยกเลิกเครื่องหมาย) */
    public function serve(Request $request, GroupRecord $record): JsonResponse
    {
        $this->assertPenance($record);
        $user = $request->user();
        $data = $record->data;
        $data['served_at'] = now()->toIso8601String();
        $data['served_by'] = $user->id;
        $data['served_by_name'] = $user->character_name ?: $user->name;
        $record->update(['data' => $data]);

        activity('groups')->causedBy($user)->performedOn($record->group)->withProperties(['record_id' => $record->id, 'penance_units' => $data['penance_units']])
            ->log('บันทึกรับโทษบำเพ็ญเรียบร้อย '.$record->group?->name);

        return response()->json(['data' => $this->row($record->fresh(['group', 'recorder']))]);
    }

    public function unserve(Request $request, GroupRecord $record): JsonResponse
    {
        $this->assertPenance($record);
        $data = $record->data;
        unset($data['served_at'], $data['served_by'], $data['served_by_name']);
        $record->update(['data' => $data]);

        activity('groups')->causedBy($request->user())->performedOn($record->group)->withProperties(['record_id' => $record->id])
            ->log('ยกเลิกบันทึกรับโทษบำเพ็ญ '.$record->group?->name);

        return response()->json(['data' => $this->row($record->fresh(['group', 'recorder']))]);
    }

    private function assertPenance(GroupRecord $record): void
    {
        abort_unless($record->kind === 'fine' && (int) ($record->data['penance_units'] ?? 0) > 0, 404, 'ไม่พบรายการโทษบำเพ็ญ');
    }

    private function row(GroupRecord $r): array
    {
        $d = $r->data ?? [];

        return [
            'id' => $r->id,
            'group' => $this->group($r->group),
            'penalty_type' => $d['penalty_type'] ?? 'fine',
            'amount' => $d['amount'] ?? null,
            'penance_units' => $d['penance_units'] ?? null,
            'category_label' => $d['category_label'] ?? '',
            'charge' => $d['charge'] ?? '',
            'note' => (string) $r->note,
            'served' => ! empty($d['served_at']),
            'served_at' => $d['served_at'] ?? null,
            'served_by' => $d['served_by_name'] ?? null,
            'by' => $r->recorder ? ($r->recorder->character_name ?: $r->recorder->name) : '',
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function group(?Group $g): ?array
    {
        if (! $g) {
            return null;
        }
        $kind = $g->type->value === 'family' ? 'family' : ($g->is_lady ? 'lady' : 'gang');

        return ['id' => $g->id, 'name' => $g->name, 'short_name' => $g->short_name, 'kind' => $kind, 'kind_label' => ['gang' => 'แก๊ง', 'lady' => 'แก๊งหญิง', 'family' => 'ครอบครัว'][$kind], 'deleted' => $g->trashed()];
    }
}
