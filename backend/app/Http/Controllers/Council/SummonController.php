<?php

namespace App\Http\Controllers\Council;

use App\Http\Controllers\Controller;
use App\Models\CouncilSummon;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** ประกาศเรียก: บันทึกการเรียกแก๊ง/ครอบครัวเข้าพบ (สภา Member ขึ้นไป · บันทึกแล้วเก็บต่อท้าย ไม่มีแก้/ลบ) */
class SummonController extends Controller
{
    private const MESSAGES = [
        'summoned_group_id.required' => 'กรุณาเลือกผู้ถูกเรียก',
        'summoned_group_id.exists' => 'ไม่พบผู้ถูกเรียกในทะเบียน',
        'summoner_group_id.required' => 'กรุณาเลือกผู้แจ้งเรียก',
        'summoner_group_id.exists' => 'ไม่พบผู้แจ้งเรียกในทะเบียน',
        'summoner_group_id.different' => 'ผู้ถูกเรียกและผู้แจ้งเรียกต้องเป็นคนละกลุ่ม',
        'reason.required' => 'กรุณาระบุเหตุผล',
        'reason.max' => 'เหตุผลยาวเกินไป (ไม่เกิน 1,000 ตัวอักษร)',
        'place.max' => 'สถานที่ยาวเกินไป (ไม่เกิน 120 ตัวอักษร)',
        'dimension.required' => 'กรุณาเลือกมิติ',
        'dimension.between' => 'มิติต้องเป็นมิติหลักหรือ 1 ถึง '.CouncilSummon::MAX_DIMENSION,
    ];

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'date' => ['nullable', 'date_format:Y-m-d'],   // วันที่ (เวลาไทย) ที่ต้องการดูย้อนหลัง
        ], ['date.date_format' => 'วันที่ไม่ถูกต้อง']);

        $query = CouncilSummon::query()
            ->with(['summoned', 'summoner', 'creator:id,name,character_name'])
            ->orderByDesc('created_at')->orderByDesc('id');

        if (! empty($f['date'])) {
            $start = Carbon::createFromFormat('Y-m-d', $f['date'], config('council.report_timezone'))->startOfDay();
            $query->whereBetween('created_at', [
                $start->copy()->setTimezone(config('app.timezone')),
                $start->copy()->endOfDay()->setTimezone(config('app.timezone')),
            ]);
        }

        $page = $query->paginate($f['per_page'] ?? 6);

        return response()->json([
            'data' => $page->getCollection()->map(fn (CouncilSummon $s) => $this->row($s))->values(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage()],
        ]);
    }

    /** ประกาศเรียกที่เกี่ยวกับแก๊ง/ครอบครัวนี้ (ทั้งที่ถูกเรียกและที่เป็นผู้แจ้งเรียก) ใหม่→เก่า · แสดงในไทม์ไลน์ของทะเบียน */
    public function forGroup(Group $group): JsonResponse
    {
        $rows = CouncilSummon::query()
            ->where(fn ($q) => $q->where('summoned_group_id', $group->id)->orWhere('summoner_group_id', $group->id))
            ->with(['summoned', 'summoner', 'creator:id,name,character_name'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (CouncilSummon $s) => $this->row($s) + ['role' => $s->summoned_group_id === $group->id ? 'summoned' : 'summoner'])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $byCouncil = $request->boolean('by_council');   // ผู้แจ้งเรียกเป็นสภาเอง (ไม่ต้องเลือกกลุ่ม)

        $d = $request->validate([
            'by_council' => ['nullable', 'boolean'],
            'summoned_group_id' => ['required', 'integer', Rule::exists('groups', 'id')->whereNull('deleted_at')],
            'summoner_group_id' => $byCouncil ? ['nullable'] : ['required', 'integer', 'different:summoned_group_id', Rule::exists('groups', 'id')->whereNull('deleted_at')],
            'reason' => ['required', 'string', 'max:1000'],
            'place' => ['nullable', 'string', 'max:120'],
            'dimension' => ['required', 'integer', 'between:'.CouncilSummon::MAIN_DIMENSION.','.CouncilSummon::MAX_DIMENSION],
        ], self::MESSAGES);

        $d['by_council'] = $byCouncil;
        $d['summoner_group_id'] = $byCouncil ? null : $d['summoner_group_id'];
        $d['place'] = trim((string) ($d['place'] ?? '')) ?: CouncilSummon::DEFAULT_PLACE;

        $summon = CouncilSummon::create($d + ['number' => $this->newNumber(), 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->row($summon->load(['summoned', 'summoner', 'creator:id,name,character_name']))], 201);
    }

    /** เลขเอกสาร WC-CALL-วันที่(เวลาไทย)-รหัสสุ่ม 6 ตัว ไม่ซ้ำกัน */
    private function newNumber(): string
    {
        do {
            $n = 'WC-CALL-'.now(config('council.report_timezone'))->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (CouncilSummon::withTrashed()->where('number', $n)->exists());

        return $n;
    }

    /** @return array<string, mixed> */
    private function row(CouncilSummon $s): array
    {
        return [
            'id' => $s->id, 'number' => $s->number,
            'summoned' => $this->group($s->summoned), 'summoner' => $s->by_council ? $this->council() : $this->group($s->summoner),
            'reason' => $s->reason, 'place' => $s->place, 'dimension' => $s->dimension,
            'by' => $this->nameOf($s->creator), 'created_at' => $s->created_at?->toIso8601String(),
        ];
    }

    /** ผู้แจ้งเรียกที่เป็นสภา: ไม่มีทะเบียนกลุ่ม (id ว่าง) ใช้ในหน้าแสดงผลแทนกลุ่ม */
    private function council(): array
    {
        return ['id' => null, 'name' => 'สภา', 'short_name' => null, 'kind' => 'council', 'kind_label' => 'สภา', 'deleted' => false];
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

    private function nameOf(?User $u): string
    {
        return $u ? ($u->character_name ?: $u->name) : '';
    }
}
