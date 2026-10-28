<?php

namespace App\Http\Controllers\Public;

use App\Enums\RuleSection;
use App\Http\Controllers\Controller;
use App\Http\Resources\RuleResource;
use App\Models\Rule;
use App\Models\RuleSectionMeta;
use Illuminate\Http\JsonResponse;

class RuleController extends Controller
{
    /** กฎทั้งหมดที่เผยแพร่ จัดกลุ่มตามหมวด เรียงตามลำดับที่ประกาศ */
    public function index(): JsonResponse
    {
        $rules = Rule::query()
            ->where('is_published', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Rule $r) => $r->section->value);

        $checked = RuleSectionMeta::query()->pluck('source_checked_at', 'section');

        $sections = collect(RuleSection::cases())
            ->filter(fn (RuleSection $s) => $rules->has($s->value))
            ->map(fn (RuleSection $s) => [
                'key' => $s->value,
                'label' => $s->label(),
                'group' => $s->group(),
                'source_url' => $s->sourceUrl(),
                'source_checked_at' => $checked->get($s->value)?->toDateString(),
                'rules' => RuleResource::collection($rules[$s->value]),
            ])
            ->values();

        return response()->json(['data' => $sections]);
    }
}
