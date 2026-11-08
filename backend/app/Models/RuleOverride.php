<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** ข้อกฎของเว็บทางการที่ประธาน/รองแก้ทับในหน้าเว็บ (ซิงก์ผสมให้ทุกรอบ ไม่ถูกทับ) */
#[Fillable(['code', 'fields', 'hidden', 'updated_by'])]
class RuleOverride extends Model
{
    public const FIELDS = ['title', 'body', 'note_label', 'note', 'penalty'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'hidden' => 'boolean'];
    }
}
