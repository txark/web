<?php

namespace App\Http\Controllers\Council;

use App\Enums\RuleSection;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\Rule;
use App\Models\RuleOverride;
use App\Services\OfficialRulesImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule as V;

/**
 * จัดการกฎ (ประธาน/รองประธาน): แก้ เพิ่ม ซ่อน กฎในหน้าเว็บ
 * ข้อของเว็บทางการที่แก้/ซ่อนเก็บเป็น rule_overrides (ซิงก์ผสมให้ ไม่ทับ) · ข้อที่เพิ่มเองเป็น is_local (ซิงก์ไม่ลบ)
 */
class RuleAdminController extends Controller
{
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:250'],
            'body' => ['nullable', 'string', 'max:6000'],
            'note' => ['nullable', 'string', 'max:3000'],
            'penalty' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function present(Rule $r, $overrides): array
    {
        $o = $r->code ? $overrides->get($r->code) : null;

        return [
            'id' => $r->id, 'section' => $r->section->value, 'code' => $r->code, 'kind' => $r->kind,
            'title' => $r->title, 'body' => $r->body, 'note' => $r->note, 'note_label' => $r->note_label, 'penalty' => $r->penalty,
            'is_published' => $r->is_published, 'is_local' => $r->is_local,
            'edited' => (bool) $o && ! $o->hidden, 'hidden' => ! $r->is_published,
        ];
    }

    public function index(): JsonResponse
    {
        $overrides = RuleOverride::query()->get()->keyBy('code');
        $rows = Rule::query()->whereIn('kind', ['rule', 'price'])->orderBy('sort_order')->orderBy('id')->get()->groupBy(fn (Rule $r) => $r->section->value);
        $snap = OfficialRulesImporter::snapshot();

        $sections = collect(RuleSection::cases())->map(fn (RuleSection $s) => [
            'key' => $s->value, 'label' => $s->label(), 'group' => $s->group(),
            'rules' => ($rows[$s->value] ?? collect())->map(fn (Rule $r) => $this->present($r, $overrides))->values(),
        ])->filter(fn ($s) => $s['rules']->isNotEmpty() || $s['key'] !== 'fees')->values();

        return response()->json(['data' => $sections, 'meta' => [
            'auto_sync' => AppSetting::rulesAutoSync(),
            'version' => $snap['version'] ?? null,
            'edited' => $overrides->where('hidden', false)->count(),
            'hidden' => $overrides->where('hidden', true)->count(),
        ]]);
    }

    /** เพิ่มข้อใหม่ท้ายหมวด (หมวดสภา: รหัส XXX-L1, L2…; ซิงก์ไม่ลบ) */
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['section' => ['required', V::in(array_column(RuleSection::cases(), 'value'))]] + $this->rules());
        abort_if($d['section'] === 'fees', 422, 'เพิ่มรายการค่าบริการจากหน้านี้ไม่ได้');

        $locals = Rule::where('section', $d['section'])->where('is_local', true)->count();
        $max = (int) Rule::where('section', $d['section'])->max('sort_order');

        $rule = Rule::create([
            'section' => $d['section'], 'code' => strtoupper($d['section']).'-L'.($locals + 1),
            'sort_order' => $max + 10, 'kind' => 'rule', 'title' => $d['title'], 'body' => $d['body'] ?? null,
            'note_label' => ($d['note'] ?? null) ? 'หมายเหตุ' : null, 'note' => $d['note'] ?? null, 'penalty' => $d['penalty'] ?? null,
            'is_published' => true, 'is_local' => true,
        ]);

        return response()->json(['data' => $this->present($rule, collect())], 201);
    }

    public function update(Request $request, Rule $rule): JsonResponse
    {
        abort_unless(in_array($rule->kind, ['rule', 'price'], true), 404);
        $d = $request->validate($this->rules());

        $fields = [
            'title' => $d['title'], 'body' => $d['body'] ?? null, 'note' => $d['note'] ?? null,
            'note_label' => ($d['note'] ?? null) ? ($rule->note_label ?: 'หมายเหตุ') : null,
            'penalty' => $d['penalty'] ?? null,
        ];
        $rule->update($fields);

        // ข้อของเว็บทางการ: จำการแก้ไว้ให้ซิงก์ผสมให้ (ไม่ใช่ข้อที่เพิ่มเอง/ค่าบริการ)
        if (! $rule->is_local && $rule->code && $rule->kind === 'rule') {
            RuleOverride::updateOrCreate(['code' => $rule->code], ['fields' => $fields, 'hidden' => false, 'updated_by' => $request->user()->id]);
        }

        return response()->json(['data' => $this->present($rule->fresh(), RuleOverride::query()->get()->keyBy('code'))]);
    }

    /** ลบ: ข้อที่เพิ่มเองลบจริง · ข้อของเว็บทางการซ่อน (กู้คืนได้) */
    public function destroy(Request $request, Rule $rule): JsonResponse
    {
        abort_unless(in_array($rule->kind, ['rule', 'price'], true), 404);

        if ($rule->is_local || ! $rule->code || $rule->kind !== 'rule') {
            $rule->delete();

            return response()->json(['data' => ['deleted' => true]]);
        }

        RuleOverride::updateOrCreate(['code' => $rule->code], ['hidden' => true, 'updated_by' => $request->user()->id]);
        $rule->update(['is_published' => false]);

        return response()->json(['data' => $this->present($rule->fresh(), RuleOverride::query()->get()->keyBy('code'))]);
    }

    /** คืนค่าตามเว็บทางการ: ล้างที่แก้/ซ่อนของข้อนี้ แล้วใส่เนื้อหาตามไฟล์เว็บทางการล่าสุดที่มี */
    public function restore(Rule $rule): JsonResponse
    {
        abort_unless($rule->code && ! $rule->is_local, 422, 'ข้อนี้ไม่ใช่ข้อของเว็บทางการ');
        RuleOverride::where('code', $rule->code)->delete();

        $card = collect(OfficialRulesImporter::snapshot()['sections'][$rule->section->value] ?? [])->firstWhere('code', $rule->code);
        if ($card) {
            $rule->update([
                'title' => $card['title'], 'body' => $card['body'], 'penalty' => $card['penalty'],
                'note' => $card['note'], 'note_label' => $card['note'] ? $card['note_label'] : null, 'is_published' => true,
            ]);
        } else {
            $rule->update(['is_published' => true]);
        }

        return response()->json(['data' => $this->present($rule->fresh(), RuleOverride::query()->get()->keyBy('code'))]);
    }

    public function settings(Request $request): JsonResponse
    {
        $d = $request->validate(['auto_sync' => ['required', 'boolean']]);
        AppSetting::put('rules_auto_sync', (bool) $d['auto_sync']);

        return response()->json(['data' => ['auto_sync' => AppSetting::rulesAutoSync()]]);
    }
}
