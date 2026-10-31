<?php

namespace App\Http\Controllers\Council;

use App\Enums\StoryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoryResource;
use App\Models\Story;
use App\Services\StoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** สัญญาสตอรี่: ดูรายการ / สร้าง / แก้ไข / บันทึกคะแนน / จบแบบพิเศษ / ยกเลิก (ตรรกะและการตรวจกติกาอยู่ใน StoryService) */
class StoryController extends Controller
{
    public function __construct(private StoryService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->service->syncLocks();

        $f = $request->validate([
            'status' => ['nullable', Rule::in(['waiting', 'playing', 'ended', 'cancelled'])],
            'group_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'], // วันที่นัดเล่นตั้งแต่ (เวลาไทย)
            'to' => ['nullable', 'date_format:Y-m-d'],   // ถึงวันที่ (รวมวันนั้นทั้งวัน)
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = Story::query()
            ->with(['groupA', 'groupB', 'starter', 'ender'])
            ->when($f['status'] ?? null, fn ($q, $v) => match ($v) {
                'waiting' => $q->active()->where('starts_at', '>', now()),
                'playing' => $q->active()->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now())),
                'ended' => $q->where('status', StoryStatus::Ended->value),
                default => $q->where('status', StoryStatus::Cancelled->value),
            })
            ->when($f['from'] ?? null, fn ($q, $d) => $q->where('starts_at', '>=', \Illuminate\Support\Carbon::parse($d, config('council.report_timezone'))->startOfDay()->utc()))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->where('starts_at', '<=', \Illuminate\Support\Carbon::parse($d, config('council.report_timezone'))->endOfDay()->utc()))
            ->when($f['group_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where('group_a_id', $id)->orWhere('group_b_id', $id)))
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->whereLike('number', "%{$term}%")
                ->orWhereLike('play_format', "%{$term}%")
                ->orWhereHas('groupA', fn ($g) => $g->withTrashed()->whereLike('name', "%{$term}%"))
                ->orWhereHas('groupB', fn ($g) => $g->withTrashed()->whereLike('name', "%{$term}%"))))
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate($f['per_page'] ?? 50)
            ->withQueryString();

        $cfg = config('council.story');

        return StoryResource::collection($items)->additional([
            'counts' => [
                'waiting' => Story::active()->where('starts_at', '>', now())->count(),
                'playing' => Story::active()->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->count(),
                'ended' => Story::where('status', StoryStatus::Ended->value)->count(),
                'all' => Story::where('status', '!=', StoryStatus::Cancelled->value)->count(),
            ],
            'today' => ['count' => $this->service->countOnDay(now()), 'limit' => (int) $cfg['daily_limit']],
            'options' => [
                'protect_days' => $cfg['protect_days'],
                'stake_min' => $cfg['stake_min'],
                'stake_max' => $cfg['stake_max'],
                'stake_enforce' => $cfg['stake_enforce'],
                'stake_cap' => $cfg['stake_cap'],
                'stake_presets' => $cfg['stake_presets'],
                'formats' => $cfg['formats'],
                'venues' => $cfg['venues'],
                'ticket_options' => $cfg['ticket_options'],
                'fights_options' => $cfg['fights_options'],
                'fights_custom' => $cfg['fights_custom'],
                'fights_min' => $cfg['fights_min'],
                'fights_max' => $cfg['fights_max'],
                'lock_minutes' => $cfg['lock_minutes'],
            ],
        ]);
    }

    public function show(Story $story): StoryResource
    {
        $this->service->syncLocks();

        return new StoryResource($story->refresh()->load(['groupA', 'groupB', 'starter', 'ender']));
    }

    public function store(Request $request): JsonResponse
    {
        return (new StoryResource($this->service->create($request->user(), $request->all())))->response()->setStatusCode(201);
    }

    public function update(Request $request, Story $story): StoryResource
    {
        return new StoryResource($this->service->update($request->user(), $story, $request->all()));
    }

    public function score(Request $request, Story $story): StoryResource
    {
        return new StoryResource($this->service->score($request->user(), $story, $request->all()));
    }

    public function end(Request $request, Story $story): StoryResource
    {
        return new StoryResource($this->service->end($request->user(), $story, $request->all()));
    }

    /** สร้าง (หรือใช้ซ้ำ) โทเคนลิงก์แชร์หนังสือสัญญา: ใครมีลิงก์เปิดอ่านได้โดยไม่ต้องล็อกอิน */
    public function share(Request $request, Story $story): JsonResponse
    {
        abort_unless($request->user()->atLeast(\App\Enums\Role::Trainee) && ! $request->user()->role->isAdmin(), 403, 'เฉพาะสภาเท่านั้นที่แชร์หนังสือสัญญาได้');

        if (! $story->share_token) {
            $story->update(['share_token' => Str::random(40)]);
            activity('stories')->causedBy($request->user())->performedOn($story)->event('shared')
                ->withProperties(['number' => $story->number])->log("สร้างลิงก์แชร์หนังสือสัญญา {$story->number}");
        }

        return response()->json(['token' => $story->share_token]);
    }

    /** ยกเลิกลิงก์แชร์ (ลิงก์เดิมใช้ไม่ได้อีก) */
    public function unshare(Request $request, Story $story): JsonResponse
    {
        abort_unless($request->user()->atLeast(\App\Enums\Role::Trainee) && ! $request->user()->role->isAdmin(), 403, 'เฉพาะสภาเท่านั้น');

        if ($story->share_token) {
            $story->update(['share_token' => null]);
            activity('stories')->causedBy($request->user())->performedOn($story)->event('unshared')
                ->withProperties(['number' => $story->number])->log("ยกเลิกลิงก์แชร์หนังสือสัญญา {$story->number}");
        }

        return response()->json(['revoked' => true]);
    }

    public function cancel(Request $request, Story $story): StoryResource
    {
        return new StoryResource($this->service->cancel($request->user(), $story, (string) $request->input('reason', '')));
    }
}
