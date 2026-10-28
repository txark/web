<?php

namespace App\Http\Resources;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Rule;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \Spatie\Activitylog\Models\Activity */
class ActivityResource extends JsonResource
{
    private const LOG_LABELS = [
        'auth' => 'เข้า/ออกระบบ',
        'access' => 'การเข้าดู',
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
    ];

    private const SUBJECT_LABELS = [
        User::class => 'ผู้ใช้',
        Group::class => 'แก๊ง/ครอบครัว',
        GroupMember::class => 'สมาชิกแก๊ง',
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

    public function toArray(Request $request): array
    {
        $causer = $this->causer;

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
            'changes' => $this->attribute_changes,
            'properties' => $this->properties,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
