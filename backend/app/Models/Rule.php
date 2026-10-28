<?php

namespace App\Models;

use App\Enums\RuleSection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable([
    'section', 'sort_order', 'kind', 'title', 'body',
    'note_label', 'note', 'penalty', 'is_published',
])]
class Rule extends Model
{
    use LogsActivity;

    /** rule = ข้อกฎที่มีเลขกำกับ, text = ย่อหน้า, heading = หัวข้อย่อย, highlight = กล่องเน้น */
    public const KINDS = ['rule', 'text', 'heading', 'highlight'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'section' => RuleSection::class,
            'is_published' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty();
    }
}
