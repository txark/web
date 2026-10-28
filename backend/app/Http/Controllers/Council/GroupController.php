<?php

namespace App\Http\Controllers\Council;

use App\Enums\GroupStatus;
use App\Enums\GroupType;
use App\Enums\MemberPosition;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupAdminResource;
use App\Models\Group;
use App\Services\GroupRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

/** จัดการแก๊ง/ครอบครัวโดยสภา (ตั้งแต่ Council ขึ้นไป; ลบกลุ่ม = รองประธานขึ้นไป) */
class GroupController extends Controller
{
    private const NAME_RULE = ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._\-]*$/'];

    private const MESSAGES = [
        'name.required' => 'กรุณากรอกชื่อ',
        'name.max' => 'ชื่อต้องยาวไม่เกิน 20 ตัวอักษร',
        'name.regex' => 'ชื่อต้องเป็นภาษาอังกฤษ/ตัวเลขเท่านั้น',
        'type.required' => 'กรุณาเลือกประเภท',
        'type.enum' => 'ประเภทไม่ถูกต้อง',
        'status.enum' => 'สถานะไม่ถูกต้อง',
        'reason.required_unless' => 'กรุณาระบุเหตุผล',
        'logo_url.url' => 'ลิงก์โลโก้ไม่ถูกต้อง',
    ];

    public function __construct(private GroupRules $rules) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'type' => ['nullable', Rule::enum(GroupType::class)],
            'status' => ['nullable', Rule::enum(GroupStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $groups = Group::query()
            ->with('leader')
            ->withCount('activeMembers')
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->whereLike('name', "%{$v}%"))
            ->orderBy('name')
            ->paginate($f['per_page'] ?? 30)
            ->withQueryString();

        return GroupAdminResource::collection($groups);
    }

    public function show(Group $group): GroupAdminResource
    {
        return new GroupAdminResource($this->full($group));
    }

    /** ขึ้นทะเบียนแก๊ง/ครอบครัว (multipart: โลโก้ PNG, ชุดชั่วคราว PNG, ไฟล์ชุด ZIP) */
    public function store(Request $request): JsonResponse
    {
        $type = GroupType::tryFrom((string) $request->input('type'));
        $isLady = $type === GroupType::Gang && $request->boolean('is_lady');
        $key = $type === GroupType::Family ? 'family' : ($isLady ? 'lady' : 'gang');
        $min = $type ? (int) config("council.groups.min_members.{$type->value}") : 1;
        $slots = config("council.groups.slots.{$key}");
        $max = $slots['main'] + $slots['reserve'];
        $maxCo = (int) config('council.groups.max_co_leaders');

        $person = fn (string $p) => [
            "{$p}.ic_name" => ['required', 'string', 'max:120'],
            "{$p}.ic_phone" => ['nullable', 'string', 'max:30', 'regex:/^[0-9 \-+]+$/'],
            "{$p}.discord_id" => ['required', 'regex:/^\d{17,20}$/'],
        ];

        $data = $request->validate([
            'type' => ['required', Rule::enum(GroupType::class)],
            'is_lady' => ['sometimes', 'boolean'],
            'declared_members' => ['required', 'integer', "min:{$min}", "max:{$max}"],
            'name' => self::NAME_RULE,
            'short_name' => ['required', 'string', 'max:4', 'regex:/^[A-Za-z0-9]+$/'],
            'color_hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_name' => ['required', 'string', 'max:60'],
            'logo' => ['required', 'file', 'max:2048', $this->signature('png')],
            'leader' => ['required', 'array'],
            ...$person('leader'),
            'co_leaders' => ['required', 'array', 'min:1', "max:{$maxCo}"],
            'co_leaders.*' => ['array'],
            ...$person('co_leaders.*'),
            'temp_outfit_image' => ['required', 'file', 'max:4096', $this->signature('png')],
            'temp_outfit_male' => ['nullable', 'string', 'max:30', 'required_without:temp_outfit_female'],
            'temp_outfit_female' => ['nullable', 'string', 'max:30', 'required_without:temp_outfit_male'],
            'outfit_zip' => ['required', 'file', 'max:51200', $this->signature('zip')],
            'protect' => ['sometimes', 'boolean'],
        ], self::MESSAGES + [
            'declared_members.min' => "ต้องมีสมาชิกอย่างน้อย {$min} คน",
            'declared_members.max' => "สมาชิกสูงสุด {$max} คน",
            'short_name.regex' => 'ชื่อย่อต้องเป็นภาษาอังกฤษ/ตัวเลข ไม่เกิน 4 ตัวอักษร',
            'color_hex.regex' => 'โค้ดสีต้องเป็นรูปแบบ #RRGGBB',
            'co_leaders.min' => 'ต้องมีรองอย่างน้อย 1 คน',
            'co_leaders.max' => "มีรองได้ไม่เกิน {$maxCo} คน",
            '*.discord_id.regex' => 'Discord ID ต้องเป็นตัวเลข 17-20 หลัก',
            '*.ic_phone.regex' => 'เบอร์ IC ไม่ถูกต้อง',
            'temp_outfit_male.required_without' => 'กรอกเลขชุดอย่างน้อยฝั่งชายหรือหญิง',
            'temp_outfit_female.required_without' => 'กรอกเลขชุดอย่างน้อยฝั่งชายหรือหญิง',
        ]);

        $this->rules->assertValidName($type, $data['name']);

        $people = [$data['leader'], ...$data['co_leaders']];
        $names = array_map(fn ($p) => mb_strtolower(trim($p['ic_name'])), $people);
        if (count(array_unique($names)) !== count($names)) {
            throw ValidationException::withMessages(['co_leaders' => 'ชื่อหัวหน้าและรองต้องไม่ซ้ำกัน']);
        }

        $stored = [];
        try {
            $group = DB::transaction(function () use ($data, $type, $isLady, $request, $people, &$stored) {
                $logo = $request->file('logo')->store('groups/logos', 'public');
                $stored[] = ['public', $logo];
                $temp = $request->file('temp_outfit_image')->store('groups/temp-outfits', 'local');
                $stored[] = ['local', $temp];
                $zip = $request->file('outfit_zip')->store('groups/outfits', 'local');
                $stored[] = ['local', $zip];
                $protect = $request->boolean('protect');

                $group = Group::create([
                    'type' => $type,
                    'is_lady' => $isLady,
                    'name' => $data['name'],
                    'short_name' => strtoupper($data['short_name']),
                    'color_hex' => strtoupper($data['color_hex']),
                    'color_name' => $data['color_name'],
                    'declared_members' => $data['declared_members'],
                    'logo_path' => $logo,
                    'logo_url' => Storage::disk('public')->url($logo),
                    'temp_outfit_path' => $temp,
                    'temp_outfit_male' => $data['temp_outfit_male'] ?? null,
                    'temp_outfit_female' => $data['temp_outfit_female'] ?? null,
                    'outfit_zip_path' => $zip,
                    'protect_requested' => $protect,
                    'protect_until' => $protect ? now()->addDays((int) config('council.groups.protect_days')) : null,
                    'status' => GroupStatus::Normal,
                    'status_changed_at' => now(),
                    'registered_at' => now(),
                ]);

                $group->statusHistory()->create([
                    'status' => GroupStatus::Normal,
                    'reason' => 'ขึ้นทะเบียน',
                    'changed_by' => $request->user()->id,
                    'started_at' => now(),
                ]);

                foreach ($people as $i => $p) {
                    $group->members()->create([
                        'character_name' => trim($p['ic_name']),
                        'ic_phone' => $p['ic_phone'] ?? null,
                        'discord_id' => $p['discord_id'],
                        'position' => $i === 0 ? MemberPosition::Leader : MemberPosition::CoLeader,
                        'joined_at' => now(),
                    ]);
                }

                return $group;
            });
        } catch (\Throwable $e) {
            foreach ($stored as [$disk, $path]) {
                Storage::disk($disk)->delete($path);
            }
            throw $e;
        }

        return (new GroupAdminResource($this->full($group)))->response()->setStatusCode(201);
    }

    /** ดาวน์โหลดไฟล์ส่วนตัวของกลุ่ม (ชุดชั่วคราว PNG / ไฟล์ชุด ZIP) */
    public function file(Group $group, string $kind)
    {
        $path = match ($kind) {
            'temp_outfit' => $group->temp_outfit_path,
            'outfit_zip' => $group->outfit_zip_path,
            default => null,
        };
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';

        return Storage::disk('local')->download($path, "{$group->name}-{$kind}.{$ext}");
    }

    /** ตรวจชนิดไฟล์จากไบต์หัวไฟล์จริง ไม่เชื่อนามสกุล */
    private function signature(string $kind): \Closure
    {
        return function (string $attr, $file, \Closure $fail) use ($kind) {
            $head = $file instanceof UploadedFile ? (string) @file_get_contents($file->getRealPath(), false, null, 0, 8) : '';
            $ok = $kind === 'png'
                ? str_starts_with($head, "\x89PNG\r\n\x1a\n")
                : str_starts_with($head, "PK\x03\x04");
            if (! $ok) {
                $fail($kind === 'png' ? 'ต้องเป็นไฟล์ .PNG เท่านั้น' : 'ต้องเป็นไฟล์ .ZIP เท่านั้น');
            }
        };
    }

    public function update(Request $request, Group $group): GroupAdminResource
    {
        $data = $request->validate([
            'name' => ['sometimes', ...self::NAME_RULE],
            'is_lady' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'logo_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'skip_cooldown' => ['sometimes', 'boolean'],
        ], self::MESSAGES);

        $skip = (bool) ($data['skip_cooldown'] ?? false);
        unset($data['skip_cooldown']);

        if (isset($data['is_lady'])) {
            // ครอบครัวไม่มี Lady และเปลี่ยนเป็นแก๊งทั่วไปไม่ได้ถ้าตัวจริงเกินเพดานใหม่
            $data['is_lady'] = $group->type === GroupType::Gang && $data['is_lady'];
            if (! $data['is_lady'] && $group->is_lady) {
                $max = config('council.groups.slots.gang.main');
                if ($group->activeMembers()->where('is_reserve', false)->count() > $max) {
                    abort(422, "เปลี่ยนเป็นแก๊งทั่วไปไม่ได้ เพราะมีตัวจริงเกิน {$max} คน");
                }
            }
        }

        if (isset($data['name']) && $data['name'] !== $group->name) {
            $this->rules->assertValidName($group->type, $data['name'], $group->id);
            $this->rules->assertCanRename($group, $skip);
            $data['renamed_at'] = now();

            if ($skip) {
                activity('groups')->causedBy($request->user())->performedOn($group)
                    ->log("ปลดคูลดาวน์เปลี่ยนชื่อ {$group->name} → {$data['name']}");
            }
        }

        $group->update($data);

        return new GroupAdminResource($this->full($group));
    }

    /** เปลี่ยนสถานะ ปกติ/Blacklist/คว่ำบาตร และเก็บประวัติ */
    public function updateStatus(Request $request, Group $group): GroupAdminResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(GroupStatus::class)],
            'reason' => ['required_unless:status,normal', 'nullable', 'string', 'max:2000'],
        ], self::MESSAGES);

        $status = GroupStatus::from($data['status']);

        if ($status === $group->status) {
            abort(422, 'สถานะนี้เป็นสถานะปัจจุบันอยู่แล้ว');
        }

        DB::transaction(function () use ($group, $status, $data, $request) {
            $group->statusHistory()->whereNull('ended_at')->update(['ended_at' => now()]);
            $group->statusHistory()->create([
                'status' => $status,
                'reason' => $data['reason'] ?? null,
                'changed_by' => $request->user()->id,
                'started_at' => now(),
            ]);
            $group->update([
                'status' => $status,
                'status_reason' => $status === GroupStatus::Normal ? null : $data['reason'],
                'status_changed_at' => now(),
            ]);
        });

        return new GroupAdminResource($this->full($group));
    }

    /** ล็อก/ปลดล็อกสล็อตสมาชิก */
    public function updateLock(Request $request, Group $group): GroupAdminResource
    {
        $data = $request->validate([
            'slots_locked' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $group->update([
            'slots_locked' => $data['slots_locked'],
            'locked_reason' => $data['slots_locked'] ? ($data['reason'] ?? null) : null,
        ]);

        return new GroupAdminResource($this->full($group));
    }

    public function destroy(Group $group): JsonResponse
    {
        $group->delete();

        return response()->json(null, 204);
    }

    private function full(Group $group): Group
    {
        return $group->fresh()->load(['leader', 'members', 'statusHistory.changedBy'])->loadCount('activeMembers');
    }
}
