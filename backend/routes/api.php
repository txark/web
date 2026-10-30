<?php

use App\Http\Controllers\Auth\DiscordController;
use App\Http\Controllers\Council\AlertController;
use App\Http\Controllers\Council\AnnouncementController as CouncilAnnouncementController;
use App\Http\Controllers\Council\DutyController;
use App\Http\Controllers\Council\DutyReportController;
use App\Http\Controllers\Council\GroupController as CouncilGroupController;
use App\Http\Controllers\Council\GroupMemberController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\Public\GroupController;
use App\Http\Controllers\Public\RuleController;
use App\Http\Controllers\UserController;
use App\Http\Resources\UserResource;
use App\Http\Controllers\Council\GroupRecordController;
use App\Http\Controllers\Council\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ข้อมูลสาธารณะ: ไม่ต้องล็อกอิน
Route::prefix('public')->middleware('throttle:120,1')->group(function () {
    Route::get('rules', [RuleController::class, 'index']);
    Route::get('groups', [GroupController::class, 'index']);
    Route::get('groups/{group}', [GroupController::class, 'show']);
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
    Route::prefix('council/duty-report')->middleware(['role:vice_president', 'duty', 'log.access:สรุปชั่วโมงเวร'])->group(function () {
        Route::get('/', [DutyReportController::class, 'index']);
        Route::get('{user}', [DutyReportController::class, 'show']);
    });

    // แจ้งเตือนงานค้าง: สภา (Council) ขึ้นไป (ไม่ log การเปิดดู เพราะแถบเมนูเรียกทุกหน้า)
    Route::get('council/alerts', [AlertController::class, 'index'])->middleware(['role:member', 'duty']);

    // ประกาศ: สภา (Council) ขึ้นไป สร้าง/แก้ไข/เผยแพร่
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
        Route::get('{group}', [CouncilGroupController::class, 'show']);
        Route::get('{group}/files/{kind}', [CouncilGroupController::class, 'file']);
        Route::get('{group}/records', [GroupRecordController::class, 'index']);
        Route::post('{group}/records', [GroupRecordController::class, 'store']);
        Route::patch('{group}', [CouncilGroupController::class, 'update']);
        Route::patch('{group}/status', [CouncilGroupController::class, 'updateStatus']);
        Route::patch('{group}/lock', [CouncilGroupController::class, 'updateLock']);
        Route::delete('{group}', [CouncilGroupController::class, 'destroy'])->middleware('role:vice_president');

        Route::post('{group}/members', [GroupMemberController::class, 'store']);
        Route::patch('{group}/members/{member}', [GroupMemberController::class, 'update']);
        Route::delete('{group}/members/{member}', [GroupMemberController::class, 'destroy']);
    });

    // ธุรกรรมของแก๊ง/ครอบครัว: สภา (สภาฝึกหัดขึ้นไป) ที่เข้าเวรอยู่
    Route::prefix('council/transactions')->middleware(['role:trainee', 'duty', 'log.access:ธุรกรรม'])->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::post('/', [TransactionController::class, 'store']);
        Route::get('{transaction}', [TransactionController::class, 'show']);
        Route::post('{transaction}/approve', [TransactionController::class, 'approve']);
        Route::post('{transaction}/reject', [TransactionController::class, 'reject']);
        Route::post('{transaction}/cancel', [TransactionController::class, 'cancel']);
    });

    // บันทึกกิจกรรมทั้งหมด: รองประธานขึ้นไป (และการเปิดดูก็ถูกบันทึกด้วย)
    Route::get('/logs', [LogController::class, 'index'])
        ->middleware(['role:vice_president,admin', 'duty', 'log.access:บันทึกกิจกรรม']);
});
