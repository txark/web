<?php

namespace App\Http\Controllers\Council;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CouncilReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** บันทึกเตือนความจำของสภา: เพิ่ม / ทำเครื่องหมายเสร็จ / ลบ (รายการที่ยังไม่เสร็จขึ้นในหน้าแจ้งเตือนงานค้าง) */
class ReminderController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:2000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'title.required' => 'กรุณากรอกหัวข้อเตือนความจำ',
            'title.max' => 'หัวข้อยาวเกินไป (ไม่เกิน 150 ตัวอักษร)',
            'due_on.date_format' => 'วันที่กำหนดไม่ถูกต้อง',
        ]);

        $r = CouncilReminder::create([
            'title' => trim($d['title']),
            'body' => isset($d['body']) && trim($d['body']) !== '' ? trim($d['body']) : null,
            'due_on' => $d['due_on'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        activity('reminders')->causedBy($request->user())->performedOn($r)->log("เพิ่มบันทึกเตือนความจำ: {$r->title}");

        return response()->json(['data' => ['id' => $r->id]], 201);
    }

    public function done(Request $request, CouncilReminder $reminder): JsonResponse
    {
        abort_if($reminder->done_at !== null, 409, 'รายการนี้ทำเครื่องหมายเสร็จแล้ว');
        $reminder->update(['done_at' => now(), 'done_by' => $request->user()->id]);
        activity('reminders')->causedBy($request->user())->performedOn($reminder)->log("เตือนความจำเสร็จแล้ว: {$reminder->title}");

        return response()->json(['data' => ['id' => $reminder->id]]);
    }

    /** ลบได้เฉพาะผู้สร้าง หรือรองประธานขึ้นไป */
    public function destroy(Request $request, CouncilReminder $reminder): JsonResponse
    {
        $user = $request->user();
        abort_unless($reminder->created_by === $user->id || $user->atLeast(Role::VicePresident), 403, 'ลบได้เฉพาะผู้สร้างหรือรองประธานขึ้นไป');
        $reminder->delete();
        activity('reminders')->causedBy($user)->log("ลบบันทึกเตือนความจำ: {$reminder->title}");

        return response()->json(null, 204);
    }
}
