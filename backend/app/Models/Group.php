<?php

namespace App\Models;

use App\Enums\GroupStatus;
use App\Enums\GroupType;
use App\Enums\MemberPosition;
use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'type', 'is_lady', 'name', 'description', 'logo_url',
    'status', 'status_reason', 'status_changed_at',
    'slots_locked', 'locked_reason',
    'registered_at', 'renamed_at',
    'short_name', 'color_hex', 'color_name', 'declared_members', 'logo_path',
    'temp_outfit_path', 'temp_outfit_male', 'temp_outfit_female', 'temp_outfit_color', 'outfit_zip_path', 'outfit_zip_original_path',
    'protect_requested', 'protect_from', 'protect_until', 'outfit_counts',
])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory, LogsActivity, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => GroupType::class,
            'is_lady' => 'boolean',
            'status' => GroupStatus::class,
            'status_changed_at' => 'datetime',
            'slots_locked' => 'boolean',
            'registered_at' => 'datetime',
            'renamed_at' => 'datetime',
            'protect_requested' => 'boolean',
            'protect_from' => 'datetime',
            'outfit_counts' => 'array',
            'protect_until' => 'datetime',
        ];
    }

    /** @return array{main: int, reserve: int} จำนวนตัวจริง/ตัวสำรองสูงสุด */
    public function slotLimits(): array
    {
        $key = $this->type === GroupType::Family ? 'family' : ($this->is_lady ? 'lady' : 'gang');

        return config("council.groups.slots.{$key}");
    }

    /** จำนวนรองหัวหน้าสูงสุด: แก๊ง 2 / ครอบครัว 1 */
    public function maxCoLeaders(): int
    {
        return self::coLeaderLimit($this->type);
    }

    public static function coLeaderLimit(GroupType $type): int
    {
        return (int) config($type === GroupType::Family ? 'council.groups.max_co_leaders_family' : 'council.groups.max_co_leaders');
    }

    /**
     * สิทธิ์ลงชุดฟรีของแก๊ง/ครอบครัวที่เพิ่งขึ้นทะเบียน: ภายใน outfit_free_hours ชั่วโมงหลังขึ้นทะเบียน
     * และใช้ได้ครั้งเดียว — ส่งไฟล์ชุดมาแล้ว 1 ครั้ง (ธุรกรรมชุดที่รออนุมัติหรืออนุมัติแล้ว) สิทธิ์หายทันที
     * ธุรกรรมที่ถูกปฏิเสธ/ยกเลิกไม่นับ (ไฟล์ที่แนบตอนขึ้นทะเบียนไม่นับเป็นการใช้สิทธิ์)
     */
    public function outfitFreeAvailable(): bool
    {
        $hours = (int) config('council.fees.outfit_free_hours');
        if ($hours <= 0 || ! $this->registered_at || ! $this->registered_at->copy()->addHours($hours)->isFuture()) {
            return false;
        }

        return ! \App\Models\Transaction::query()
            ->where('group_id', $this->id)
            ->whereIn('kind', [\App\Enums\TransactionKind::Outfit->value, \App\Enums\TransactionKind::OutfitAdd->value, \App\Enums\TransactionKind::OutfitFile->value])
            ->whereIn('status', [\App\Enums\TransactionStatus::Pending->value, \App\Enums\TransactionStatus::Approved->value])
            ->exists();
    }

    /** @return array<string, int> ชนิดชุด => จำนวนสูงสุดที่ลงได้ */
    public function outfitLimits(): array
    {
        return (array) config('council.groups.outfit_limits.'.($this->type === GroupType::Family ? 'family' : 'gang'));
    }

    /** ตรวจจำนวนชุดที่จะเก็บ (ชนิด => จำนวน) ไม่เกินลิมิตของประเภทนี้ คืนข้อความผิดพลาดต่อชนิด */
    public function outfitLimitErrors(array $counts): array
    {
        $errors = [];
        foreach ($this->outfitLimits() as $key => $limit) {
            if (isset($counts[$key]) && (int) $counts[$key] > $limit) {
                $errors["counts.{$key}"] = "ลงได้สูงสุด {$limit} ชิ้น";
            }
        }

        return $errors;
    }

    public function minMembers(): int
    {
        return config("council.groups.min_members.{$this->type->value}");
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    public function leader(): HasOne
    {
        return $this->hasOne(GroupMember::class)
            ->where('position', MemberPosition::Leader->value)
            ->whereNull('left_at');
    }

    public function records(): HasMany
    {
        return $this->hasMany(GroupRecord::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(GroupStatusHistory::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
