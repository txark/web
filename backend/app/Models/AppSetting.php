<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** ค่าตั้งค่าทั่วไปแบบ key-value (ต่างจาก config/env ตรงที่แก้ผ่านหน้าเว็บได้) */
#[Fillable(['key', 'value'])]
class AppSetting extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! Schema::hasTable('app_settings')) {
            return $default;
        }
        $row = static::query()->find($key);

        return $row ? ($row->value['v'] ?? $default) : $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
    }

    /** ซิงก์กฎจากเว็บทางการอัตโนมัติ: ค่าที่ตั้งในหน้าเว็บมาก่อน ไม่งั้นใช้ค่า env */
    public static function rulesAutoSync(): bool
    {
        return (bool) static::get('rules_auto_sync', config('council.rules_sync.enabled'));
    }
}
