<?php

namespace App\Http\Controllers\Council;

use App\Enums\GroupType;
use App\Http\Controllers\Controller;
use App\Models\Group;
use Illuminate\Http\JsonResponse;

/**
 * แจ้งเตือนงานค้างของสภา: คำนวณจากข้อมูลจริงทุกครั้งที่เปิด (ไม่มีตารางแยก จึงไม่ค้างเมื่อแก้ปัญหาแล้ว)
 * งานใหม่ (คำร้อง สัญญาสตอรี่ ฯลฯ) เพิ่มเป็นกฎอีกข้อในที่นี้ได้
 * ไม่แจ้งเตือนจำนวนสมาชิก: ระบบเก็บเฉพาะรายชื่อหัวหน้าและรอง จึงนับสมาชิกทั้งหมดไม่ได้
 */
class AlertController extends Controller
{
    private const SEVERITY_ORDER = ['high' => 0, 'medium' => 1, 'low' => 2];

    public function index(): JsonResponse
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

        usort($alerts, fn ($a, $b) => [self::SEVERITY_ORDER[$a['severity']], $a['since']] <=> [self::SEVERITY_ORDER[$b['severity']], $b['since']]);

        return response()->json([
            'data' => $alerts,
            'meta' => [
                'count' => count($alerts),
                'by_severity' => collect($alerts)->countBy('severity'),
            ],
        ]);
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
