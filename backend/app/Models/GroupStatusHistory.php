<?php

namespace App\Models;

use App\Enums\GroupStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['group_id', 'status', 'reason', 'changed_by', 'started_at', 'ended_at'])]
class GroupStatusHistory extends Model
{
    protected $table = 'group_status_history';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GroupStatus::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
