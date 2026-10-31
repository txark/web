<?php

namespace App\Http\Controllers\Council;

use App\Enums\GroupStatus;
use App\Enums\GroupType;
use App\Enums\MemberPosition;
use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupAdminResource;
use App\Models\Group;
use App\Services\GroupRules;
use App\Support\ColorNameMatcher;
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
    private const NAME_RULE = ['required', 'string', 'max:20', 'regex:/^(?=.*[A-Za-z])[A-Za-z0-9][A-Za-z0-9 ._\-]*$/'];

    private const MESSAGES = [
        'name.required' => 'กรุณากรอกชื่อ',
        'name.max' => 'ชื่อต้องยาวไม่เกิน 20 ตัวอักษร',
        'name.regex' => 'ชื่อต้องเป็นภาษาอังกฤษ (ใช้ตัวเลขร่วมได้) และมีตัวอักษรอย่างน้อย 1 ตัว',
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
            'kind' => ['nullable', Rule::in(['gang', 'lady', 'family'])], // lady = แก๊งหญิงล้วน
            'status' => ['nullable', Rule::enum(GroupStatus::class)],
            'scope' => ['nullable', Rule::in(['active', 'dissolved', 'deleted'])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $scope = $f['scope'] ?? 'active';

        $query = match ($scope) {
            'dissolved' => $this->dissolved(Group::onlyTrashed(), true),
            'deleted' => $this->dissolved(Group::onlyTrashed(), false),
            default => Group::query(),
        };

        $groups = $query
            ->with('leader')
            ->withCount('activeMembers')
            ->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($f['kind'] ?? null, fn ($q, $v) => match ($v) {
                'family' => $q->where('type', GroupType::Family->value),
                'lady' => $q->where('type', GroupType::Gang->value)->where('is_lady', true),
                default => $q->where('type', GroupType::Gang->value)->where('is_lady', false),
            })
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(function ($w) use ($v) {
                $w->whereLike('name', "%{$v}%")
                    ->orWhereLike('short_name', "%{$v}%")
                    ->orWhereHas('members', fn ($m) => $m->where('position', MemberPosition::Leader->value)->whereLike('character_name', "%{$v}%"));
            }))
            ->orderBy('name')
            ->paginate($f['per_page'] ?? 30)
            ->withQueryString();

        return GroupAdminResource::collection($groups)->additional(['counts' => $this->counts()]);
    }

    /** ถูกยุบด้วยธุรกรรม "ยุบ" ($viaTransaction = true) หรือถูกลบด้วยวิธีอื่น (false) — ทั้งสองเป็น soft delete */
    private function dissolved($query, bool $viaTransaction)
    {
        $exists = fn ($q) => $q->select(DB::raw(1))->from('transactions')
            ->whereColumn('transactions.group_id', 'groups.id')
            ->where('transactions.kind', TransactionKind::Dissolve->value)
            ->where('transactions.status', TransactionStatus::Approved->value);

        return $viaTransaction ? $query->whereExists($exists) : $query->whereNotExists($exists);
    }

    /** @return array<string, int> จำนวนกลุ่มที่ใช้งาน แยกประเภท (ไม่ขึ้นกับตัวกรอง) + จำนวนที่ยุบ/ลบแล้ว */
    private function counts(): array
    {
        $active = Group::query()->selectRaw('type, is_lady, count(*) as n')->groupBy('type', 'is_lady')->get();
        $gang = $active->where('type', GroupType::Gang)->where('is_lady', false)->sum('n');
        $lady = $active->where('type', GroupType::Gang)->where('is_lady', true)->sum('n');
        $family = $active->where('type', GroupType::Family)->sum('n');

        return [
            'active' => (int) ($gang + $lady + $family),
            'gang' => (int) $gang,
            'lady' => (int) $lady,
            'family' => (int) $family,
            'dissolved' => $this->dissolved(Group::onlyTrashed(), true)->count(),
            'deleted' => $this->dissolved(Group::onlyTrashed(), false)->count(),
        ];
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
        $maxCo = $type ? Group::coLeaderLimit($type) : (int) config('council.groups.max_co_leaders');

        // Discord ID ของหัวหน้าบังคับ ส่วนของรองไม่บังคับ (แต่ถ้ากรอกต้องเป็นตัวเลข 17-20 หลัก)
        $person = fn (string $p, bool $discordRequired = true) => [
            "{$p}.ic_name" => ['required', 'string', 'max:120'],
            "{$p}.ic_phone" => ['nullable', 'string', 'max:30', 'regex:/^[0-9 \-+]+$/'],
            "{$p}.discord_id" => [$discordRequired ? 'required' : 'nullable', 'regex:/^\d{17,20}$/'],
        ];

        $data = $request->validate([
            'type' => ['required', Rule::enum(GroupType::class)],
            'is_lady' => ['sometimes', 'boolean'],
            'name' => self::NAME_RULE,
            'short_name' => ['required', 'string', 'max:4', 'regex:/^[A-Z0-9]+$/'],
            'color_hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_name' => ['required', 'string', 'max:60'],
            'logo' => ['required', 'file', 'max:2048', $this->signature('png')],
            'leader' => ['required', 'array'],
            ...$person('leader'),
            'co_leaders' => ['nullable', 'array', "max:{$maxCo}"], // รองไม่บังคับ
            'co_leaders.*' => ['array'],
            ...$person('co_leaders.*', false),
            // ชุดชั่วคราวเป็นเบอร์สี 1..N (ไม่แนบรูป) และวันเดียวกันใช้เบอร์ซ้ำกันไม่ได้
            'temp_outfit_color' => ['required', 'integer', 'min:1', 'max:'.config('council.groups.temp_outfit.colors')],
            'outfit_zip' => ['required', 'file', 'max:51200', $this->signature('zip')],
            'protect' => ['sometimes', 'boolean'],
        ], self::MESSAGES + [
            'short_name.regex' => 'ชื่อย่อต้องเป็นตัวพิมพ์ใหญ่ภาษาอังกฤษหรือตัวเลขเท่านั้น ไม่เกิน 4 ตัวอักษร',
            'color_hex.regex' => 'โค้ดสีต้องเป็นรูปแบบ #RRGGBB',
            'temp_outfit_color.required' => 'กรุณาเลือกเบอร์สีชุดชั่วคราว',
            'temp_outfit_color.integer' => 'เบอร์สีชุดชั่วคราวไม่ถูกต้อง',
            'temp_outfit_color.min' => 'เบอร์สีชุดชั่วคราวไม่ถูกต้อง',
            'temp_outfit_color.max' => 'เบอร์สีชุดชั่วคราวไม่ถูกต้อง',
            'co_leaders.max' => $type === GroupType::Family ? 'ครอบครัวมีรองได้ 1 คน' : "แก๊งมีรองได้ไม่เกิน {$maxCo} คน",
            '*.discord_id.regex' => 'Discord ID ต้องเป็นตัวเลข 17-20 หลัก',
            '*.ic_phone.regex' => 'เบอร์ IC ไม่ถูกต้อง',
        ]);

        $this->rules->assertValidName($type, $data['name']);
        $this->assertColorName($data['color_hex'], $data['color_name']);

        $people = [$data['leader'], ...($data['co_leaders'] ?? [])];
        $names = array_map(fn ($p) => mb_strtolower(trim($p['ic_name'])), $people);
        if (count(array_unique($names)) !== count($names)) {
            throw ValidationException::withMessages(['co_leaders' => 'ชื่อหัวหน้าและรองต้องไม่ซ้ำกัน']);
        }

        $stored = [];
        try {
            $group = DB::transaction(function () use ($data, $type, $isLady, $request, $people, &$stored) {
                // ตรวจเบอร์สีซ้ำในวันเดียวกันใน transaction (ล็อกตารางกันสองคนจองเบอร์เดียวกันพร้อมกัน)
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('LOCK TABLE groups IN SHARE ROW EXCLUSIVE MODE');
                }
                if (in_array((int) $data['temp_outfit_color'], $this->takenTempColors(), true)) {
                    throw ValidationException::withMessages(['temp_outfit_color' => "เบอร์สีชุดชั่วคราว {$data['temp_outfit_color']} ถูกแก๊ง/ครอบครัวอื่นใช้ไปแล้วในวันนี้ กรุณาเลือกเบอร์อื่น"]);
                }

                $logo = $request->file('logo')->store('groups/logos', 'public');
                $stored[] = ['public', $logo];
                $zip = $request->file('outfit_zip')->store('groups/outfits', 'local');
                $stored[] = ['local', $zip];
                $protect = $request->boolean('protect');

                $group = Group::create([
                    'type' => $type,
                    'is_lady' => $isLady,
                    'name' => $data['name'],
                    'short_name' => $data['short_name'],
                    'color_hex' => strtoupper($data['color_hex']),
                    'color_name' => $data['color_name'],
                    'logo_path' => $logo,
                    'logo_url' => Storage::disk('public')->url($logo),
                    'temp_outfit_male' => config('council.groups.temp_outfit.male'),
                    'temp_outfit_female' => config('council.groups.temp_outfit.female'),
                    'temp_outfit_color' => (int) $data['temp_outfit_color'],
                    'outfit_zip_path' => $zip,
                    'outfit_zip_original_path' => $zip,
                    'protect_requested' => $protect,
                    'protect_from' => $protect ? now() : null, // เริ่มนับตอนกดขึ้นทะเบียน
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
                        'discord_id' => $p['discord_id'] ?? null,
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
    /** เบอร์สีชุดชั่วคราวที่ถูกใช้ไปแล้ววันนี้ (เวลาไทย) ไว้ให้หน้าขึ้นทะเบียนปิดเบอร์ที่ซ้ำ */
    public function tempColors(): \Illuminate\Http\JsonResponse
    {
        $cfg = config('council.groups.temp_outfit');

        return response()->json([
            'taken' => $this->takenTempColors(),
            'colors' => (int) $cfg['colors'],
            'male' => $cfg['male'],
            'female' => $cfg['female'],
            'date' => now(config('council.report_timezone'))->toDateString(),
        ]);
    }

    /** @return list<int> เบอร์สีที่แก๊ง/ครอบครัว (รวมที่ยุบไปแล้ว) ขึ้นทะเบียนด้วยในวันนี้ตามเวลาไทย */
    private function takenTempColors(): array
    {
        $tz = config('council.report_timezone');
        $from = now($tz)->startOfDay()->utc();

        return Group::withTrashed()->whereNotNull('temp_outfit_color')
            ->where('registered_at', '>=', $from)->where('registered_at', '<', $from->copy()->addDay())
            ->pluck('temp_outfit_color')->map(fn ($c) => (int) $c)->unique()->values()->all();
    }

    public function file(Group $group, string $kind)
    {
        // โลโก้อยู่ดิสก์สาธารณะ: ส่งกลับเป็นรูป (ไม่ดาวน์โหลด) ให้หน้าเว็บนำไปวาดในหนังสือสัญญา
        if ($kind === 'logo') {
            abort_unless($group->logo_path && Storage::disk('public')->exists($group->logo_path), 404);

            return Storage::disk('public')->response($group->logo_path);
        }

        $path = match ($kind) {
            'temp_outfit' => $group->temp_outfit_path,
            'outfit_zip' => $group->outfit_zip_path,
            'outfit_zip_original' => $group->outfit_zip_original_path,
            default => null,
        };
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $ext = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';

        return Storage::disk('local')->download($path, "{$group->name}-{$kind}.{$ext}");
    }

    /** ชื่อสีต้องตรงกับโค้ด HEX (เช่น #FF0000 ใช้ชื่อ "เขียว" ไม่ได้) */
    private function assertColorName(string $hex, string $name): void
    {
        if ($message = ColorNameMatcher::check($hex, $name)) {
            throw ValidationException::withMessages(['color_name' => $message]);
        }
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
        return $group->fresh()->load(['leader', 'members', 'statusHistory.changedBy'])->loadCount('activeMembers')
            ->loadMax(['records as last_report_at' => fn ($q) => $q->where('kind', 'report')], 'created_at');
    }
}
