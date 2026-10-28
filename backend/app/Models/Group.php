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
    'temp_outfit_path', 'temp_outfit_male', 'temp_outfit_female', 'outfit_zip_path',
    'protect_requested', 'protect_until',
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
            'protect_until' => 'datetime',
        ];
    }

    /** @return array{main: int, reserve: int} จำนวนตัวจริง/ตัวสำรองสูงสุด */
    public function slotLimits(): array
    {
        $key = $this->type === GroupType::Family ? 'family' : ($this->is_lady ? 'lady' : 'gang');

        return config("council.groups.slots.{$key}");
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

    public function statusHistory(): HasMany
    {
        return $this->hasMany(GroupStatusHistory::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
