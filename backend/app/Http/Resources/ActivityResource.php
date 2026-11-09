<?php

namespace App\Http\Resources;

use App\Enums\GroupStatus;
use App\Models\Announcement;
use App\Models\CouncilEntry;
use App\Models\CouncilLoanPayment;
use App\Models\CouncilPayRound;
use App\Models\CouncilSummon;
use App\Models\CouncilTrade;
use App\Models\Dispute;
use App\Models\DutyShift;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Rule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Spatie\Activitylog\Models\Activity */
class ActivityResource extends JsonResource
{
    private const LOG_LABELS = [
        'auth' => 'เข้า/ออกระบบ',
        'access' => 'การเข้าดู',
        'transactions' => 'ธุรกรรม',
        'groups' => 'แก๊ง/ครอบครัว',
        'duty' => 'เวร',
        'stories' => 'สตอรี่',
        'default' => 'แก้ไขข้อมูล',
    ];

    private const EVENT_LABELS = [
        'login' => 'เข้าสู่ระบบ',
        'logout' => 'ออกจากระบบ',
        'login_failed' => 'เข้าสู่ระบบไม่สำเร็จ',
        'view' => 'เปิดดู',
        'denied' => 'ถูกปฏิเสธสิทธิ์',
        'created' => 'สร้าง',
        'updated' => 'แก้ไข',
        'deleted' => 'ลบ',
        'approved' => 'อนุมัติ',
        'rejected' => 'ไม่อนุมัติ',
        'cancelled' => 'ยกเลิก',
        'started' => 'สร้าง',
        'ended' => 'สิ้นสุด',
        'scored' => 'บันทึกคะแนน',
        'shared' => 'สร้างลิงก์แชร์',
        'unshared' => 'ยกเลิกลิงก์แชร์',
        'start' => 'เข้าเวร',
        'end' => 'ออกเวร',
        'system_end' => 'ระบบปิดเวร',
    ];

    private const SUBJECT_LABELS = [
        User::class => 'ผู้ใช้',
        Group::class => 'แก๊ง/ครอบครัว',
        GroupMember::class => 'สมาชิกแก๊ง',
        Transaction::class => 'ธุรกรรม',
        Rule::class => 'กฎ',
        CouncilEntry::class => 'รายรับ/รายจ่าย',
        CouncilTrade::class => 'ซื้อ-ขาย',
        CouncilPayRound::class => 'รอบโบนัสเวร',
        CouncilLoanPayment::class => 'รับคืนเงินกู้',
        CouncilSummon::class => 'ประกาศเรียก',
        Announcement::class => 'ประกาศ',
        Dispute::class => 'ข้อพิพาท',
    ];

    /** บันทึกจากโมเดล (created/updated/deleted) เป็นภาษาอังกฤษ จึงแปลเป็นไทยตอนแสดง */
    private function description(): string
    {
        if ($this->log_name === 'default' && isset(self::EVENT_LABELS[$this->description])) {
            $subject = self::SUBJECT_LABELS[$this->subject_type] ?? null;

            return self::EVENT_LABELS[$this->description].($subject ? ' '.$subject : '');
        }

        // ออกเวร/ระบบปิดเวร: ตัดส่วนอธิบายท้ายข้อความออก (ช่วงเวลา ระยะเวลา สาเหตุ อยู่ในรายละเอียดแล้ว)
        if ($this->log_name === 'duty' && $this->event === 'end') {
            return 'ออกเวร';
        }
        if ($this->log_name === 'duty' && $this->event === 'system_end') {
            return 'ระบบปิดเวร';
        }

        return (string) $this->description;
    }

    /** ธุรกรรม: คืนรายละเอียดว่าทำอะไรบ้าง (คำนวณจากตัวธุรกรรม จึงใช้กับบันทึกเก่าได้ด้วย) */
    private function transaction(): ?Transaction
    {
        if ($this->log_name !== 'transactions' || $this->subject_type !== Transaction::class) {
            return null;
        }

        return $this->subject instanceof Transaction ? $this->subject->loadMissing('group') : null;
    }

    /** บันทึกของแก๊ง/ครอบครัว (แก้จำนวนชุด / Protect / ค่าปรับ / รายงานตัว): อ่านจาก properties ที่เก็บไว้ */
    private function groupDetails(): array
    {
        if ($this->log_name !== 'groups') {
            return [];
        }

        $p = $this->properties?->toArray() ?? [];
        $lines = [];
        $dash = fn ($v) => $v === null || $v === '' ? '—' : $v;
        $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone(config('council.report_timezone'))->format('d/m/Y H:i') : null;

        if (is_array($p['before'] ?? null) && is_array($p['after'] ?? null)) {
            // ก่อน → หลัง เฉพาะช่องที่เปลี่ยน (จำนวนชุด / วัน Protect)
            foreach (array_unique(array_merge(array_keys($p['before']), array_keys($p['after']))) as $key) {
                $from = $p['before'][$key] ?? null;
                $to = $p['after'][$key] ?? null;
                if ($from === $to) {
                    continue;
                }
                if (isset(TransactionResource::OUTFIT_LABELS[$key])) {
                    $lines[] = [TransactionResource::OUTFIT_LABELS[$key], $dash($from === null ? null : "{$from} ชิ้น").' → '.$dash($to === null ? null : "{$to} ชิ้น")];
                } else {
                    $label = ['protect_from' => 'Protect เริ่ม', 'protect_until' => 'Protect สิ้นสุด'][$key] ?? $key;
                    $lines[] = [$label, $dash($date($from)).' → '.$dash($date($to))];
                }
            }
        } elseif (isset($p['import_source'])) {
            $lines[] = ['ที่มาของข้อมูล', $p['import_source']];
            $lines[] = ['วันที่ขึ้นทะเบียนเดิม', $date($p['registered_at'] ?? null) ?? '—'];
            $lines[] = ['จำนวนสมาชิกที่นำเข้า', ($p['members'] ?? 0).' คน'];
        } elseif (isset($p['penalty_type'])) {
            $penance = $p['penalty_type'] === 'penance';
            $both = $p['penalty_type'] === 'both';
            $lines[] = ['ประเภท', $both ? 'ค่าปรับและส่งบำเพ็ญ' : ($penance ? 'ส่งบำเพ็ญ' : 'ค่าปรับ')];
            if (! empty($p['category_label'])) {
                $lines[] = ['หมวด', $p['category_label']];
            }
            if (! empty($p['charge'])) {
                $lines[] = ['ข้อหา', $p['charge']];
            }
            if ($penance || $both) {
                $lines[] = ['จำนวนไม้', ($p['penance_units'] ?? 0).' ไม้'];
            }
            if (! $penance) {
                $lines[] = ['จำนวนเงิน', number_format((int) ($p['amount'] ?? 0)).' WVDL'];
            }
            if (! empty($p['rule_penalty'])) {
                $lines[] = ['บทลงโทษตามกฎ', $p['rule_penalty']];
            }
        } elseif (isset($p['status'])) {
            $label = fn ($v) => GroupStatus::tryFrom((string) $v)?->label() ?? $v;
            $lines[] = ['สถานะ', ($p['previous_status'] ?? null ? $label($p['previous_status']).' → ' : '').$label($p['status'])];
        }

        if (! empty($p['note'])) {
            $lines[] = ['หมายเหตุ', $p['note']];
        }

        return $lines;
    }

    /** ชื่อช่องที่เปลี่ยนให้เข้ากับบริบทของรายการ (เช่น แถวค่าบริการ: ช่อง body = อัตรา ไม่ใช่ "เนื้อหา") */
    private function fieldLabels(): array
    {
        $m = $this->subject;
        if ($m instanceof Rule && $m->kind === 'price') {
            return ['title' => 'รายการ', 'body' => 'ค่าบริการ', 'note' => 'หมายเหตุ'];
        }

        return [];
    }

    /** รายการที่มีรหัส (การเงิน/ซื้อ-ขาย/โบนัส/รับคืนเงินกู้/ประกาศ): แสดงรหัสและสาระสำคัญของรายการ */
    private function docDetails(): array
    {
        $m = $this->subject;

        // กฎ: บอกว่าแก้ข้อไหน (รหัส + หัวข้อ + หมวด) ให้ตรงกับช่อง "เนื้อหา" ที่เปลี่ยน
        if ($m instanceof Rule) {
            $where = $m->kind === 'price' ? 'อัตราค่าบริการ' : ($m->section?->label() ?? '');

            return array_values(array_filter([
                ['ข้อกฎ', trim(($m->code ? $m->code.' · ' : '').($m->title ?: ($m->kind === 'price' ? 'รายการค่าบริการ' : '')))],
                $where !== '' ? ['หมวด', $where] : null,
            ]));
        }
        if (! $m || ! isset(self::SUBJECT_LABELS[$this->subject_type]) || empty($m->number)) {
            return [];
        }

        $baht = fn ($v) => number_format((int) $v).' WVDL';
        $lines = [['รหัสรายการ', $m->number], ['ชนิด', self::SUBJECT_LABELS[$this->subject_type]]];

        if ($m instanceof CouncilEntry) {
            $lines[] = ['รายการ', $m->title];
            $lines[] = [$m->type === 'income' ? 'รายรับ' : 'รายจ่าย', $baht($m->amount)];
        } elseif ($m instanceof CouncilTrade) {
            $lines[] = ['รายการ', $m->item.' ×'.number_format($m->quantity)];
            $lines[] = [$m->direction === 'sell' ? 'สภาขาย' : 'สภาซื้อ', $baht($m->amount)];
        } elseif ($m instanceof CouncilLoanPayment) {
            $lines[] = [$m->reversal_of ? 'ยกเลิกรับคืน' : 'รับคืน', $baht($m->amount)];
        } elseif ($m instanceof CouncilPayRound) {
            $lines[] = ['ถึงวันที่', $m->ended_at?->timezone(config('council.report_timezone'))->format('d/m/Y H:i')];
        } elseif ($m instanceof CouncilSummon) {
            $lines[] = ['เหตุผล', (string) $m->reason];
        } elseif ($m instanceof Announcement) {
            $lines[] = ['หัวข้อ', (string) $m->title];
        }

        return $lines;
    }

    /**
     * ออกเวร / ระบบปิดเวร / ออกจากระบบขณะเข้าเวร: ช่วงเวลาที่เข้าเวร ระยะเวลา และสาเหตุเมื่อออกก่อนเวลา
     * (เข้าเวร / เข้าสู่ระบบ / ออกจากระบบตอนไม่ได้เข้าเวร ไม่มีรายละเอียด)
     */
    private function dutyDetails(): array
    {
        $shift = $this->subject;
        $viaLogout = false;

        if ($this->log_name === 'auth' && $this->event === 'logout' && $this->causer_id) {
            // ออกจากระบบแล้วเวรปิดตามไปด้วย: หาเวรที่ปิดด้วยการออกจากระบบของผู้ใช้นี้ในช่วงเวลาเดียวกัน
            $shift = DutyShift::query()->where('user_id', $this->causer_id)->where('note', 'ออกจากระบบ')
                ->whereBetween('ended_at', [$this->created_at->copy()->subSeconds(10), $this->created_at->copy()->addSeconds(10)])
                ->latest('id')->first();
            $viaLogout = true;
        } elseif ($this->log_name !== 'duty' || ! in_array($this->event, ['end', 'system_end'], true)) {
            return [];
        }

        if (! $shift instanceof DutyShift || ! $shift->ended_at) {
            return [];
        }

        $tz = config('council.report_timezone');
        $from = $shift->started_at->copy()->timezone($tz);
        $to = $shift->ended_at->copy()->timezone($tz);
        // วันที่แบบไทย (พ.ศ.) เช่น 7 พ.ย. 2569 เวลา 23:23:00 น. — แยกเป็นเวลาเข้า/ออกคนละแถว อ่านง่าย
        $months = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
        $date = fn ($d) => $d->day.' '.$months[$d->month - 1].' '.($d->year + 543);
        $time = fn ($d) => $d->format('H:i:s').' น.';

        $secs = (int) $shift->started_at->diffInSeconds($shift->ended_at);
        $dur = (intdiv($secs, 3600) > 0 ? intdiv($secs, 3600).' ชม. ' : '').intdiv($secs % 3600, 60).' นาที '.($secs % 60).' วินาที';

        $lines = [];
        $p = $this->properties?->toArray() ?? [];
        if ($viaLogout) {
            $lines[] = ['หมายเหตุ', 'ออกจากระบบขณะอยู่ในเวร ระบบออกเวรให้โดยอัตโนมัติ'];
        }
        // ออกก่อนเวลาขั้นต่ำ (ไม่นับกรณีออกจากระบบ): แจ้งสาเหตุที่ผู้ใช้ระบุ
        if ($this->event === 'end' && ! empty($p['short']) && empty($p['logout']) && ! empty($p['note'])) {
            $lines[] = ['สาเหตุที่ออกก่อนเวลา', $p['note']];
        }
        // วันที่ · เวลาเข้าเวร · เวลาออกเวร · ระยะเวลา (กะที่ข้ามวันแสดงวันที่เริ่ม – วันที่จบ)
        $lines[] = ['วันที่', $from->isSameDay($to) ? $date($from) : $date($from).' – '.$date($to)];
        $lines[] = ['เวลาเข้าเวร', $time($from)];
        $lines[] = [$this->event === 'system_end' ? 'เวลาออกเวร (สัญญาณล่าสุด)' : 'เวลาออกเวร', $time($to)];
        $lines[] = ['ระยะเวลาเข้าเวร', $dur];

        return $lines;
    }

    public function toArray(Request $request): array
    {
        $causer = $this->causer;
        $tx = $this->transaction();

        return [
            'id' => $this->id,
            'log' => $this->log_name,
            'log_label' => self::LOG_LABELS[$this->log_name] ?? $this->log_name,
            'event' => $this->event,
            'event_label' => self::EVENT_LABELS[$this->event] ?? $this->event,
            'description' => $this->description(),
            'causer' => $causer ? [
                'id' => $causer->id,
                'name' => $causer->name,
                'avatar' => $causer->avatar,
                'role' => $causer->role->value,
                'role_label' => $causer->role->label(),
                'role_name' => $causer->role->thaiName(),
            ] : null,
            'subject' => $this->subject_type ? [
                'label' => self::SUBJECT_LABELS[$this->subject_type] ?? class_basename($this->subject_type),
                'id' => $this->subject_id,
            ] : null,
            'summary' => $tx ? (new TransactionResource($tx))->toArray($request)['summary'] : null,
            'details' => $tx ? TransactionResource::detailLines($tx) : ($this->groupDetails() ?: $this->docDetails() ?: $this->dutyDetails()),
            'labels' => $this->fieldLabels(),
            'changes' => $this->attribute_changes,
            'properties' => $this->properties,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
