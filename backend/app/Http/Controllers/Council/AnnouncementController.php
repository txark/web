<?php

namespace App\Http\Controllers\Council;

use App\Enums\AnnouncementType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\AnnouncementAdminResource;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** สร้าง/แก้ไข/เผยแพร่ประกาศของสภา (Council ขึ้นไป; ลบ = ผู้สร้างหรือรองประธานขึ้นไป) */
class AnnouncementController extends Controller
{
    private const MESSAGES = [
        'title.required' => 'กรุณากรอกหัวข้อ',
        'title.max' => 'หัวข้อยาวเกินไป (ไม่เกิน 150 ตัวอักษร)',
        'body.required' => 'กรุณากรอกเนื้อหา',
        'body.max' => 'เนื้อหายาวเกินไป (ไม่เกิน 5,000 ตัวอักษร)',
        'type.required' => 'กรุณาเลือกประเภท',
        'type.enum' => 'ประเภทไม่ถูกต้อง',
        'group_id.exists' => 'ไม่พบแก๊ง/ครอบครัวที่เลือก',
        'due_at.date' => 'วันเวลาไม่ถูกต้อง',
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'type' => ['nullable', Rule::enum(AnnouncementType::class)],
            'status' => ['nullable', Rule::in(['published', 'draft'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $items = Announcement::query()
            ->with(['group', 'author'])
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('is_published', $v === 'published'))
            ->orderByDesc('id')
            ->paginate($f['per_page'] ?? 30)
            ->withQueryString();

        return AnnouncementAdminResource::collection($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $data['published_at'] = $data['is_published'] ? now() : null;

        $a = Announcement::create($data);

        return (new AnnouncementAdminResource($a->load(['group', 'author'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, Announcement $announcement): AnnouncementAdminResource
    {
        $data = $this->validated($request);

        // เผยแพร่ครั้งแรก หรือถอนเป็นฉบับร่างแล้วเผยแพร่ใหม่ → ถือเป็นเวลาเผยแพร่ใหม่
        if ($data['is_published'] && ! $announcement->is_published) {
            $data['published_at'] = now();
        }

        $announcement->update($data);

        return new AnnouncementAdminResource($announcement->fresh()->load(['group', 'author']));
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $user = $request->user();
        if ($announcement->created_by !== $user->id && ! $user->atLeast(Role::VicePresident)) {
            abort(403, 'ลบได้เฉพาะประกาศที่คุณสร้าง (หรือรองประธานขึ้นไป)');
        }

        $announcement->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(AnnouncementType::class)],
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id,deleted_at,NULL'],
            'due_at' => ['nullable', 'date'],
            'is_published' => ['required', 'boolean'],
        ], self::MESSAGES);

        $data['group_id'] = $data['group_id'] ?? null;
        $data['due_at'] = $data['due_at'] ?? null;

        return $data;
    }
}
