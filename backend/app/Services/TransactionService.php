<?php

namespace App\Services;

use App\Enums\GroupStatus;
use App\Enums\GroupType;
use App\Enums\MemberPosition;
use App\Enums\Role;
use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ColorNameMatcher;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ธุรกรรมกลางของแก๊ง/ครอบครัว: ตรวจข้อมูลตามชนิด → บันทึกเลขธุรกรรม → ใช้ผลกับกลุ่มใน DB transaction เดียว
 * สภา (สภาฝึกหัดขึ้นไป) ส่งแล้วมีผลทันที; approval_required = true จะค้างเป็น pending จนมีสภาอื่นอนุมัติ
 * การเปลี่ยนสถานะใช้ UPDATE ... WHERE status = 'pending' เงื่อนไขเดียว กันอนุมัติ/ยกเลิกซ้ำและแข่งกัน (ตอบ 409)
 */
class TransactionService
{
    /** ออกพร้อมกันได้สูงสุดกี่คนต่อธุรกรรม */
    private const MAX_RELEASE = 50;

    /** ชนิดชุดทั้งหมด และชนิดที่ครอบครัวลงได้ */
    private const OUTFIT_KEYS = ['suit', 'hoodie', 'tshirt', 'polo', 'armor', 'gun', 'mod1', 'mod2', 'accessories'];

    private const FAMILY_OUTFIT_KEYS = ['hoodie', 'tshirt', 'polo', 'accessories'];

    private const PNG = "\x89PNG\r\n\x1a\n";

    public function __construct(private GroupRules $rules) {}

    // ───────────────────────── ส่ง ─────────────────────────

    /**
     * @param  array<string, mixed>  $payload  ข้อมูลตามชนิด (อาจมีไฟล์ UploadedFile เช่น logo ของ rename)
     */
    public function submit(User $actor, TransactionKind $kind, ?Group $group, array $payload, bool $approvalRequired = false): Transaction
    {
        abort_unless($actor->atLeast(Role::Trainee) && ! $actor->role->isAdmin(), 403, 'เฉพาะสภาเท่านั้นที่ทำธุรกรรมได้');
        abort_if(in_array($kind, [TransactionKind::OutfitAdd, TransactionKind::OutfitFile], true), 422, 'ชนิดธุรกรรมนี้ถูกรวมเป็น "ลงชุด/แก้ไขชุด" แล้ว');

        $group = $this->resolveGroup($kind, $group);
        $payload = $this->validate($kind, $group, $payload);

        $stored = [];
        try {
            return DB::transaction(function () use ($actor, $kind, $group, $payload, $approvalRequired, &$stored) {
                $payload = $this->storeFiles($kind, $payload, $stored);

                $tx = Transaction::create([
                    'number' => $this->newNumber(),
                    'group_id' => $group?->id,
                    'kind' => $kind,
                    'payload' => $payload,
                    'status' => $approvalRequired ? TransactionStatus::Pending : TransactionStatus::Approved,
                    'requested_by' => $actor->id,
                    'requested_role' => $actor->role->value,
                    'approval_required' => $approvalRequired,
                    'reviewed_by' => $approvalRequired ? null : $actor->id,
                    'reviewed_at' => $approvalRequired ? null : now(),
                ]);

                if (! $approvalRequired) {
                    $this->applyEffect($tx, $actor);
                }

                return $tx->load(['group', 'requester', 'reviewer']);
            });
        } catch (\Throwable $e) {
            $this->deleteFiles($stored);
            throw $e;
        }
    }

    // ───────────────────────── อนุมัติ / ไม่อนุมัติ / ยกเลิก ─────────────────────────

    public function approve(Transaction $tx, User $reviewer): Transaction
    {
        $this->assertCanReview($tx, $reviewer);

        return DB::transaction(function () use ($tx, $reviewer) {
            $this->claim($tx, TransactionStatus::Approved, ['reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
            $tx->refresh();
            // ใช้ผลกับกลุ่ม ณ ตอนนี้ (ตรวจกติกาซ้ำเพราะข้อมูลกลุ่มอาจเปลี่ยนไประหว่างรอ) พังตรงไหนก็ย้อนกลับ ธุรกรรมยังรออนุมัติอยู่
            $this->revalidateForApply($tx);
            $this->applyEffect($tx, $reviewer);

            return $tx->load(['group', 'requester', 'reviewer']);
        });
    }

    public function reject(Transaction $tx, User $reviewer, string $reason): Transaction
    {
        $this->assertCanReview($tx, $reviewer);

        return DB::transaction(function () use ($tx, $reviewer, $reason) {
            $this->claim($tx, TransactionStatus::Rejected, ['reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'review_reason' => $reason]);
            $tx->refresh();
            $this->deleteFiles($this->pendingFiles($tx));
            $this->log($reviewer, $tx, "ไม่อนุมัติธุรกรรม {$tx->number}: {$reason}", 'rejected');

            return $tx->load(['group', 'requester', 'reviewer']);
        });
    }

    public function cancel(Transaction $tx, User $actor): Transaction
    {
        abort_unless($actor->atLeast(Role::Trainee) && ! $actor->role->isAdmin(), 403, 'เฉพาะสภาเท่านั้นที่จัดการธุรกรรมได้');
        abort_unless($tx->isPending(), 409, 'ธุรกรรมนี้ถูกดำเนินการไปแล้ว (ไม่ได้อยู่ในสถานะรออนุมัติ)');
        abort_unless($tx->requested_by === $actor->id || $actor->atLeast(Role::VicePresident), 403, 'ยกเลิกได้เฉพาะผู้ส่งหรือรองประธานขึ้นไป');

        return DB::transaction(function () use ($tx, $actor) {
            $this->claim($tx, TransactionStatus::Cancelled, ['reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            $tx->refresh();
            $this->deleteFiles($this->pendingFiles($tx));
            $this->log($actor, $tx, "ยกเลิกธุรกรรม {$tx->number}", 'cancelled');

            return $tx->load(['group', 'requester', 'reviewer']);
        });
    }

    private function assertCanReview(Transaction $tx, User $reviewer): void
    {
        abort_unless($reviewer->atLeast(Role::Trainee) && ! $reviewer->role->isAdmin(), 403, 'เฉพาะสภาเท่านั้นที่อนุมัติได้');
        abort_if($tx->requested_by === $reviewer->id, 403, 'อนุมัติธุรกรรมที่ตัวเองส่งไม่ได้');
    }

    /** เปลี่ยนสถานะจาก pending ด้วย UPDATE เดียว: ถ้ามีใครเปลี่ยนไปก่อนแล้วจะไม่มีแถวถูกแก้ → 409 */
    private function claim(Transaction $tx, TransactionStatus $to, array $extra): void
    {
        $changed = Transaction::query()
            ->whereKey($tx->id)
            ->where('status', TransactionStatus::Pending->value)
            ->update(['status' => $to->value, 'updated_at' => now()] + $extra);

        abort_if($changed === 0, 409, 'ธุรกรรมนี้ถูกดำเนินการไปแล้ว (ไม่ได้อยู่ในสถานะรออนุมัติ)');
    }

    // ───────────────────────── ตรวจข้อมูลตามชนิด ─────────────────────────

    private function resolveGroup(TransactionKind $kind, ?Group $group): ?Group
    {
        if (! $kind->needsGroup()) {
            return null;
        }
        if (! $group || $group->trashed()) {
            throw ValidationException::withMessages(['group_id' => 'ไม่พบแก๊ง/ครอบครัว หรือถูกยุบไปแล้ว']);
        }

        return $group;
    }

    /** @return array<string, mixed> payload ที่ผ่านการตรวจและจัดรูปแล้ว */
    private function validate(TransactionKind $kind, ?Group $group, array $payload): array
    {
        $rules = match ($kind) {
            TransactionKind::Register => $this->registerRules($payload),
            TransactionKind::Rename => [
                'name' => ['required', 'string', 'max:20', 'regex:/^(?=.*[A-Za-z])[A-Za-z0-9][A-Za-z0-9 ._\-]*$/'],
                'short_name' => ['required', 'string', 'max:4', 'regex:/^[A-Z0-9]+$/'],
                'logo' => ['required', 'file', 'max:2048', $this->pngRule()],
            ],
            TransactionKind::Recolor => [
                'color_hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'color_name' => ['required', 'string', 'max:60'],
            ],
            TransactionKind::ChangeLeader => [
                'name' => ['required', 'string', 'max:120'],
                'discord_id' => ['required', 'regex:/^\d{17,20}$/'],
                'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9 \-+]+$/'],
            ],
            // กรอกชื่อสมาชิกที่ออกได้หลายคน; เหตุผลและสล็อตไม่บังคับ
            TransactionKind::ReleaseSlot, TransactionKind::ReleaseFree => [
                'member_names' => ['required', 'array', 'min:1', 'max:'.self::MAX_RELEASE],
                'member_names.*' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
                'reason' => ['nullable', 'string', 'max:2000'],
                'slot' => ['nullable', Rule::in(['main', 'reserve'])],
            ],
            TransactionKind::Dissolve => [
                'reason' => ['required', 'string', 'max:2000'],
            ],
            TransactionKind::OutfitCounts => $this->outfitRules($group),
            TransactionKind::Outfit => $this->outfitMergedRules($group),
            // ลงชุดเพิ่มต้องแนบไฟล์ชุดที่จะลงด้วย (เก็บไว้กับธุรกรรมเป็นหลักฐาน ไม่แทนที่ไฟล์ชุดหลักของกลุ่ม)
            TransactionKind::OutfitAdd => $this->outfitRules($group) + ['outfit_zip' => ['required', 'file', 'max:51200', $this->zipRule()]],
            // ไฟล์ใหม่แทนที่ไฟล์เดิมทั้งก้อน จึงต้องระบุชนิดชุดที่ถูกแทน (ให้ผู้อนุมัติและบันทึกกิจกรรมเห็น)
            // ชนิดที่ถูกแทนต้องระบุจำนวนหลังเปลี่ยนด้วย (จำนวนนี้จะบันทึกเป็นจำนวนชุดที่ลงทะเบียนของชนิดนั้น)
            TransactionKind::OutfitFile => $this->outfitRules($group) + [
                'outfit_zip' => ['required', 'file', 'max:51200', $this->zipRule()],
                'types' => ['required', 'array', 'min:1'],
                'types.*' => ['string', 'distinct', Rule::in(array_keys($group->outfitLimits()))],
                // สลับข้ามชนิด: ชนิดต้นทาง => ชนิดปลายทาง (เช่น hoodie => tshirt) ทั้งสองต้องอยู่ใน types
                'swaps' => ['nullable', 'array'],
                'swaps.*' => ['string', Rule::in(array_keys($group->outfitLimits()))],
                'note' => ['nullable', 'string', 'max:2000'],
            ],
        };

        if (in_array($kind, [TransactionKind::ReleaseSlot, TransactionKind::ReleaseFree], true)) {
            $payload = $this->releaseNames($payload);
        }

        $v = Validator::make($payload, $rules, $this->messages());
        $data = $v->validate();

        $this->domainChecks($kind, $group, $data);

        return $this->normalize($kind, $data, $group);
    }

    /** @return array<string, array<int, mixed>> */
    private function outfitRules(Group $group): array
    {
        $allowed = $group->type === GroupType::Family ? self::FAMILY_OUTFIT_KEYS : self::OUTFIT_KEYS;
        $rules = ['counts' => ['required', 'array']];
        foreach (self::OUTFIT_KEYS as $key) {
            $rules["counts.{$key}"] = in_array($key, $allowed, true) ? ['nullable', 'integer', 'min:0', 'max:9999'] : ['prohibited'];
        }

        return $rules;
    }

    /**
     * ธุรกรรม "ลงชุด/แก้ไขชุด": ส่วนลงเพิ่ม (add) และส่วนเปลี่ยน/แทน (replace) ทำพร้อมกันได้ ใช้ไฟล์ .ZIP ไฟล์เดียว
     *
     * @return array<string, array<int, mixed>>
     */
    private function outfitMergedRules(Group $group): array
    {
        $allowed = array_keys($group->outfitLimits());
        $rules = [
            'add' => ['nullable', 'array'],
            'replace' => ['nullable', 'array'],
            'replace.types' => ['nullable', 'array'],
            'replace.types.*' => ['string', 'distinct', Rule::in($allowed)],
            'replace.counts' => ['nullable', 'array'],
            'replace.swaps' => ['nullable', 'array'],
            'replace.swaps.*' => ['string', Rule::in($allowed)],
            'outfit_zip' => ['required', 'file', 'max:51200', $this->zipRule()],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
        foreach (self::OUTFIT_KEYS as $key) {
            $rule = in_array($key, $allowed, true) ? ['nullable', 'integer', 'min:0', 'max:9999'] : ['prohibited'];
            $rules["add.{$key}"] = $rule;
            $rules["replace.counts.{$key}"] = $rule;
        }

        return $rules;
    }

    /** กติกาของธุรกรรม "ลงชุด/แก้ไขชุด" (ตรวจกับสถานะกลุ่มปัจจุบัน ใช้ซ้ำตอนอนุมัติด้วย) */
    private function outfitChecks(Group $group, array $d): void
    {
        $add = array_filter($this->cleanCounts($d['add'] ?? []), fn ($n) => $n > 0);
        $replace = $d['replace'] ?? [];
        $types = $replace['types'] ?? [];

        if (! $add && ! $types) {
            throw ValidationException::withMessages(['add' => 'กรุณากรอกจำนวนชุดที่จะลงเพิ่ม หรือเลือกชุดที่จะเปลี่ยน/แทนอย่างน้อย 1 อย่าง']);
        }

        $errors = [];

        // ส่วนลงเพิ่ม: ชนิดเดียวกันอยู่ได้ส่วนเดียว และที่ลงไว้ + ที่จะเพิ่ม ต้องไม่เกินลิมิต
        $current = $group->outfit_counts ?? [];
        $after = [];
        foreach ($add as $key => $n) {
            $after[$key] = ($current[$key] ?? 0) + $n;
            if (in_array($key, $types, true)) {
                $errors["add.{$key}"] = 'ชนิดนี้อยู่ในส่วนเปลี่ยน/แทนชุดแล้ว';
            }
        }
        foreach ($group->outfitLimitErrors($after) as $field => $msg) {
            $key = substr($field, strlen('counts.'));
            $errors["add.{$key}"] ??= "{$msg} (ลงไว้แล้ว ".($current[$key] ?? 0).' ชิ้น)';
        }

        // ส่วนเปลี่ยน/แทน: ทุกชนิดที่เลือกต้องมีจำนวนหลังเปลี่ยน ไม่เกินลิมิต; สลับข้ามชนิดต่อเป็นทอดไม่ได้
        if ($types) {
            $counts = $this->cleanCounts($replace['counts'] ?? []);
            $swaps = $replace['swaps'] ?? [];
            foreach ($types as $type) {
                if (! isset($counts[$type])) {
                    $errors["replace.counts.{$type}"] = 'กรุณากรอกจำนวนชุดหลังเปลี่ยน (ใส่ 0 ถ้าไม่มีชุดชนิดนี้แล้ว)';
                }
            }
            foreach (array_diff(array_keys($counts), $types) as $extra) {
                $errors["replace.counts.{$extra}"] = 'จำนวนนี้ไม่ได้อยู่ในชนิดชุดที่ถูกแทน';
            }
            foreach ($swaps as $from => $to) {
                if (! in_array($from, $types, true) || ! in_array($to, $types, true)) {
                    $errors["replace.swaps.{$from}"] = 'ชนิดต้นทางและปลายทางต้องอยู่ในชนิดชุดที่ถูกแทน';
                } elseif ($from === $to) {
                    $errors["replace.swaps.{$from}"] = 'ชนิดปลายทางต้องต่างจากชนิดต้นทาง';
                } elseif (isset($swaps[$to])) {
                    $errors["replace.swaps.{$from}"] = 'ชนิดปลายทางย้ายต่อไปชนิดอื่นไม่ได้';
                }
            }
            foreach ($group->outfitLimitErrors($counts) as $field => $msg) {
                $errors['replace.'.$field] ??= $msg;
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * ค่าบริการธุรกรรมชุดตามกฎสภา: ลงเพิ่มต่อชิ้น (MOD/Accessories ราคาสูงกว่า), แก้ไขชุดต่อชนิดที่ถูกแทน
     * แก๊ง/ครอบครัวที่เพิ่งขึ้นทะเบียนลงชุดเพิ่มฟรีภายใน outfit_free_hours ชั่วโมง แต่ใช้สิทธิ์ได้ครั้งเดียว (ดู Group::outfitFreeAvailable)
     *
     * @return list<array{label: string, unit_price: int, count: int, amount: int}>
     */
    private function outfitFeeItems(array $d, ?Group $group): array
    {
        $price = fn (string $key) => (int) config("council.fees.{$key}");
        $hours = (int) config('council.fees.outfit_free_hours');
        $free = $group && $group->outfitFreeAvailable(); // ภายใน N ชม. หลังขึ้นทะเบียน และยังไม่เคยส่งไฟล์ชุด
        $suffix = $free ? " (ฟรี ครั้งแรกภายใน {$hours} ชม. หลังขึ้นทะเบียน)" : '';
        $item = fn (string $label, int $unit, int $count) => ['label' => $label, 'unit_price' => $unit, 'count' => $count, 'amount' => $unit * $count];

        $special = ['mod1', 'mod2', 'accessories'];
        $add = $d['add'] ?? [];
        $specialN = array_sum(array_intersect_key($add, array_flip($special)));
        $normalN = array_sum($add) - $specialN;

        $items = [];
        if ($normalN > 0) {
            $items[] = $item('ลงชุดเพิ่ม (ต่อชิ้น)'.$suffix, $free ? 0 : $price('outfit_add'), $normalN);
        }
        if ($specialN > 0) {
            $items[] = $item('ลงชุด MOD / Accessories เพิ่ม (ต่อชิ้น)'.$suffix, $free ? 0 : $price('outfit_add_special'), $specialN);
        }

        $replace = $d['replace'] ?? [];
        $units = count($replace['types'] ?? []) - count(array_unique(array_values($replace['swaps'] ?? [])));
        if ($units > 0) {
            $items[] = $item('แก้ไขชุด (ต่อชนิด)', $price('outfit_edit'), $units);
        }

        return $items;
    }

    /** รองรับทั้ง member_names (รายการ) และ member_name (ชื่อเดียวแบบเดิม); ตัดช่องว่างและรายการว่าง */
    private function releaseNames(array $payload): array
    {
        $names = $payload['member_names'] ?? (isset($payload['member_name']) ? [$payload['member_name']] : []);
        if (is_string($names)) {
            $names = preg_split('/[\r\n,]+/', $names);
        }
        $names = is_array($names) ? array_values(array_filter(array_map(fn ($n) => is_string($n) ? trim($n) : $n, $names), fn ($n) => $n !== '' && $n !== null)) : [];

        unset($payload['member_name']);
        $payload['member_names'] = $names;

        return $payload;
    }

    /** @return array<string, array<int, mixed>> */
    private function registerRules(array $payload): array
    {
        $type = GroupType::tryFrom((string) ($payload['type'] ?? ''));
        $isLady = $type === GroupType::Gang && ! empty($payload['is_lady']);
        $key = $type === GroupType::Family ? 'family' : ($isLady ? 'lady' : 'gang');
        $min = $type ? (int) config("council.groups.min_members.{$type->value}") : 1;
        $slots = config("council.groups.slots.{$key}");
        $max = $slots['main'] + $slots['reserve'];
        $maxCo = $type ? Group::coLeaderLimit($type) : (int) config('council.groups.max_co_leaders');

        // Discord ID ของหัวหน้าบังคับ ส่วนของรองไม่บังคับ
        $person = fn (string $p, bool $discordRequired = true) => [
            "{$p}.name" => ['required', 'string', 'max:120'],
            "{$p}.discord_id" => [$discordRequired ? 'required' : 'nullable', 'regex:/^\d{17,20}$/'],
            "{$p}.phone" => ['required', 'string', 'max:30', 'regex:/^[0-9 \-+]+$/'],
        ];

        return [
            'type' => ['required', Rule::enum(GroupType::class)],
            'is_lady' => ['sometimes', 'boolean'],
            'name' => ['required', 'string', 'max:20', 'regex:/^(?=.*[A-Za-z])[A-Za-z0-9][A-Za-z0-9 ._\-]*$/'],
            'short_name' => ['required', 'string', 'max:4', 'regex:/^[A-Z0-9]+$/'],
            'color_hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'color_name' => ['required', 'string', 'max:60'],
            'leader' => ['required', 'array'],
            ...$person('leader'),
            'co_leaders' => ['sometimes', 'array', "max:{$maxCo}"],
            'co_leaders.*' => ['array'],
            ...$person('co_leaders.*', false),
            'protect_from' => ['nullable', 'date', 'required_with:protect_until'],
            'protect_until' => ['nullable', 'date', 'after:protect_from'],
        ];
    }

    private function domainChecks(TransactionKind $kind, ?Group $group, array $d): void
    {
        switch ($kind) {
            case TransactionKind::Register:
                $this->rules->assertValidName(GroupType::from($d['type']), $d['name']);
                $this->assertColorName($d['color_hex'], $d['color_name']);
                $names = array_map(fn ($p) => mb_strtolower(trim($p['name'])), [$d['leader'], ...($d['co_leaders'] ?? [])]);
                if (count(array_unique($names)) !== count($names)) {
                    throw ValidationException::withMessages(['co_leaders' => 'ชื่อหัวหน้าและรองต้องไม่ซ้ำกัน']);
                }
                $dup = Transaction::query()
                    ->where('kind', TransactionKind::Register->value)->where('status', TransactionStatus::Pending->value)
                    ->get()->contains(fn ($t) => mb_strtolower($t->payload['name'] ?? '') === mb_strtolower($d['name']));
                if ($dup) {
                    throw ValidationException::withMessages(['name' => 'มีคำขอขึ้นทะเบียนชื่อนี้รออนุมัติอยู่แล้ว']);
                }
                break;

            case TransactionKind::Rename:
                if (mb_strtolower($d['name']) === mb_strtolower($group->name)) {
                    throw ValidationException::withMessages(['name' => 'ชื่อใหม่ต้องต่างจากชื่อเดิม']);
                }
                $this->rules->assertValidName($group->type, $d['name'], $group->id);
                $this->rules->assertCanRename($group, false); // cooldown เปลี่ยนชื่อ
                break;

            case TransactionKind::Recolor:
                $this->assertColorName($d['color_hex'], $d['color_name']);
                if (strtoupper($d['color_hex']) === strtoupper((string) $group->color_hex) && $d['color_name'] === $group->color_name) {
                    throw ValidationException::withMessages(['color_hex' => 'สีใหม่ต้องต่างจากสีเดิม']);
                }
                break;

            case TransactionKind::ReleaseSlot:
                $this->rules->assertSlotsOpen($group); // ล็อกสล็อต → 409
                break;

            case TransactionKind::OutfitAdd:
                $add = $this->cleanCounts($d['counts'] ?? []);
                if (array_sum($add) < 1) {
                    throw ValidationException::withMessages(['counts' => 'กรุณากรอกจำนวนชุดที่จะลงเพิ่มอย่างน้อย 1 ชิ้น']);
                }
                // จำนวนที่ลงไว้แล้ว + ที่จะลงเพิ่ม ต้องไม่เกินลิมิตของชนิดนั้น
                $after = $group->outfit_counts ?? [];
                foreach ($add as $key => $n) {
                    $after[$key] = ($after[$key] ?? 0) + $n;
                }
                $over = [];
                foreach ($group->outfitLimitErrors(array_intersect_key($after, $add)) as $field => $msg) {
                    $key = substr($field, strlen('counts.'));
                    $over[$field] = "{$msg} (ลงไว้แล้ว ".($group->outfit_counts[$key] ?? 0).' ชิ้น)';
                }
                if ($over) {
                    throw ValidationException::withMessages($over);
                }
                break;

            case TransactionKind::Outfit:
                $this->outfitChecks($group, $d);
                break;

            case TransactionKind::OutfitCounts:
                if ($errors = $group->outfitLimitErrors($this->cleanCounts($d['counts'] ?? []))) {
                    throw ValidationException::withMessages($errors);
                }
                break;

            case TransactionKind::OutfitFile:
                $counts = $this->cleanCounts($d['counts'] ?? []);
                $errors = [];
                foreach ($d['types'] as $type) {
                    if (! isset($counts[$type])) {
                        $errors["counts.{$type}"] = 'กรุณากรอกจำนวนชุดหลังเปลี่ยน (ใส่ 0 ถ้าไม่มีชุดชนิดนี้แล้ว)';
                    }
                }
                $swaps = $d['swaps'] ?? [];
                foreach ($swaps as $from => $to) {
                    if (! in_array($from, $d['types'], true) || ! in_array($to, $d['types'], true)) {
                        $errors["swaps.{$from}"] = 'ชนิดต้นทางและปลายทางต้องอยู่ในชนิดชุดที่ถูกแทน';
                    } elseif ($from === $to) {
                        $errors["swaps.{$from}"] = 'ชนิดปลายทางต้องต่างจากชนิดต้นทาง';
                    } elseif (isset($swaps[$to])) {
                        $errors["swaps.{$from}"] = 'ชนิดปลายทางย้ายต่อไปชนิดอื่นไม่ได้';
                    }
                }
                foreach (array_diff(array_keys($counts), $d['types']) as $extra) {
                    $errors["counts.{$extra}"] = 'จำนวนนี้ไม่ได้อยู่ในชนิดชุดที่ถูกแทน';
                }
                if ($errors = $errors + $group->outfitLimitErrors($counts)) {
                    throw ValidationException::withMessages($errors);
                }
                break;

            default:
                break;
        }
    }

    /**
     * ค่าบริการของธุรกรรมนี้ (คำนวณฝั่งเซิร์ฟเวอร์จากอัตราใน config ไม่เชื่อค่าที่ส่งมา)
     * ออกสล็อต/ออกลอย = ราคาต่อคน x จำนวนสมาชิกที่ออก; เปลี่ยนชื่อ = ค่าเปลี่ยนชื่อ + ค่าเปลี่ยนโลโก้ (ต้องแนบโลโก้ใหม่เสมอ)
     *
     * @return array{items: list<array{label: string, unit_price: int, count: int, amount: int}>, unit_price: int, count: int, total: int, currency: string}
     */
    public function fee(TransactionKind $kind, array $d, ?Group $group = null): array
    {
        $price = fn (string $key) => (int) config("council.fees.{$key}");
        $item = fn (string $label, int $unit, int $count = 1) => ['label' => $label, 'unit_price' => $unit, 'count' => $count, 'amount' => $unit * $count];

        $items = match ($kind) {
            TransactionKind::Register => [$item('ขึ้นทะเบียน', $price('register'))],
            TransactionKind::Rename => [$item('เปลี่ยนชื่อ', $price('rename')), $item('เปลี่ยนโลโก้', $price('rename_logo'))],
            TransactionKind::Recolor => [$item('เปลี่ยนสี', $price('recolor'))],
            TransactionKind::ChangeLeader => [$item('เปลี่ยน Leader', $price('change_leader'))],
            TransactionKind::ReleaseSlot => [$item('ออกสล็อต (ต่อคน)', $price('release_slot'), count($d['member_names'] ?? []))],
            TransactionKind::ReleaseFree => [$item('ออกลอย (ต่อคน)', $price('release_free'), count($d['member_names'] ?? []))],
            TransactionKind::Dissolve => [$item('ยุบ', $price('dissolve'))],
            TransactionKind::OutfitAdd => [$item('ลงชุดเพิ่ม (ต่อชิ้น)', $price('outfit_add'), array_sum($d['counts'] ?? []))],
            TransactionKind::OutfitCounts => [$item('แก้จำนวนชุด', $price('outfit_counts'))],
            TransactionKind::OutfitFile => [$item('เปลี่ยนไฟล์ชุด', $price('outfit_file'))],
            TransactionKind::Outfit => $this->outfitFeeItems($d, $group),
        };

        $total = array_sum(array_column($items, 'amount'));

        return [
            'items' => $items,
            'unit_price' => count($items) === 1 ? $items[0]['unit_price'] : $total,
            'count' => count($items) === 1 ? $items[0]['count'] : 1,
            'total' => $total,
            'currency' => (string) config('council.fees.currency'),
        ];
    }

    /** ชื่อสีต้องตรงกับโค้ด HEX */
    private function assertColorName(string $hex, string $name): void
    {
        if ($message = ColorNameMatcher::check($hex, $name)) {
            throw ValidationException::withMessages(['color_name' => $message]);
        }
    }

    /** @return array<string, mixed> */
    private function normalize(TransactionKind $kind, array $d, ?Group $group = null): array
    {
        if (isset($d['color_hex'])) {
            $d['color_hex'] = strtoupper($d['color_hex']);
        }
        if (isset($d['counts'])) {
            $d['counts'] = $this->cleanCounts($d['counts']);
        }
        if (isset($d['swaps'])) {
            $d['swaps'] = array_intersect_key($d['swaps'], array_flip(self::OUTFIT_KEYS));
        }
        if (isset($d['types'])) {
            $d['types'] = array_values(array_intersect(self::OUTFIT_KEYS, $d['types'])); // เรียงตามลำดับมาตรฐาน
        }
        if ($kind === TransactionKind::Outfit) {
            $d['add'] = array_filter($this->cleanCounts($d['add'] ?? []), fn ($n) => $n > 0);
            $replace = $d['replace'] ?? [];
            if (! empty($replace['types'])) {
                $d['replace'] = [
                    'types' => array_values(array_intersect(self::OUTFIT_KEYS, $replace['types'])),
                    'counts' => $this->cleanCounts($replace['counts'] ?? []),
                    'swaps' => array_intersect_key($replace['swaps'] ?? [], array_flip(self::OUTFIT_KEYS)),
                ];
            } else {
                $d['replace'] = null;
            }
        }
        $d['fee'] = $this->fee($kind, $d, $group);
        if ($kind === TransactionKind::Register) {
            $d['is_lady'] = $d['type'] === GroupType::Gang->value && ! empty($d['is_lady']);
            $d['co_leaders'] = $d['co_leaders'] ?? [];
        }

        return $d;
    }

    /** ตัดช่องว่าง/null ออก เหลือเฉพาะ key => จำนวนเต็ม (เรียงตามลำดับชนิดชุดมาตรฐาน) */
    private function cleanCounts(array $counts): array
    {
        $out = [];
        foreach (self::OUTFIT_KEYS as $key) {
            if (isset($counts[$key]) && $counts[$key] !== '') {
                $out[$key] = (int) $counts[$key];
            }
        }

        return $out;
    }

    private function zipRule(): \Closure
    {
        return function (string $attr, $file, \Closure $fail) {
            $head = $file instanceof UploadedFile ? (string) @file_get_contents($file->getRealPath(), false, null, 0, 4) : '';
            if (! str_starts_with($head, "PK\x03\x04")) {
                $fail('ต้องเป็นไฟล์ .ZIP เท่านั้น');
            }
        };
    }

    private function pngRule(): \Closure
    {
        return function (string $attr, $file, \Closure $fail) {
            $head = $file instanceof UploadedFile ? (string) @file_get_contents($file->getRealPath(), false, null, 0, 8) : '';
            if (! str_starts_with($head, self::PNG)) {
                $fail('ต้องเป็นไฟล์ .PNG เท่านั้น');
            }
        };
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'required' => 'กรุณากรอกข้อมูลนี้',
            'required_with' => 'ต้องระบุวันเริ่ม Protect ด้วย',
            'logo.required' => 'กรุณาแนบรูปโลโก้ใหม่ (.PNG)',
            'max' => 'ข้อมูลยาวเกินกำหนด',
            'name.max' => 'ชื่อต้องยาวไม่เกิน 20 ตัวอักษร',
            'add.*.integer' => 'จำนวนต้องเป็นตัวเลข',
            'replace.counts.*.integer' => 'จำนวนต้องเป็นตัวเลข',
            'add.*.min' => 'จำนวนต้องไม่ติดลบ',
            'replace.counts.*.min' => 'จำนวนต้องไม่ติดลบ',
            'add.*.prohibited' => 'ประเภทนี้ลงชุดชนิดนี้ไม่ได้',
            'replace.counts.*.prohibited' => 'ประเภทนี้ลงชุดชนิดนี้ไม่ได้',
            'replace.types.*.in' => 'ประเภทนี้ลงชุดชนิดนี้ไม่ได้',
            'types.required' => 'กรุณาเลือกชนิดชุดที่ถูกแทนอย่างน้อย 1 ชนิด',
            'types.min' => 'กรุณาเลือกชนิดชุดที่ถูกแทนอย่างน้อย 1 ชนิด',
            'types.*.in' => 'ประเภทนี้ลงชุดชนิดนี้ไม่ได้',
            'name.regex' => 'ชื่อต้องเป็นภาษาอังกฤษ (ใช้ตัวเลขร่วมได้) และมีตัวอักษรอย่างน้อย 1 ตัว',
            'short_name.max' => 'ชื่อย่อต้องไม่เกิน 4 ตัวอักษร',
            'short_name.regex' => 'ชื่อย่อต้องเป็นตัวพิมพ์ใหญ่ภาษาอังกฤษหรือตัวเลขเท่านั้น',
            'color_hex.regex' => 'โค้ดสีต้องเป็นรูปแบบ #RRGGBB',
            '*.discord_id.regex' => 'Discord ID ต้องเป็นตัวเลข 17-20 หลัก',
            'discord_id.regex' => 'Discord ID ต้องเป็นตัวเลข 17-20 หลัก',
            '*.phone.regex' => 'เบอร์โทรไม่ถูกต้อง',
            'phone.regex' => 'เบอร์โทรไม่ถูกต้อง',
            'declared_members.min' => 'จำนวนสมาชิกต่ำกว่าขั้นต่ำของประเภทนี้',
            'declared_members.max' => 'จำนวนสมาชิกเกินสูงสุดของประเภทนี้',
            'co_leaders.max' => 'แก๊งมีรองได้ไม่เกิน '.config('council.groups.max_co_leaders').' คน ครอบครัวมีรองได้ '.config('council.groups.max_co_leaders_family').' คน',
            'protect_until.after' => 'วันสิ้นสุด Protect ต้องหลังวันเริ่ม',
            'slot.in' => 'สล็อตต้องเป็น main (ตัวจริง) หรือ reserve (ตัวสำรอง)',
            'type.enum' => 'ประเภทไม่ถูกต้อง',
        ];
    }

    // ───────────────────────── ไฟล์แนบ (โลโก้ตอนเปลี่ยนชื่อ) ─────────────────────────

    /** เก็บไฟล์ไว้ที่ดิสก์ส่วนตัวก่อน (ยังไม่เผยแพร่จนกว่าธุรกรรมมีผล) แล้วเก็บ path ใน payload แทนตัวไฟล์ */
    private function storeFiles(TransactionKind $kind, array $payload, array &$stored): array
    {
        if ($kind === TransactionKind::Rename && ($payload['logo'] ?? null) instanceof UploadedFile) {
            $path = $payload['logo']->store('transactions/logos', 'local');
            $stored[] = ['local', $path];
            unset($payload['logo']);
            $payload['logo_path'] = $path;
        }

        if (in_array($kind, [TransactionKind::Outfit, TransactionKind::OutfitAdd, TransactionKind::OutfitFile], true) && ($payload['outfit_zip'] ?? null) instanceof UploadedFile) {
            $path = $payload['outfit_zip']->store('transactions/outfits', 'local');
            $stored[] = ['local', $path];
            unset($payload['outfit_zip']);
            $payload['outfit_zip_path'] = $path;
        }

        return $payload;
    }

    /** @param  list<array{0: string, 1: string}>  $files */
    private function deleteFiles(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }
    }

    /** @return list<array{0: string, 1: string}> */
    private function pendingFiles(Transaction $tx): array
    {
        $files = [];
        foreach (['logo_path', 'outfit_zip_path'] as $key) {
            if ($path = $tx->payload[$key] ?? null) {
                $files[] = ['local', $path];
            }
        }

        return $files;
    }

    // ───────────────────────── ใช้ผลกับกลุ่ม ─────────────────────────

    /** ตรวจกติกาที่ขึ้นกับสถานะปัจจุบันของกลุ่มอีกครั้งตอนอนุมัติ */
    private function revalidateForApply(Transaction $tx): void
    {
        $group = $tx->group_id ? Group::withTrashed()->find($tx->group_id) : null;
        if ($tx->kind->needsGroup() && (! $group || $group->trashed())) {
            throw ValidationException::withMessages(['group_id' => 'กลุ่มนี้ถูกยุบไปแล้ว จึงอนุมัติไม่ได้']);
        }
        $this->domainChecks($tx->kind, $group, $tx->payload);
    }

    private function applyEffect(Transaction $tx, User $by): void
    {
        $group = $tx->group_id ? Group::findOrFail($tx->group_id) : null;
        $before = $group ? $this->snapshot($group) : null;

        match ($tx->kind) {
            TransactionKind::Register => $group = $this->doRegister($tx, $by),
            TransactionKind::Rename => $this->doRename($tx, $group),
            TransactionKind::Recolor => $group->update(['color_hex' => $tx->payload['color_hex'], 'color_name' => $tx->payload['color_name']]),
            TransactionKind::ChangeLeader => $this->doChangeLeader($tx, $group),
            TransactionKind::ReleaseSlot => $this->doRelease($tx, $group, true),
            TransactionKind::ReleaseFree => $this->doRelease($tx, $group, false),
            TransactionKind::Dissolve => $this->doDissolve($group),
            TransactionKind::OutfitAdd => $this->doOutfitAdd($tx, $group),
            TransactionKind::OutfitCounts => $group->update(['outfit_counts' => $tx->payload['counts']]),
            TransactionKind::OutfitFile => $this->doOutfitFile($tx, $group),
            TransactionKind::Outfit => $this->doOutfit($tx, $group),
        };

        $after = $tx->kind === TransactionKind::Dissolve
            ? ['dissolved' => true]
            : $this->snapshot($group->fresh());

        activity('transactions')
            ->causedBy($by)
            ->performedOn($tx)
            ->event('approved')
            ->withProperties(['number' => $tx->number, 'kind' => $tx->kind->value, 'group_id' => $group?->id, 'before' => $before, 'after' => $after])
            ->log("{$tx->kind->label()} {$tx->number}".($group ? " ({$group->name})" : ''));
    }

    private function doRegister(Transaction $tx, User $by): Group
    {
        $p = $tx->payload;
        $type = GroupType::from($p['type']);

        $group = Group::create([
            'type' => $type,
            'is_lady' => (bool) $p['is_lady'],
            'name' => $p['name'],
            'short_name' => $p['short_name'],
            'color_hex' => $p['color_hex'],
            'color_name' => $p['color_name'],
            'declared_members' => $p['declared_members'] ?? null,
            'protect_requested' => ! empty($p['protect_from']),
            'protect_from' => $p['protect_from'] ?? null,
            'protect_until' => $p['protect_until'] ?? null,
            'status' => GroupStatus::Normal,
            'status_changed_at' => now(),
            'registered_at' => now(),
        ]);

        $group->statusHistory()->create([
            'status' => GroupStatus::Normal,
            'reason' => "ขึ้นทะเบียน ({$tx->number})",
            'changed_by' => $by->id,
            'started_at' => now(),
        ]);

        foreach ([$p['leader'], ...$p['co_leaders']] as $i => $person) {
            $group->members()->create([
                'character_name' => trim($person['name']),
                'ic_phone' => $person['phone'],
                'discord_id' => $person['discord_id'] ?? null,
                'position' => $i === 0 ? MemberPosition::Leader : MemberPosition::CoLeader,
                'joined_at' => now(),
            ]);
        }

        $tx->update(['group_id' => $group->id]);

        return $group;
    }

    private function doRename(Transaction $tx, Group $group): void
    {
        $p = $tx->payload;
        $update = ['name' => $p['name'], 'short_name' => $p['short_name'], 'renamed_at' => now()];

        $path = $p['logo_path'] ?? null;
        if ($path && Storage::disk('local')->exists($path)) {
            $public = 'groups/logos/'.basename($path);
            Storage::disk('public')->put($public, Storage::disk('local')->get($path));
            Storage::disk('local')->delete($path);
            $update += ['logo_path' => $public, 'logo_url' => Storage::disk('public')->url($public)];
        } elseif ($path) {
            throw ValidationException::withMessages(['logo' => 'ไม่พบไฟล์โลโก้ที่แนบไว้ จึงเปลี่ยนชื่อไม่ได้']);
        }

        $group->update($update);
    }

    private function doChangeLeader(Transaction $tx, Group $group): void
    {
        $p = $tx->payload;
        $this->rules->assertSlotsOpen($group);

        $name = trim($p['name']);
        $old = $group->activeMembers()->where('position', MemberPosition::Leader->value)->first();
        $existing = $group->activeMembers()->whereRaw('lower(character_name) = ?', [mb_strtolower($name)])->first();

        if ($existing && $old && $existing->is($old)) {
            throw ValidationException::withMessages(['name' => "{$name} เป็นหัวหน้าอยู่แล้ว"]);
        }

        // หัวหน้าคนเดิมยังอยู่ในกลุ่มในฐานะสมาชิก (ย้ายออกให้ทำธุรกรรม ออกสล็อต/ออกลอย ต่างหาก)
        $old?->update(['position' => MemberPosition::Member]);

        if ($existing) {
            $this->rules->assertSlotAvailable($group, MemberPosition::Leader, false, $existing);
            $existing->update(['position' => MemberPosition::Leader, 'is_reserve' => false, 'ic_phone' => $p['phone'], 'discord_id' => $p['discord_id']]);

            return;
        }

        $this->rules->assertCanAddMember($group, $name, MemberPosition::Leader, false, false);
        $group->members()->create([
            'character_name' => $name,
            'ic_phone' => $p['phone'],
            'discord_id' => $p['discord_id'],
            'position' => MemberPosition::Leader,
            'is_reserve' => false,
            'joined_at' => now(),
        ]);
    }

    /** ออกสล็อต (มีคนมายืนยันตัวตนที่หน้าสภา) / ออกลอย (ผู้ออกไม่มายืนยันตัวตน) — ออกได้หลายชื่อในธุรกรรมเดียว */
    private function doRelease(Transaction $tx, Group $group, bool $slot): void
    {
        $p = $tx->payload;
        if ($slot) {
            $this->rules->assertSlotsOpen($group);
        }

        $names = $p['member_names'] ?? (isset($p['member_name']) ? [$p['member_name']] : []);
        $members = [];
        foreach ($names as $name) {
            $member = $group->activeMembers()->whereRaw('lower(character_name) = ?', [mb_strtolower(trim($name))])->first();
            if (! $member) {
                continue; // ระบบเก็บเฉพาะรายชื่อหัวหน้าและรอง: ชื่ออื่นบันทึกเป็นธุรกรรมไว้เป็นหลักฐานโดยไม่แก้รายชื่อ
            }
            if ($member->position === MemberPosition::Leader) {
                throw ValidationException::withMessages(['member_names' => "{$member->character_name} เป็นหัวหน้า ออกจากกลุ่มโดยตรงไม่ได้ ให้ทำธุรกรรมเปลี่ยน Leader ก่อน"]);
            }
            // ระบุสล็อตได้เมื่อออกคนเดียว (หลายคนข้ามการเทียบสล็อต)
            if ($slot && count($names) === 1 && ! empty($p['slot']) && ($p['slot'] === 'reserve') !== (bool) $member->is_reserve) {
                throw ValidationException::withMessages(['slot' => 'สมาชิกคนนี้อยู่ในสล็อต'.($member->is_reserve ? 'ตัวสำรอง' : 'ตัวจริง').' ไม่ตรงกับที่ระบุ']);
            }
            $members[] = $member;
        }

        foreach ($members as $member) {
            $member->update(['left_at' => now()]);
        }
    }

    /**
     * ลงชุด/แก้ไขชุด: บวกจำนวนส่วนลงเพิ่ม แล้วตั้งจำนวนส่วนเปลี่ยน/แทน
     * ถ้ามีส่วนเปลี่ยน/แทน ไฟล์ .ZIP จะแทนไฟล์ชุดหลักของกลุ่ม ถ้ามีแต่ส่วนลงเพิ่ม ไฟล์เก็บไว้กับธุรกรรมเป็นหลักฐาน
     */
    private function doOutfit(Transaction $tx, Group $group): void
    {
        $p = $tx->payload;
        $counts = $group->outfit_counts ?? [];
        foreach ($p['add'] ?? [] as $key => $n) {
            $counts[$key] = ($counts[$key] ?? 0) + $n;
        }
        foreach ($p['replace']['counts'] ?? [] as $key => $n) {
            $counts[$key] = $n;
        }
        $update = ['outfit_counts' => $counts];

        if (! empty($p['replace']['types'])) {
            $path = $p['outfit_zip_path'] ?? null;
            if (! $path || ! Storage::disk('local')->exists($path)) {
                throw ValidationException::withMessages(['outfit_zip' => 'ไม่พบไฟล์ชุดที่แนบไว้ จึงเปลี่ยนไฟล์ชุดไม่ได้']);
            }
            $new = 'groups/outfits/'.basename($path);
            Storage::disk('local')->move($path, $new);
            if ($group->outfit_zip_path && $group->outfit_zip_path !== $new) {
                Storage::disk('local')->delete($group->outfit_zip_path);
            }
            $update['outfit_zip_path'] = $new;
        }

        $group->update($update);
    }

    private function doOutfitAdd(Transaction $tx, Group $group): void
    {
        $counts = $group->outfit_counts ?? [];
        foreach ($tx->payload['counts'] as $key => $n) {
            $counts[$key] = ($counts[$key] ?? 0) + $n;
        }
        $group->update(['outfit_counts' => $counts]);
    }

    /** แทนที่ไฟล์ชุดเดิมด้วยไฟล์ใหม่ที่แนบไว้กับธุรกรรม แล้วลบไฟล์เก่า */
    private function doOutfitFile(Transaction $tx, Group $group): void
    {
        $path = $tx->payload['outfit_zip_path'] ?? null;
        if (! $path || ! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages(['outfit_zip' => 'ไม่พบไฟล์ชุดที่แนบไว้ จึงเปลี่ยนไฟล์ชุดไม่ได้']);
        }

        $new = 'groups/outfits/'.basename($path);
        Storage::disk('local')->move($path, $new);
        if ($group->outfit_zip_path && $group->outfit_zip_path !== $new) {
            Storage::disk('local')->delete($group->outfit_zip_path);
        }
        // จำนวนชุดของชนิดที่ถูกแทนเปลี่ยนเป็นจำนวนใหม่ ชนิดอื่นคงเดิม
        $group->update(['outfit_zip_path' => $new, 'outfit_counts' => array_merge($group->outfit_counts ?? [], $tx->payload['counts'])]);
    }

    private function doDissolve(Group $group): void
    {
        $group->activeMembers()->update(['left_at' => now()]);
        $group->delete();
    }

    /** @return array<string, mixed> ภาพสถานะกลุ่ม ณ ขณะหนึ่ง สำหรับบันทึกก่อน-หลังใน activity log */
    public function snapshot(Group $group): array
    {
        $active = $group->members()->whereNull('left_at')->get();

        return [
            'name' => $group->name,
            'short_name' => $group->short_name,
            'type' => $group->type->value,
            'is_lady' => $group->is_lady,
            'status' => $group->status->value,
            'color_hex' => $group->color_hex,
            'color_name' => $group->color_name,
            'logo_url' => $group->logo_url,
            'slots_locked' => $group->slots_locked,
            'protect_from' => $group->protect_from?->toIso8601String(),
            'protect_until' => $group->protect_until?->toIso8601String(),
            'leader' => $active->firstWhere('position', MemberPosition::Leader)?->character_name,
            'members_count' => $active->count(),
            'outfit_counts' => $group->outfit_counts ?? [],
            'outfit_zip' => $group->outfit_zip_path,
            'members' => $active->map(fn (GroupMember $m) => [
                'name' => $m->character_name,
                'position' => $m->position->value,
                'is_reserve' => $m->is_reserve,
            ])->values()->all(),
        ];
    }

    // ───────────────────────── เลขธุรกรรม ─────────────────────────

    /** WC-YYYYMMDD-XXXXXX: วันที่ตามเวลา Asia/Bangkok, XXXXXX สุ่มพิมพ์ใหญ่ (ตัดตัวที่สับสนง่าย) ชนกันสุ่มใหม่ */
    private function newNumber(): string
    {
        $date = now(config('council.report_timezone'))->format('Ymd');
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($i = 0; $i < 20; $i++) {
            $code = '';
            for ($j = 0; $j < 6; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $number = "WC-{$date}-{$code}";
            if (! Transaction::where('number', $number)->exists()) {
                return $number;
            }
        }

        throw new \RuntimeException('สร้างเลขธุรกรรมไม่สำเร็จ');
    }

    private function log(User $by, Transaction $tx, string $text, string $event): void
    {
        activity('transactions')->causedBy($by)->performedOn($tx)->event($event)->withProperties(['number' => $tx->number, 'kind' => $tx->kind->value])->log($text);
    }
}
