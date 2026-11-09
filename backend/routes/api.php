<?php

use App\Http\Controllers\Auth\DiscordController;
use App\Http\Controllers\Council\AlertController;
use App\Http\Controllers\Council\AnnouncementController as CouncilAnnouncementController;
use App\Http\Controllers\Council\DutyController;
use App\Http\Controllers\Council\DutyReportController;
use App\Http\Controllers\Council\FinanceController;
use App\Http\Controllers\Council\BonusController;
use App\Http\Controllers\Council\LoanController;
use App\Http\Controllers\Council\LoanSettingController;
use App\Http\Controllers\Council\TradeController;
use App\Http\Controllers\Council\GroupController as CouncilGroupController;
use App\Http\Controllers\Council\GroupMemberController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\Public\GroupController;
use App\Http\Controllers\Public\StoryController as PublicStoryController;
use App\Http\Controllers\Public\RuleController;
use App\Http\Controllers\UserController;
use App\Http\Resources\UserResource;
use App\Http\Controllers\Council\GroupRecordController;
use App\Http\Controllers\Council\ActivityRoundController;
use App\Http\Controllers\Council\FineController;
use App\Http\Controllers\Council\ReminderController;
use App\Http\Controllers\MyGroupController;
use App\Http\Controllers\Council\StoryController;
use App\Http\Controllers\Council\DisputeController;
use App\Http\Controllers\Council\SummonController;
use App\Http\Controllers\Council\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ข้อมูลสาธารณะ: ไม่ต้องล็อกอิน
Route::prefix('public')->middleware('throttle:120,1')->group(function () {
    Route::get('rules', [RuleController::class, 'index']);
    Route::get('members', [\App\Http\Controllers\Public\MemberController::class, 'index']);
    Route::get('groups', [GroupController::class, 'index']);
    Route::get('groups/{group}', [GroupController::class, 'show']);
    Route::get('stories/{token}', [PublicStoryController::class, 'show']); // หนังสือสัญญาตามลิงก์แชร์
    Route::get('loan-contracts/{token}.png', [LoanController::class, 'publicImage'])->where('token', '[A-Za-z0-9]{40}'); // รูปหนังสือสัญญากู้ตามลิงก์
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', fn (Request $request) => new UserResource($request->user()));
    Route::post('/logout', [DiscordController::class, 'logout']);

    // จัดการผู้ใช้ (เพิ่ม Discord ID + กำหนดบทบาท): รองประธานขึ้นไป
    Route::prefix('users')->middleware(['role:vice_president,admin', 'duty', 'log.access:จัดการผู้ใช้'])->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::patch('{user}', [UserController::class, 'update']);
        Route::delete('{user}', [UserController::class, 'destroy']);
    });

    // เข้า/ออกเวรของตัวเอง: สภาขึ้นไป (ไม่ต้องอยู่ในเวรอยู่แล้ว เพราะเป็นทางเข้าเวร)
    Route::prefix('duty')->middleware('role:member')->group(function () {
        Route::get('/', [DutyController::class, 'show']);
        Route::post('start', [DutyController::class, 'start']);
        Route::post('heartbeat', [DutyController::class, 'heartbeat']);
        Route::post('end', [DutyController::class, 'end']);
    });

    // สรุปชั่วโมงเวรของสภาแต่ละคน: รองประธานขึ้นไป
    // จัดการกฎ (แก้/เพิ่ม/ซ่อน): รองประธานขึ้นไป
    Route::prefix('council/rules')->middleware(['role:vice_president', 'duty', 'log.access:จัดการกฎ'])->group(function () {
        $c = \App\Http\Controllers\Council\RuleAdminController::class;
        Route::get('/', [$c, 'index']);
        Route::post('/', [$c, 'store']);
        Route::put('settings', [$c, 'settings']);
        Route::put('{rule}', [$c, 'update']);
        Route::delete('{rule}', [$c, 'destroy']);
        Route::post('{rule}/restore', [$c, 'restore']);
    });

    // ภาพรวมหน้าแรกของสภา: สภา (Council) ขึ้นไป
    Route::get('council/overview', [\App\Http\Controllers\Council\OverviewController::class, 'show'])
        ->middleware(['role:member']);

    // แดชบอร์ดสรุปภาพรวม: รองประธานขึ้นไป
    Route::get('council/dashboard', [\App\Http\Controllers\Council\DashboardController::class, 'show'])
        ->middleware(['role:vice_president', 'log.access:แดชบอร์ด']);

    Route::prefix('council/duty-report')->middleware(['role:vice_president', 'duty', 'log.access:สรุปชั่วโมงเวร'])->group(function () {
        Route::get('/', [DutyReportController::class, 'index']);
        Route::get('{user}', [DutyReportController::class, 'show']);
    });

    // ธุรกรรมการเงินของสภา: ประธานและรองประธาน (Admin ไม่เกี่ยว)
    Route::prefix('council/finance')->middleware(['role:vice_president', 'duty', 'log.access:ธุรกรรมการเงิน'])->group(function () {
        Route::get('summary', [FinanceController::class, 'summary']);
        Route::get('entries', [FinanceController::class, 'entries']);
        Route::get('export', [FinanceController::class, 'export']);
        Route::post('records', [FinanceController::class, 'store']);
        Route::match(['put', 'patch'], 'records/{record}', [FinanceController::class, 'update']);
        Route::delete('records/{record}', [FinanceController::class, 'destroy']);

        // เงินกู้ที่สภาปล่อยให้แก๊ง/ครอบครัว
        Route::get('loans', [LoanController::class, 'index']);
        Route::post('loans', [LoanController::class, 'store']);
        Route::get('loans/{loan}', [LoanController::class, 'show']);
        // สัญญาและรายการรับคืนแก้/ลบไม่ได้ ผิดให้ยกเลิกด้วยรายการต่อท้าย
        Route::post('loans/{loan}/image', [LoanController::class, 'shareImage']);
        Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel']);
        Route::post('loans/{loan}/payments', [LoanController::class, 'storePayment']);
        Route::post('loans/{loan}/payments/{payment}/reverse', [LoanController::class, 'reversePayment']);
        Route::get('loan-settings', [LoanSettingController::class, 'show']);
        Route::put('loan-settings', [LoanSettingController::class, 'update']);

        // ซื้อ-ขายกับแก๊ง/ครอบครัว
        Route::get('trades', [TradeController::class, 'index']);
        Route::post('trades', [TradeController::class, 'store']);
        Route::match(['put', 'patch'], 'trades/{trade}', [TradeController::class, 'update']);
        Route::delete('trades/{trade}', [TradeController::class, 'destroy']);

        // โบนัสเวรของสภา: ตัดรอบ + ย้อนรอบล่าสุด (คำนวณจากชั่วโมงเวร)
        Route::get('bonus', [BonusController::class, 'index']);
        Route::post('bonus/preview', [BonusController::class, 'preview']);
        Route::post('bonus/duty-cut', [BonusController::class, 'dutyCut']);
        Route::delete('bonus/duty-cut', [BonusController::class, 'clearDutyCut']);
        Route::post('bonus/cutoff', [BonusController::class, 'cutoff']);
        Route::delete('bonus/rounds/{round}', [BonusController::class, 'destroy']);
    });

    // แจ้งเตือนงานค้าง: สภา (Council) ขึ้นไป (ไม่ log การเปิดดู เพราะแถบเมนูเรียกทุกหน้า)
    Route::get('council/alerts', [AlertController::class, 'index'])->middleware(['role:member', 'duty']);

    // ประกาศ: สภา (Council) ขึ้นไป สร้าง/แก้ไข/เผยแพร่
    // ประกาศเรียก: บันทึกการเรียกแก๊ง/ครอบครัวเข้าพบ (สภา Member ขึ้นไป)
    Route::prefix('council/summons')->middleware(['role:member', 'duty', 'log.access:ประกาศเรียก'])->group(function () {
        Route::get('/', [SummonController::class, 'index']);
        Route::post('/', [SummonController::class, 'store']);
    });

    // ประกาศปรับ: บันทึกการปรับแก๊ง/ครอบครัว (สภา Member ขึ้นไป)
    Route::prefix('council/fines')->middleware(['role:member', 'duty', 'log.access:ประกาศปรับ'])->group(function () {
        Route::get('/', [FineController::class, 'index']);
    });

    // ประกาศโทษ: รายการโทษบำเพ็ญที่บันทึกไว้ให้คัดลอกเป็นประกาศ (สภา Member ขึ้นไป)
    Route::prefix('council/penances')->middleware(['role:member', 'duty', 'log.access:ประกาศโทษ'])->group(function () {
        Route::get('/', [FineController::class, 'penances']);
        Route::post('{record}/serve', [FineController::class, 'serve']);
        Route::delete('{record}/serve', [FineController::class, 'unserve']);
    });

    Route::prefix('council/announcements')->middleware(['role:member', 'duty', 'log.access:ประกาศ'])->group(function () {
        Route::get('/', [CouncilAnnouncementController::class, 'index']);
        Route::post('/', [CouncilAnnouncementController::class, 'store']);
        Route::put('{announcement}', [CouncilAnnouncementController::class, 'update']);
        Route::delete('{announcement}', [CouncilAnnouncementController::class, 'destroy']);
    });

    // จัดการแก๊ง/ครอบครัว: สภา (Council) ขึ้นไป
    Route::prefix('council/groups')->middleware(['role:member', 'duty', 'log.access:จัดการแก๊ง/ครอบครัว'])->group(function () {
        Route::get('/', [CouncilGroupController::class, 'index']);
        Route::post('/', [CouncilGroupController::class, 'store']);
        Route::get('temp-colors', [CouncilGroupController::class, 'tempColors']); // เบอร์สีชุดชั่วคราวที่ถูกใช้ไปแล้ววันนี้
        Route::get('{group}', [CouncilGroupController::class, 'show']);
        Route::get('{group}/files/{kind}', [CouncilGroupController::class, 'file']);
        Route::get('{group}/records', [GroupRecordController::class, 'index']);
        Route::post('{group}/records', [GroupRecordController::class, 'store']);
        Route::get('{group}/summons', [SummonController::class, 'forGroup']);
        Route::get('{group}/loans', [LoanController::class, 'forGroup'])->middleware('role:vice_president');   // เงินกู้ของทะเบียน (ข้อมูลการเงิน)
        Route::patch('{group}', [CouncilGroupController::class, 'update']);
        Route::patch('{group}/status', [CouncilGroupController::class, 'updateStatus']);
        Route::patch('{group}/lock', [CouncilGroupController::class, 'updateLock']);
        Route::delete('{group}', [CouncilGroupController::class, 'destroy'])->middleware('role:vice_president');

        Route::post('{group}/members', [GroupMemberController::class, 'store']);
        Route::patch('{group}/members/{member}', [GroupMemberController::class, 'update']);
        Route::delete('{group}/members/{member}', [GroupMemberController::class, 'destroy']);
    });

    // ธุรกรรมของแก๊ง/ครอบครัว: สภา (สภาฝึกหัดขึ้นไป) ที่เข้าเวรอยู่
    // บันทึกเตือนความจำของสภา (แสดงในหน้าแจ้งเตือนงานค้าง)
    Route::prefix('council/reminders')->middleware(['role:member', 'duty', 'log.access:เตือนความจำ'])->group(function () {
        Route::post('/', [ReminderController::class, 'store']);
        Route::post('{reminder}/done', [ReminderController::class, 'done']);
        Route::delete('{reminder}', [ReminderController::class, 'destroy']);
    });

    Route::prefix('council/activity-rounds')->middleware(['role:trainee', 'duty', 'log.access:รายงานความเคลื่อนไหว'])->group(function () {
        Route::get('/', [ActivityRoundController::class, 'index']);
        Route::post('/', [ActivityRoundController::class, 'store']);
        Route::post('{round}/close', [ActivityRoundController::class, 'close']);
    });

    Route::prefix('council/transactions')->middleware(['role:trainee', 'duty', 'log.access:ธุรกรรม'])->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('{transaction}/files/{key}', [TransactionController::class, 'file'])->where('key', 'photo');
        Route::post('/', [TransactionController::class, 'store']);
        Route::get('{transaction}', [TransactionController::class, 'show']);
        Route::post('{transaction}/approve', [TransactionController::class, 'approve']);
        Route::post('{transaction}/reject', [TransactionController::class, 'reject']);
        Route::post('{transaction}/cancel', [TransactionController::class, 'cancel']);
    });

    // ส่วนของแก๊ง/ครอบครัว: ยื่นเรื่องให้สภาอนุมัติ (เปิดใช้งานเฉพาะช่วงเวลาตาม council.group_portal)
    Route::prefix('my-group')->middleware(['role:gang'])->group(function () {
        Route::get('/', [MyGroupController::class, 'show']);
        Route::middleware('group.portal')->group(function () {
            Route::post('quote', [MyGroupController::class, 'quote']);
            Route::post('requests', [MyGroupController::class, 'store']);
            Route::post('requests/{transaction}/cancel', [MyGroupController::class, 'cancel']);
        });
    });

    // สัญญาสตอรี่: สภา (สภาฝึกหัดขึ้นไป) ที่เข้าเวรอยู่
    Route::prefix('council/stories')->middleware(['role:trainee', 'duty', 'log.access:สตอรี่'])->group(function () {
        Route::get('/', [StoryController::class, 'index']);
        Route::post('/', [StoryController::class, 'store']);
        Route::get('{story}', [StoryController::class, 'show']);
        Route::match(['put', 'patch'], '{story}', [StoryController::class, 'update']);
        Route::post('{story}/score', [StoryController::class, 'score']);
        Route::post('{story}/end', [StoryController::class, 'end']);
        Route::post('{story}/cancel', [StoryController::class, 'cancel']);
        Route::post('{story}/share', [StoryController::class, 'share']);
        Route::delete('{story}/share', [StoryController::class, 'unshare']);
    });

    // ระบบบันทึกข้อพิพาท: สภาทุกยศและ Admin ดูได้ (ไม่ต้องเข้าเวร) · บันทึก/แก้ไขต้องเข้าเวร · ลบเฉพาะประธานสภา
    Route::prefix('council/disputes')->middleware(['log.access:ข้อพิพาท'])->group(function () {
        Route::middleware('role:trainee,admin')->group(function () {
            Route::get('options', [DisputeController::class, 'options']);
            Route::get('/', [DisputeController::class, 'index']);
            Route::get('{dispute}/files/{file}', [DisputeController::class, 'file']);
        });
        // สภาทุกยศ (ต้องเข้าเวร): บันทึกเรื่อง · เพิ่มภาพ/วิดีโอ · เพิ่มบันทึกเพิ่มเติม · ปิดเรื่อง (ของตัวเองเท่านั้น; รองประธานขึ้นไปปิดได้ทุกเรื่อง)
        Route::middleware(['role:trainee', 'duty'])->group(function () {
            Route::post('/', [DisputeController::class, 'store']);
            Route::post('{dispute}/files', [DisputeController::class, 'addFiles']);
            Route::post('{dispute}/notes', [DisputeController::class, 'addNote']);
            Route::post('{dispute}/status', [DisputeController::class, 'setStatus']);
        });
        // รองประธานขึ้นไป (ต้องเข้าเวร): แก้ไขรายละเอียดเรื่อง · ลบไฟล์หลักฐาน
        Route::middleware(['role:vice_president', 'duty'])->group(function () {
            Route::match(['put', 'patch', 'post'], '{dispute}', [DisputeController::class, 'update']);
            Route::delete('{dispute}/files/{file}', [DisputeController::class, 'destroyFile']);
        });
        Route::delete('{dispute}', [DisputeController::class, 'destroy'])->middleware(['role:president', 'duty']);
    });

    // บันทึกกิจกรรมทั้งหมด: รองประธานขึ้นไป (และการเปิดดูก็ถูกบันทึกด้วย)
    Route::get('/logs', [LogController::class, 'index'])
        ->middleware(['role:vice_president,admin', 'duty', 'log.access:บันทึกกิจกรรม']);
});
