<?php

namespace App\Http\Controllers\Council;

use App\Enums\GroupStatus;
use App\Enums\RuleSection;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupRecord;
use App\Models\Rule as RuleModel;
use App\Support\PenaltyAmount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * บันทึกของทะเบียน: รายงานตัว / ค่าปรับ / ปรับ Protect
 * เป็นรายการต่อท้าย (แก้หรือลบไม่ได้) และการปรับ Protect บันทึกค่าก่อน-หลังลง activity log
 * หมายเหตุ: ค่าปรับที่นี่เป็นแค่บันทึกประวัติ การรับเงินจริงต้องไปบันทึกกองกลางแยกต่างหาก
 */
class GroupRecordController extends Controller
{
    public function index(Group $group): JsonResponse
    {
        $records = $group->records()->with('recorder')->orderByDesc('id')->limit(100)->get();

        return response()->json([
            'data' => $records->map(fn (GroupRecord $r) => $this->present($r))->values(),
            'meta' => [
                'fine_sections' => $this->fineSections(),
                'fine_rules' => $this->fineRules(),
                'fine_categories' => collect(RuleSection::cases())->map(fn (RuleSection $s) => ['value' => $s->value, 'label' => $s->label()])->values(),
            ],
        ]);
    }

    public function store(Request $request, Group $group): JsonResponse
    {
        $kind = $request->validate(['kind' => ['required', Rule::in(['report', 'fine', 'protect', 'outfit'])]], ['kind.required' => 'กรุณาเลือกชนิดบันทึก', 'kind.in' => 'ชนิดบันทึกไม่ถูกต้อง'])['kind'];

        $record = match ($kind) {
            'report' => $this->report($request, $group),
            'fine' => $this->fine($request, $group),
            'protect' => $this->protect($request, $group),
            'outfit' => $this->outfit($request, $group),
        };

        return response()->json(['data' => $this->present($record->load('recorder'))], 201);
    }

    /**
     * รายงานตัว = ปุ่มสถานะของทะเบียน: "ปกติ" (มารายงานตัวแล้ว) หรือ "ขาดการเคลื่อนไหว"
     * เปลี่ยนสถานะของกลุ่มพร้อมเก็บประวัติสถานะ; กลุ่มที่เป็น Blacklist/คว่ำบาตรเปลี่ยนด้วยปุ่มนี้ไม่ได้ (ต้องไปจัดการสถานะโดยตรง)
     * ลงบันทึกชนิด report เมื่อเลือกปกติ (นับเป็นรายงานตัวล่าสุด) และชนิด inactive เมื่อขาดการเคลื่อนไหว
     */
    private function report(Request $request, Group $group): GroupRecord
    {
        $d = $request->validate([
            'status' => ['required', Rule::in([GroupStatus::Normal->value, GroupStatus::Inactive->value])],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['status.required' => 'กรุณาเลือกสถานะ', 'status.in' => 'สถานะไม่ถูกต้อง']);

        $to = GroupStatus::from($d['status']);
        $from = $group->status;

        if (! in_array($from, [GroupStatus::Normal, GroupStatus::Inactive], true)) {
            abort(422, "ทะเบียนนี้เป็นสถานะ {$from->label()} จึงเปลี่ยนด้วยการรายงานตัวไม่ได้ ให้จัดการสถานะที่หน้าจัดการทะเบียน");
        }

        $note = isset($d['note']) && trim($d['note']) !== '' ? trim($d['note']) : null;

        if ($to !== $from) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($group, $to, $note, $request) {
                $group->statusHistory()->whereNull('ended_at')->update(['ended_at' => now()]);
                $group->statusHistory()->create([
                    'status' => $to,
                    'reason' => $note ?? 'จากการบันทึกรายงานตัว',
                    'changed_by' => $request->user()->id,
                    'started_at' => now(),
                ]);
                $group->update([
                    'status' => $to,
                    'status_reason' => $to === GroupStatus::Normal ? null : ($note ?? 'ขาดการเคลื่อนไหว'),
                    'status_changed_at' => now(),
                ]);
            });
        }

        return $this->create($request, $group, $to === GroupStatus::Normal ? 'report' : 'inactive', ['status' => $to->value, 'previous_status' => $from->value], $note);
    }

    /** ข้อกฎที่เป็นเหตุปรับได้: เผยแพร่แล้วและมีบทลงโทษ */
    private function fineableRules()
    {
        return RuleModel::query()->where('is_published', true)->where('kind', 'rule')->whereNotNull('penalty')->where('penalty', '!=', '')
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return list<array{value: string, label: string, count: int}> หมวดที่มีข้อกฎให้เลือก เรียงตามลำดับหมวด */
    private function fineSections(): array
    {
        $counts = $this->fineableRules()->groupBy(fn (RuleModel $r) => $r->section->value)->map->count();

        return collect(RuleSection::cases())
            ->filter(fn (RuleSection $s) => $counts->has($s->value))
            ->map(fn (RuleSection $s) => ['value' => $s->value, 'label' => $s->label(), 'count' => $counts[$s->value]])
            ->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function fineRules(): array
    {
        return $this->fineableRules()->map(function (RuleModel $r) {
            $p = PenaltyAmount::parse($r->penalty);

            return [
                'id' => $r->id,
                'section' => $r->section->value,
                'text' => trim((string) $r->body),
                'penalty' => trim((string) $r->penalty),
                'amount' => $p['suggested'],
                'amount_min' => $p['min'],
                'amount_max' => $p['max'],
                'penance' => $p['penance']['suggested'], // ไม้ที่ต้องบำเพ็ญ (เมื่อกฎระบุจำนวนเดียว)
                'penance_min' => $p['penance']['min'],
                'penance_max' => $p['penance']['max'],
            ];
        })->values()->all();
    }

    /**
     * บันทึกค่าปรับ: เลือกข้อกฎ (rule_id) แล้วดึงหมวดและข้อหาจากข้อกฎนั้น หรือเลือก "อื่นๆ" แล้วระบุหมวด+ข้อหาเอง
     * เก็บสำเนาข้อความกฎและบทลงโทษ ณ ตอนบันทึกไว้ (แก้กฎทีหลังไม่กระทบประวัติ)
     */
    private function fine(Request $request, Group $group): GroupRecord
    {
        $d = $request->validate([
            'rule_id' => ['nullable', 'integer', Rule::exists('rules', 'id')->where('is_published', true)],
            'category' => ['required_without:rule_id', 'nullable', Rule::enum(RuleSection::class)],
            'penalty_type' => ['nullable', Rule::in(['fine', 'penance'])], // fine = ค่าปรับ (WVDL) | penance = ส่งบำเพ็ญ (ไม้)
            'amount' => ['required_unless:penalty_type,penance', 'nullable', 'integer', 'min:1', 'max:1000000000'],
            'penance_units' => ['required_if:penalty_type,penance', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'charge' => ['required_without:rule_id', 'nullable', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'rule_id.exists' => 'ไม่พบข้อกฎที่เลือก',
            'category.required_without' => 'กรุณาเลือกหมวดหมู่',
            'category.enum' => 'หมวดหมู่ไม่ถูกต้อง',
            'amount.required' => 'กรุณากรอกจำนวนเงินในเกม',
            'amount.integer' => 'จำนวนเงินต้องเป็นตัวเลข',
            'amount.min' => 'จำนวนเงินต้องมากกว่า 0',
            'penance_units.required_if' => 'กรุณากรอกจำนวนไม้ที่ต้องบำเพ็ญ',
            'penance_units.integer' => 'จำนวนไม้ต้องเป็นตัวเลข',
            'penance_units.min' => 'จำนวนไม้ต้องมากกว่า 0',
            'charge.required_without' => 'กรุณากรอกข้อหา',
        ]);

        $rule = isset($d['rule_id']) ? RuleModel::find($d['rule_id']) : null;
        $section = $rule ? $rule->section : RuleSection::from($d['category']);
        $penance = ($d['penalty_type'] ?? 'fine') === 'penance';

        return $this->create($request, $group, 'fine', [
            'penalty_type' => $penance ? 'penance' : 'fine',
            'category' => $section->value,
            'category_label' => $section->label(),
            'amount' => $penance ? null : (int) $d['amount'],
            'penance_units' => $penance ? (int) $d['penance_units'] : null,
            'charge' => $rule ? trim((string) $rule->body) : trim($d['charge']),
            'rule_id' => $rule?->id,
            'rule_penalty' => $rule ? trim((string) $rule->penalty) : null,
        ], $d['note'] ?? null);
    }

    /** จำนวนชุดที่ลงทะเบียน: ช่องว่าง/null = ยังไม่ลงทะเบียน; ครอบครัวลงได้เฉพาะ ฮูด เสื้อยืด เสื้อโปโล accessories */
    private function outfit(Request $request, Group $group): GroupRecord
    {
        $all = ['suit', 'hoodie', 'tshirt', 'polo', 'armor', 'gun', 'mod1', 'mod2', 'accessories'];
        $allowed = $group->type === \App\Enums\GroupType::Family ? ['hoodie', 'tshirt', 'polo', 'accessories'] : $all;

        $rules = ['counts' => ['required', 'array']];
        foreach ($all as $key) {
            $rules["counts.{$key}"] = in_array($key, $allowed, true) ? ['nullable', 'integer', 'min:0', 'max:9999'] : ['prohibited'];
        }
        $d = $request->validate($rules, [
            'counts.required' => 'กรุณากรอกจำนวนชุด',
            'counts.*.integer' => 'จำนวนต้องเป็นตัวเลข',
            'counts.*.min' => 'จำนวนต้องไม่ติดลบ',
            'counts.*.max' => 'จำนวนมากเกินไป',
            'counts.*.prohibited' => 'ครอบครัวลงชุดชนิดนี้ไม่ได้',
        ]);

        $after = [];
        foreach ($allowed as $key) {
            if (isset($d['counts'][$key]) && $d['counts'][$key] !== '') {
                $after[$key] = (int) $d['counts'][$key];
            }
        }
        if ($errors = $group->outfitLimitErrors($after)) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }

        $before = $group->outfit_counts ?? [];
        $group->update(['outfit_counts' => $after]);

        activity('groups')->causedBy($request->user())->performedOn($group)
            ->withProperties(['before' => $before, 'after' => $after])
            ->log("แก้ไขจำนวนชุด {$group->name}");

        return $this->create($request, $group, 'outfit', ['before' => $before, 'after' => $after], null);
    }

    /** ตั้ง/เลื่อน/ยกเลิก Protect: protect_until ว่าง = ยกเลิก */
    private function protect(Request $request, Group $group): GroupRecord
    {
        $d = $request->validate([
            'protect_until' => ['nullable', 'date'],
        ], ['protect_until.date' => 'วันเวลาไม่ถูกต้อง']);

        $until = isset($d['protect_until']) ? \Illuminate\Support\Carbon::parse($d['protect_until']) : null;
        if ($until && $until->lte($group->protect_from ?? now())) {
            abort(422, 'วันสิ้นสุด Protect ต้องหลังวันเริ่มต้นหรือเวลาปัจจุบัน');
        }

        $before = ['protect_from' => $group->protect_from?->toIso8601String(), 'protect_until' => $group->protect_until?->toIso8601String()];

        $group->update([
            'protect_until' => $until,
            'protect_requested' => $until !== null,
            'protect_from' => $until ? ($group->protect_from ?? now()) : $group->protect_from,
        ]);
        $group->refresh();

        $after = ['protect_from' => $group->protect_from?->toIso8601String(), 'protect_until' => $group->protect_until?->toIso8601String()];

        activity('groups')->causedBy($request->user())->performedOn($group)
            ->withProperties(['before' => $before, 'after' => $after])
            ->log($until ? "ปรับ Protect {$group->name} สิ้นสุด {$until->toIso8601String()}" : "ยกเลิก Protect {$group->name}");

        return $this->create($request, $group, 'protect', ['before' => $before, 'after' => $after], null);
    }

    private function create(Request $request, Group $group, string $kind, array $data, ?string $note): GroupRecord
    {
        $record = $group->records()->create([
            'kind' => $kind,
            'data' => $data,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'recorded_by' => $request->user()->id,
        ]);

        // protect/outfit เขียนบันทึกกิจกรรมของตัวเองแล้ว (พร้อมก่อน-หลัง) ไม่เขียนซ้ำที่นี่
        if (! in_array($kind, ['protect', 'outfit'], true)) {
            activity('groups')->causedBy($request->user())->performedOn($group)
                ->withProperties($data + ['note' => $record->note])
                ->log(match ($kind) { 'report' => 'บันทึกรายงานตัว ', 'inactive' => 'บันทึกขาดการเคลื่อนไหว ', default => (($data['penalty_type'] ?? 'fine') === 'penance' ? 'บันทึกส่งบำเพ็ญ ' : 'บันทึกค่าปรับ ') }.$group->name);
        }

        return $record;
    }

    /** @return array<string, mixed> */
    private function present(GroupRecord $r): array
    {
        return [
            'id' => $r->id,
            'kind' => $r->kind,
            'data' => $r->data,
            'note' => $r->note,
            'recorded_by' => $r->recorder ? ($r->recorder->character_name ?: $r->recorder->name) : null,
            'created_at' => $r->created_at,
        ];
    }
}
