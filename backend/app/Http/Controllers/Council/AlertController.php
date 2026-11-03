<?php

namespace App\Http\Controllers\Council;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CouncilLoan;
use App\Models\CouncilReminder;
use App\Models\Group;
use App\Enums\TransactionKind;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * แจ้งเตือนงานค้างของสภา: คำนวณจากข้อมูลจริงทุกครั้งที่เปิด (ไม่มีตารางแยก จึงไม่ค้างเมื่อแก้ปัญหาแล้ว)
 * งานใหม่ (คำร้อง สัญญาสตอรี่ ฯลฯ) เพิ่มเป็นกฎอีกข้อในที่นี้ได้
 * ไม่แจ้งเตือนจำนวนสมาชิก: ระบบเก็บเฉพาะรายชื่อหัวหน้าและรอง จึงนับสมาชิกทั้งหมดไม่ได้
 * สัญญากู้เงินเกินกำหนด/ใกล้ครบกำหนด แจ้งเฉพาะประธานและรองประธาน พร้อมข้อความทวง (copy_text) ให้คัดลอกไปส่งเอง ไม่ใช้ Discord bot
 */
class AlertController extends Controller
{
    private const SEVERITY_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    public function index(Request $request): JsonResponse
    {
        $lockedDays = config('council.alerts.locked_days');

        $groups = Group::query()->with('leader')->get();

        $alerts = [];
        foreach ($groups as $g) {
            $link = "/manage-groups.html?id={$g->id}";
            $label = "{$g->type->label()} {$g->name}";

            if (! $g->leader) {
                $alerts[] = $this->alert('no_leader', 'high', $g, $link, "{$label} ยังไม่มีหัวหน้า", 'กำหนดหัวหน้าในหน้าจัดการกลุ่ม', $g->created_at);
            }

            if ($g->slots_locked && $g->updated_at->lt(now()->subDays($lockedDays))) {
                $days = (int) $g->updated_at->diffInDays(now());
                $alerts[] = $this->alert('locked_long', 'low', $g, $link, "{$label} ล็อกสล็อตมานาน {$days} วัน", 'ตรวจสอบว่ายังต้องล็อกอยู่หรือไม่'.($g->locked_reason ? " (เหตุผล: {$g->locked_reason})" : ''), $g->updated_at);
            }
        }

        array_push($alerts, ...$this->pendingTransactionAlerts());
        array_push($alerts, ...$this->reminderAlerts());

        if ($request->user()->atLeast(Role::VicePresident) && ! $request->user()->role->isAdmin()) {
            array_push($alerts, ...$this->loanAlerts());
        }

        usort($alerts, fn ($a, $b) => [self::SEVERITY_ORDER[$a['severity']], $a['since']] <=> [self::SEVERITY_ORDER[$b['severity']], $b['since']]);

        return response()->json([
            'data' => $alerts,
            'meta' => [
                'count' => count($alerts),
                'by_severity' => collect($alerts)->countBy('severity'),
            ],
        ]);
    }

    /**
     * บันทึกเตือนความจำที่ยังไม่เสร็จ: เกินกำหนด = เร่งด่วน · ครบกำหนดวันนี้/พรุ่งนี้ = ควรทำ · ที่เหลือ (ไม่มีกำหนด/ยังไกล) = ทบทวน
     *
     * @return list<array<string, mixed>>
     */
    private function reminderAlerts(): array
    {
        $today = now(config('council.report_timezone'))->startOfDay();

        return CouncilReminder::query()->with('creator')->whereNull('done_at')->orderBy('id')->get()->map(function (CouncilReminder $r) use ($today) {
            $days = $r->due_on ? (int) $today->diffInDays(Carbon::parse($r->due_on->toDateString(), config('council.report_timezone'))->startOfDay(), false) : null;
            $severity = $days === null ? 'low' : ($days < 0 ? 'high' : ($days <= 1 ? 'medium' : 'low'));
            $when = $days === null ? 'ไม่มีกำหนด' : ($days < 0 ? 'เกินกำหนด '.abs($days).' วัน' : ($days === 0 ? 'ครบกำหนดวันนี้' : "ครบกำหนดอีก {$days} วัน"));

            return [
                'id' => "reminder:{$r->id}",
                'type' => 'reminder',
                'reminder_id' => $r->id,
                'severity' => $severity,
                'title' => $r->title,
                'detail' => trim(($r->body ? $r->body.' · ' : '').$when),
                'due_on' => $r->due_on?->toDateString(),
                'days_left' => $days,   // null = ไม่มีกำหนด · 0 = วันนี้ · ติดลบ = เกินกำหนด (ใช้เด้งเตือนกลางจอ)
                'link' => null,
                'group_type' => null,
                'since' => $r->created_at->toIso8601String(),
                'author' => $r->creator ? ($r->creator->character_name ?: $r->creator->name) : null,
                'created_by' => $r->created_by,
            ];
        })->all();
    }

    /**
     * ธุรกรรมที่รอสภาอนุมัติ (รวมคำขอที่แก๊ง/ครอบครัวยื่นเข้ามา): ควรทำ · ค้างเกิน 1 วันขึ้นเป็นเร่งด่วน
     * หายไปเองเมื่ออนุมัติ ไม่อนุมัติ หรือผู้ยื่นยกเลิก
     *
     * @return list<array<string, mixed>>
     */
    private function pendingTransactionAlerts(): array
    {
        return Transaction::query()->with(['group', 'requester'])->where('status', 'pending')->orderBy('id')->get()
            ->map(function (Transaction $t) {
                $fromGroup = in_array($t->requested_role, [Role::Gang->value, Role::Family->value], true);
                $who = $t->requester?->character_name ?: $t->requester?->name ?: '—';
                $name = $t->group?->name ?? ($t->payload['name'] ?? '—');
                $group = $t->group ?? null;
                $groupType = $group?->type->value ?? ($t->payload['type'] ?? null);

                return [
                    'id' => "txn:{$t->id}",
                    'type' => 'txn_pending',
                    'kind' => $t->kind->value,
                    'severity' => $t->created_at->lt(now()->subDay()) ? 'high' : 'medium',
                    'title' => "{$t->kind->label()} {$name} รออนุมัติ".($fromGroup ? ' (แก๊ง/ครอบครัวยื่นเข้ามา)' : ''),
                    'detail' => "{$t->number} · ยื่นโดย {$who} ({$t->requested_role})",
                    'link' => $t->kind === TransactionKind::ActivityReport ? "/activity-rounds.html?id={$t->id}" : "/requests.html?id={$t->id}",
                    'group_type' => $groupType,
                    'since' => $t->created_at->toIso8601String(),
                ];
            })->all();
    }

    /**
     * สัญญากู้เงินที่ยังไม่มีการคืนเงินหรือคืนไม่ครบทุกใบ: เกินกำหนด (เร่งด่วน) · จะครบกำหนดภายใน council.alerts.loan_due_soon_days วัน (ควรทำ)
     * · ที่เหลือ (ยังไม่ถึงกำหนด) ขึ้นเป็น "ทบทวน" ให้ติดตามยอดค้าง หายไปเมื่อคืนครบหรือยกเลิกสัญญา
     *
     * @return list<array<string, mixed>>
     */
    private function loanAlerts(): array
    {
        $tz = config('council.report_timezone');
        $today = now($tz)->startOfDay();
        $soon = (int) config('council.alerts.loan_due_soon_days', 3);
        $out = [];

        CouncilLoan::query()->with(['group', 'payments'])->whereNull('cancelled_at')->get()->each(function (CouncilLoan $l) use (&$out, $today, $soon, $tz) {
            $status = $l->status();
            if (! in_array($status, ['open', 'partial', 'overdue'], true)) {
                return;
            }
            $next = collect($l->schedule())->first(fn (array $s) => ! in_array($s['status'], ['paid', 'paid_late'], true));
            if (! $next || ! $next['due_at']) {
                return;
            }
            $due = Carbon::parse($next['due_at'], $tz)->startOfDay();
            $days = (int) $today->diffInDays($due, false); // บวก = เหลืออีกกี่วัน, ลบ = เกินมากี่วัน

            $over = $days < 0;
            $near = $days <= $soon;
            $name = $l->group?->name ?? '—';
            $repaid = $l->repaid();
            $title = match (true) {
                $over => "สัญญากู้เงิน {$name} เกินกำหนด ".abs($days).' วัน',
                $days === 0 => "สัญญากู้เงิน {$name} ครบกำหนดวันนี้",
                $near => "สัญญากู้เงิน {$name} ใกล้ครบกำหนดอีก {$days} วัน",
                default => $repaid > 0 ? "สัญญากู้เงิน {$name} ยังคืนไม่ครบ" : "สัญญากู้เงิน {$name} ยังไม่มีการคืนเงิน",
            };
            $parts = $l->outstandingParts();
            $number = 'WV-LN-'.str_pad((string) $l->id, 6, '0', STR_PAD_LEFT);
            $when = $this->thaiDate($due);
            $text = implode("
", array_filter([
                'แจ้งเตือนจากสภาเมืองคลื่น',
                "สัญญากู้เงิน {$number}",
                'ผู้กู้: '.($l->borrower_name ?: '—')." ({$name})",
                'ยอดกู้ '.number_format($l->principal).' WVDL · งวดที่ '.$next['no'].' ครบกำหนด '.$when,
                $over ? 'เกินกำหนดมาแล้ว '.abs($days).' วัน' : ($days === 0 ? 'ครบกำหนดวันนี้' : "อีก {$days} วันจะครบกำหนด"),
                'ยอดที่ต้องคืน '.number_format($l->outstanding()).' WVDL (ต้นเงินและดอกเบี้ย '.number_format($parts['pi']).($parts['fee'] > 0 ? ' + ค่าปรับ '.number_format($parts['fee']) : '').')',
                'กรุณาติดต่อสภาเพื่อคืนเงินโดยเร็ว',
            ]));

            $out[] = [
                'id' => "loan:{$l->id}", 'type' => $over ? 'loan_overdue' : ($near ? 'loan_due_soon' : 'loan_pending'), 'severity' => $over ? 'high' : ($near ? 'medium' : 'low'),
                'title' => $title,
                'detail' => 'ผู้กู้ '.($l->borrower_name ?: '—').' · '.($repaid > 0 ? 'คืนแล้ว '.number_format($repaid).' จากยอดรวม '.number_format($l->totalPayable()) : 'ยังไม่มีการคืนเงิน').' · คงค้าง '.number_format($l->outstanding()).' WVDL', 'link' => '/finance.html#loans',
                'group_type' => $l->group?->type->value, 'since' => $due->copy()->utc()->toIso8601String(), 'copy_text' => $text,
            ];
        });

        return $out;
    }

    private function thaiDate(Carbon $d): string
    {
        $m = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

        return $d->day.' '.$m[$d->month - 1].' '.($d->year + 543);
    }

    private function alert(string $type, string $severity, Group $g, string $link, string $title, string $detail, $since): array
    {
        return [
            'id' => "{$type}:{$g->id}",
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'link' => $link,
            'group_type' => $g->type->value,
            'since' => $since?->toIso8601String(),
        ];
    }
}
