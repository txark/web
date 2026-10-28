<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'discord_id', 'avatar', 'character_name', 'role', 'disabled_at', 'last_login_at', 'added_by'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, LogsActivity, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function atLeast(Role $min): bool
    {
        return $this->role->atLeast($min);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'added_by');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'character_name', 'role', 'disabled_at'])
            ->logOnlyDirty();
    }
}
