<?php

namespace App\Http\Controllers\Council;

use App\Enums\TransactionKind;
use App\Http\Controllers\Controller;
use App\Models\ActivityReportRound;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** สภาเปิด/ปิดรอบรายงานความเคลื่อนไหวของแก๊ง/ครอบครัว */
class ActivityRoundController extends Controller
{
    public function index(): JsonResponse
    {
        $rounds = ActivityReportRound::query()->with('creator')->orderByDesc('opens_at')->limit(20)->get();
        $counts = Transaction::query()->where('kind', TransactionKind::ActivityReport->value)->get()
            ->groupBy(fn (Transaction $t) => (int) ($t->payload['round_id'] ?? 0))->map->count();

        return response()->json(['data' => $rounds->map(fn (ActivityReportRound $r) => [
            'id' => $r->id,
            'title' => $r->title,
            'opens_at' => $r->opens_at,
            'closes_at' => $r->closes_at,
            'closed_early_at' => $r->closed_early_at,
            'phase' => $r->phase(),
            'reports_count' => $counts[$r->id] ?? 0,
            'created_by' => $r->creator ? ($r->creator->character_name ?: $r->creator->name) : null,
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'opens_at' => ['required', 'date'],
            'closes_at' => ['required', 'date', 'after:opens_at'],
        ], [
            'title.required' => 'กรุณาตั้งชื่อรอบ',
            'opens_at.required' => 'กรุณาระบุเวลาเปิดรับ',
            'closes_at.required' => 'กรุณาระบุเวลาปิดรับ',
            'closes_at.after' => 'เวลาปิดรับต้องหลังเวลาเปิดรับ',
        ]);

        $overlap = ActivityReportRound::query()->whereNull('closed_early_at')
            ->where('opens_at', '<', $d['closes_at'])->where('closes_at', '>', $d['opens_at'])->exists();
        if ($overlap) {
            return response()->json(['message' => 'ช่วงเวลานี้ทับกับรอบที่เปิดไว้แล้ว', 'errors' => ['opens_at' => ['ช่วงเวลานี้ทับกับรอบที่เปิดไว้แล้ว']]], 422);
        }

        $round = ActivityReportRound::create($d + ['created_by' => $request->user()->id]);
        activity('groups')->causedBy($request->user())->performedOn($round)->log("เปิดรอบรายงานความเคลื่อนไหว {$round->title}");

        return response()->json(['data' => ['id' => $round->id]], 201);
    }

    /** ปิดรอบก่อนกำหนด (รอบที่ยังไม่เริ่มจะถูกยกเลิก) */
    public function close(Request $request, ActivityReportRound $round): JsonResponse
    {
        abort_if($round->phase() === 'closed', 409, 'รอบนี้ปิดไปแล้ว');
        $round->update(['closed_early_at' => now()]);
        activity('groups')->causedBy($request->user())->performedOn($round)->log("ปิดรอบรายงานความเคลื่อนไหว {$round->title}");

        return response()->json(['data' => ['id' => $round->id]]);
    }
}
