<?php

namespace App\Http\Controllers\Council;

use App\Http\Controllers\Controller;
use App\Models\CouncilLoanSetting;
use App\Models\CouncilLoanSettingLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * ตั้งค่ากลางการกู้เงิน (ประธานและรองประธาน): ดอกเบี้ยเริ่มต้น วิธีคิดค่าปรับ จำนวนเงินค่าปรับ จำนวนงวดผ่อนสูงสุด
 * เป็นค่าเริ่มต้นที่ใส่ให้อัตโนมัติตอนสร้างสัญญาใหม่ สัญญาที่ทำไปแล้วเก็บค่าตอนทำสัญญา ไม่เปลี่ยนตาม
 * ทุกครั้งที่แก้ลงประวัติ (ต่อท้าย แก้ไม่ได้) และ activity log
 */
class LoanSettingController extends Controller
{
    private const LABELS = [
        'default_interest' => 'ดอกเบี้ยเริ่มต้น',
        'late_fee_type' => 'วิธีคิดค่าปรับ',
        'late_fee_amount' => 'จำนวนเงินค่าปรับ',
        'max_installments' => 'จำนวนงวดสูงสุด',
    ];

    private const MESSAGES = [
        'default_interest.required' => 'กรุณากรอกดอกเบี้ยเริ่มต้น',
        'default_interest.numeric' => 'ดอกเบี้ยต้องเป็นตัวเลข',
        'default_interest.min' => 'ดอกเบี้ยต้องไม่ติดลบ',
        'default_interest.max' => 'ดอกเบี้ยต่อสัปดาห์ต้องไม่เกิน 100%',
        'late_fee_type.required' => 'กรุณาเลือกวิธีคิดค่าปรับ',
        'late_fee_type.in' => 'วิธีคิดค่าปรับไม่ถูกต้อง',
        'late_fee_amount.required' => 'กรุณากรอกจำนวนเงินค่าปรับ',
        'late_fee_amount.integer' => 'จำนวนเงินค่าปรับต้องเป็นตัวเลข',
        'late_fee_amount.min' => 'จำนวนเงินค่าปรับต้องไม่ติดลบ',
        'late_fee_amount.max' => 'จำนวนเงินค่าปรับมากเกินไป',
        'max_installments.required' => 'กรุณากรอกจำนวนงวดผ่อนสูงสุด',
        'max_installments.integer' => 'จำนวนงวดต้องเป็นตัวเลข',
        'max_installments.min' => 'จำนวนงวดต้องอย่างน้อย 1 งวด',
        'max_installments.max' => 'จำนวนงวดต้องไม่เกิน 104 งวด (2 ปี)',
    ];

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $d = $request->validate([
            'default_interest' => ['required', 'numeric', 'min:0', 'max:100'],
            'late_fee_type' => ['required', Rule::in(array_keys(CouncilLoanSetting::FEE_TYPES))],
            'late_fee_amount' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'max_installments' => ['required', 'integer', 'min:1', 'max:104'],
        ], self::MESSAGES);
        $d['default_interest'] = round((float) $d['default_interest'], 2);

        DB::transaction(function () use ($d, $request) {
            $s = CouncilLoanSetting::current();
            foreach ($d as $field => $new) {
                $old = $s->{$field};
                if ((string) $old === (string) $new) {
                    continue;
                }
                CouncilLoanSettingLog::create([
                    'field' => $field, 'old_value' => $this->display($field, $old), 'new_value' => $this->display($field, $new), 'changed_by' => $request->user()->id,
                ]);
            }
            $s->update($d + ['updated_by' => $request->user()->id]);
            activity('finance')->causedBy($request->user())->performedOn($s)->withProperties(['attributes' => $d])->log('แก้ตั้งค่ากลางการกู้เงิน');
        });

        return response()->json(['data' => $this->payload()]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $s = CouncilLoanSetting::current();
        $logs = CouncilLoanSettingLog::query()->with('user:id,name,character_name,role')->orderByDesc('id')->limit(100)->get();

        return [
            'settings' => ['default_interest' => $s->default_interest, 'late_fee_type' => $s->late_fee_type, 'late_fee_amount' => $s->late_fee_amount, 'max_installments' => $s->max_installments],
            'fee_types' => collect(CouncilLoanSetting::FEE_TYPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'logs' => $logs->map(fn (CouncilLoanSettingLog $l) => [
                'id' => $l->id, 'field' => $l->field, 'label' => self::LABELS[$l->field] ?? $l->field, 'old' => $l->old_value, 'new' => $l->new_value,
                'by' => $l->user ? ($l->user->character_name ?: $l->user->name) : '', 'role_label' => $l->user?->role->thaiName(), 'at' => $l->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** ข้อความของค่าที่แสดงในประวัติ (เช่น 3% / ต่อวัน / 500 / 6 งวด) */
    private function display(string $field, mixed $v): string
    {
        return match ($field) {
            'default_interest' => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').'%',
            'late_fee_type' => CouncilLoanSetting::FEE_TYPES[$v] ?? (string) $v,
            'late_fee_amount' => number_format((int) $v),
            'max_installments' => $v.' งวด',
            default => (string) $v,
        };
    }
}
