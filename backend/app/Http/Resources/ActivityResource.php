<?php

namespace App\Http\Resources;

use App\Enums\GroupStatus;
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
    ];

    /** บันทึกจากโมเดล (created/updated/deleted) เป็นภาษาอังกฤษ จึงแปลเป็นไทยตอนแสดง */
    private function description(): string
    {
        if ($this->log_name === 'default' && isset(self::EVENT_LABELS[$this->description])) {
            $subject = self::SUBJECT_LABELS[$this->subject_type] ?? null;

            return self::EVENT_LABELS[$this->description].($subject ? ' '.$subject : '');
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
        } elseif (isset($p['penalty_type'])) {
            $penance = $p['penalty_type'] === 'penance';
            $lines[] = ['ประเภท', $penance ? 'ส่งบำเพ็ญ' : 'ค่าปรับ'];
            if (! empty($p['category_label'])) {
                $lines[] = ['หมวด', $p['category_label']];
            }
            if (! empty($p['charge'])) {
                $lines[] = ['ข้อหา', $p['charge']];
            }
            $lines[] = $penance ? ['จำนวนไม้', ($p['penance_units'] ?? 0).' ไม้'] : ['จำนวนเงิน', number_format((int) ($p['amount'] ?? 0)).' WVDL'];
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
            'details' => $tx ? TransactionResource::detailLines($tx) : $this->groupDetails(),
            'changes' => $this->attribute_changes,
            'properties' => $this->properties,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
