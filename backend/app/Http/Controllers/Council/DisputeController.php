<?php

namespace App\Http\Controllers\Council;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\DisputeFile;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ระบบบันทึกข้อพิพาท: สภาทุกยศและ Admin ดูได้ · สภาที่เข้าเวรบันทึก/แก้ไขได้ · ลบได้เฉพาะประธานสภา
 * หลักฐานแนบได้หลายรูป/หลายวิดีโอ เก็บภายในและเปิดผ่าน API ที่ตรวจสิทธิ์เท่านั้น
 */
class DisputeController extends Controller
{
    private const MAX_FILES = 10;

    private const MESSAGES = [
        'title.required' => 'กรุณาระบุหัวข้อ',
        'title.max' => 'หัวข้อยาวเกินไป (ไม่เกิน 200 ตัวอักษร)',
        'occurred_at.required' => 'กรุณาระบุวันเวลาที่เกิดขึ้น',
        'occurred_at.date' => 'วันเวลาที่เกิดขึ้นไม่ถูกต้อง',
        'details.required' => 'กรุณาบันทึกรายละเอียดเหตุการณ์',
        'details.max' => 'รายละเอียดยาวเกินไป (ไม่เกิน 5,000 ตัวอักษร)',
        'status.in' => 'สถานะไม่ถูกต้อง',
        'group_ids.required' => 'กรุณาเลือกผู้มีข้อพิพาทอย่างน้อย 1 ราย',
        'group_ids.min' => 'กรุณาเลือกผู้มีข้อพิพาทอย่างน้อย 1 ราย',
        'group_ids.*.exists' => 'ไม่พบผู้มีข้อพิพาทที่เลือก',
        'council_ids.required' => 'กรุณาเลือกสภาผู้อยู่ในเหตุการณ์อย่างน้อย 1 คน',
        'council_ids.min' => 'กรุณาเลือกสภาผู้อยู่ในเหตุการณ์อย่างน้อย 1 คน',
        'council_ids.*.exists' => 'ไม่พบสภาที่เลือก',
        'images.max' => 'แนบภาพได้ไม่เกิน 10 ไฟล์ต่อครั้ง',
        'images.*.max' => 'ภาพใหญ่เกินไป (ไม่เกิน 10 MB ต่อไฟล์)',
        'images.*.mimes' => 'ภาพต้องเป็น PNG JPG GIF หรือ WEBP',
        'images.*.file' => 'ไฟล์ภาพไม่ถูกต้อง',
        'videos.max' => 'แนบวิดีโอได้ไม่เกิน 10 ไฟล์ต่อครั้ง',
        'videos.*.max' => 'วิดีโอใหญ่เกินไป (ไม่เกิน 100 MB ต่อไฟล์)',
        'videos.*.mimes' => 'วิดีโอต้องเป็น MP4 MOV หรือ WEBM',
        'videos.*.file' => 'ไฟล์วิดีโอไม่ถูกต้อง',
    ];

    private const WITH = ['groups', 'council', 'creator:id,name,character_name', 'files'];

    /** ตัวเลือกในฟอร์ม: ทะเบียนทั้งหมด และสภาทุกยศที่ยังใช้งานอยู่ */
    public function options(): JsonResponse
    {
        $groups = Group::query()->orderBy('name')->get()->map(fn (Group $g) => $this->group($g))->values();

        $council = User::query()->whereNull('removed_at')->get()
            ->filter(fn (User $u) => $u->role->isCouncil())
            ->sortBy(fn (User $u) => [-$u->role->sortWeight(), mb_strtolower($u->character_name ?: $u->name)])
            ->map(fn (User $u) => $this->person($u))->values();

        return response()->json(['groups' => $groups, 'council' => $council]);
    }

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(array_keys(Dispute::STATUSES))],
        ]);

        $query = Dispute::query()->with(self::WITH)
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(trim((string) ($f['q'] ?? '')) !== '', function ($q) use ($f) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($f['q'])).'%';
                $op = $q->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
                $q->where(fn ($w) => $w->where('title', $op, $like)->orWhere('details', $op, $like)->orWhere('number', $op, $like)
                    ->orWhereHas('groups', fn ($g) => $g->where('name', $op, $like)));
            })
            ->orderByDesc('occurred_at')->orderByDesc('id');

        $page = $query->paginate($f['per_page'] ?? 10);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Dispute $d) => $this->row($d))->values(),
            'meta' => [
                'total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'open' => Dispute::query()->where('status', 'open')->count(),
            ],
            'statuses' => collect(Dispute::STATUSES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate($this->rules(true), self::MESSAGES);

        $dispute = Dispute::create([
            'title' => trim($d['title']),
            'details' => trim($d['details']),
            'occurred_at' => $this->when($d['occurred_at']),
            'status' => $d['status'] ?? 'open',
            'created_by' => $request->user()->id,
        ]);
        $dispute->groups()->sync($d['group_ids']);
        $dispute->council()->sync($d['council_ids']);
        $this->attach($request, $dispute);

        return response()->json(['data' => $this->row($dispute->load(self::WITH))], 201);
    }

    public function update(Request $request, Dispute $dispute): JsonResponse
    {
        $d = $request->validate($this->rules(false), self::MESSAGES);

        $dispute->fill(collect($d)->only(['title', 'details', 'status'])->map(fn ($v) => is_string($v) ? trim($v) : $v)->all());
        if (isset($d['occurred_at'])) {
            $dispute->occurred_at = $this->when($d['occurred_at']);
        }
        $dispute->save();
        if (isset($d['group_ids'])) {
            $dispute->groups()->sync($d['group_ids']);
        }
        if (isset($d['council_ids'])) {
            $dispute->council()->sync($d['council_ids']);
        }
        $this->attach($request, $dispute);

        return response()->json(['data' => $this->row($dispute->load(self::WITH))]);
    }

    /** ลบได้เฉพาะประธานสภา (ที่ route) · ไฟล์หลักฐานถูกลบด้วย */
    public function destroy(Dispute $dispute): JsonResponse
    {
        foreach ($dispute->files as $file) {
            Storage::disk('local')->delete($file->path);
        }
        $dispute->files()->delete();
        $dispute->delete();

        return response()->json(['message' => 'ลบข้อพิพาทแล้ว']);
    }

    /** เปิดไฟล์หลักฐาน (ต้องล็อกอินและมีสิทธิ์ดูข้อพิพาท) */
    public function file(Dispute $dispute, DisputeFile $file)
    {
        abort_unless($file->dispute_id === $dispute->id && Storage::disk('local')->exists($file->path), 404);

        return Storage::disk('local')->response($file->path, $file->name, [
            'Content-Type' => $file->mime ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyFile(Request $request, Dispute $dispute, DisputeFile $file): JsonResponse
    {
        abort_unless($file->dispute_id === $dispute->id, 404);
        Storage::disk('local')->delete($file->path);
        $file->delete();
        activity()->causedBy($request->user())->performedOn($dispute)->event('updated')->log('ลบไฟล์หลักฐาน '.$file->name);

        return response()->json(['data' => $this->row($dispute->load(self::WITH))]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $councilIds = User::query()->whereNull('removed_at')->get()->filter(fn (User $u) => $u->role->isCouncil())->pluck('id')->all();

        return [
            'title' => [$req, 'string', 'max:200'],
            'occurred_at' => [$req, 'date'],
            'details' => [$req, 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(array_keys(Dispute::STATUSES))],
            'group_ids' => [$req, 'array', 'min:1'],
            'group_ids.*' => ['integer', Rule::exists('groups', 'id')->whereNull('deleted_at')],
            'council_ids' => [$req, 'array', 'min:1'],
            'council_ids.*' => ['integer', Rule::in($councilIds)],
            'images' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'images.*' => ['file', 'max:10240', 'mimes:png,jpg,jpeg,gif,webp'],
            'videos' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'videos.*' => ['file', 'max:102400', 'mimes:mp4,mov,webm'],
        ];
    }

    /** วันเวลาที่ผู้ใช้กรอก (เวลาไทย ถ้าไม่ระบุโซน) → เวลาของระบบ */
    private function when(string $value): Carbon
    {
        return Carbon::parse($value, config('council.report_timezone'))->setTimezone(config('app.timezone'));
    }

    private function attach(Request $request, Dispute $dispute): void
    {
        $count = 0;
        foreach (['image' => 'images', 'video' => 'videos'] as $kind => $field) {
            foreach ((array) $request->file($field, []) as $upload) {
                $path = Storage::disk('local')->putFileAs("disputes/{$dispute->id}", $upload, Str::random(24).'.'.$upload->extension());
                $dispute->files()->create([
                    'kind' => $kind,
                    'path' => $path,
                    'name' => Str::limit($upload->getClientOriginalName(), 150, ''),
                    'mime' => $upload->getMimeType(),
                    'size' => $upload->getSize(),
                    'uploaded_by' => $request->user()->id,
                ]);
                $count++;
            }
        }
        if ($count > 0) {
            activity()->causedBy($request->user())->performedOn($dispute)->event('updated')->log("แนบไฟล์หลักฐาน {$count} ไฟล์");
        }
    }

    /** @return array<string, mixed> */
    private function row(Dispute $d): array
    {
        return [
            'id' => $d->id, 'number' => $d->number,
            'title' => $d->title, 'details' => $d->details,
            'status' => $d->status, 'status_label' => Dispute::STATUSES[$d->status] ?? $d->status,
            'occurred_at' => $d->occurred_at?->toIso8601String(),
            'groups' => $d->groups->map(fn (Group $g) => $this->group($g))->values(),
            'council' => $d->council->map(fn (User $u) => $this->person($u))->values(),
            'by' => $d->creator ? ($d->creator->character_name ?: $d->creator->name) : '',
            'created_at' => $d->created_at?->toIso8601String(),
            'files' => $d->files->map(fn (DisputeFile $f) => [
                'id' => $f->id, 'kind' => $f->kind, 'name' => $f->name, 'mime' => $f->mime, 'size' => $f->size,
                'url' => "/api/council/disputes/{$d->id}/files/{$f->id}",
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function group(Group $g): array
    {
        $kind = $g->type->value === 'family' ? 'family' : ($g->is_lady ? 'lady' : 'gang');

        return ['id' => $g->id, 'name' => $g->name, 'short_name' => $g->short_name, 'kind' => $kind, 'kind_label' => ['gang' => 'แก๊ง', 'lady' => 'แก๊งหญิง', 'family' => 'ครอบครัว'][$kind], 'deleted' => $g->trashed()];
    }

    /** @return array<string, mixed> */
    private function person(User $u): array
    {
        return ['id' => $u->id, 'name' => $u->character_name ?: $u->name, 'role_label' => $u->role->thaiName()];
    }
}
