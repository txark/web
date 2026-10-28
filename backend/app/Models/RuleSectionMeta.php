<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['section', 'source_checked_at'])]
class RuleSectionMeta extends Model
{
    protected $table = 'rule_sections';

    protected $primaryKey = 'section';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['source_checked_at' => 'date'];
    }
}
