<?php

namespace App\Services;

use App\Enums\GroupType;
use App\Enums\Role;
use App\Enums\StoryStatus;
use App\Models\Group;
use App\Models\Story;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * สัญญาสตอรี่แบบนัดเวลา: สร้างสัญญา → (ล็อกสล็อตก่อนเริ่มเล่น) → เริ่มเล่นตามเวลา → บันทึกคะแนนจนฝ่ายใดถึงจำนวนไฟต์ = สิ้นสุด
 * พร้อมตรวจกติกาอัตโนมัติตามกฎ CC
 *  - BUY-013   ห้ามสตอรี่ซ้อน (กลุ่มที่ติดสตอรี่อยู่สร้างใหม่ไม่ได้)      - DEAL-003  รับเล่นได้วันละ N คู่ (นับตามวันที่นัดเล่น)
 *  - DEAL-003/004 เวลาคุย 19.00–23.30 (เลยได้ถึง 23.45 กรณียินยอม) · DEAL-010 สินเดิมพันมาตรฐาน · DEAL-012 ปืนขั้นต่ำ → เตือน ไม่ปฏิเสธ
 *  - DEAL-005/007 ล็อกสล็อตก่อนเริ่มเล่นจนสตอรี่สิ้นสุด · DEAL-008 ห้ามทำธุรกรรมระหว่างมีสัญญา
 *  - DEAL-011 ชำระหลังเริ่ม = 2 เท่า · PROT-010 ยอมแพ้ = 2 เท่า · PROT-009 ไม่มาเจรจาปิดเรื่อง = 0 คะแนน
 */
class StoryService
{
    private const TOXIC = [70, 100];

    // ───────────────────────── สร้าง / แก้ไข ─────────────────────────

    public function create(User $actor, array $data): Story
    {
        $this->assertCouncil($actor);
        $d = $this->validated($data, null);

        return DB::transaction(function () use ($actor, $d) {
            [$a, $b] = $this->loadGroups($d);
            $this->assertGroupsFree($a, $b, null);
            $this->assertDailyLimit($d['starts_at'], null);

            $number = $this->newNumber();
            $story = Story::create($this->attributes($d, $a, $b) + [
                'number' => $number,
                'status' => StoryStatus::Active,
                'started_at' => now(),
                'started_by' => $actor->id,
            ]);

            $this->log($actor, $story, "สร้างสตอรี่ {$number}: {$a->name} vs {$b->name}", 'started');
            $this->syncLocks();

            return $story->refresh()->load(['groupA', 'groupB', 'starter', 'ender']);
        });
    }

    public function update(User $actor, Story $story, array $data): Story
    {
        $this->assertCouncil($actor);

        return DB::transaction(function () use ($actor, $story, $data) {
            $story = Story::whereKey($story->id)->lockForUpdate()->firstOrFail();
            abort_unless($story->isActive(), 409, 'แก้ไขได้เฉพาะสตอรี่ที่ยังไม่สิ้นสุด');

            // คู่สัญญาเปลี่ยนไม่ได้ (สล็อตถูกล็อกไปแล้ว) ถ้าผิดคู่ให้ยกเลิกแล้วสร้างใหม่
            $d = $this->validated($data + ['group_a_id' => $story->group_a_id, 'group_b_id' => $story->group_b_id], $story);
            abort_if((int) $d['group_a_id'] !== $story->group_a_id || (int) $d['group_b_id'] !== $story->group_b_id, 422, 'เปลี่ยนคู่สตอรี่ไม่ได้ ให้ยกเลิกแล้วสร้างใหม่');
            [$a, $b] = $this->loadGroups($d);
            $this->assertDailyLimit($d['starts_at'], $story->id);

            $attrs = $this->attributes($d, $a, $b);
            unset($attrs['group_a_id'], $attrs['group_b_id']);
            // ล็อกแล้วคงสถานะล็อกไว้ ยกเว้นล็อกทันทีไว้ก่อนแต่แก้เป็นอนุญาตแล้ว และยังไม่ถึงเวลาล็อกปกติ → ปลดล็อก
            // ยังไม่ล็อกให้คำนวณเวลาใหม่ตามเวลาเริ่มเล่นที่แก้
            if ($story->lock_applied) {
                if ($attrs['lock_at']->gt(now())) {
                    $this->unlock($story);
                    $attrs += ['lock_applied' => false, 'locked_a' => false, 'locked_b' => false];
                } else {
                    unset($attrs['lock_at']);
                }
            }
            $story->update($attrs);

            $this->log($actor, $story, "แก้ไขข้อมูลสตอรี่ {$story->number}", 'updated');
            $this->syncLocks();

            return $story->refresh()->load(['groupA', 'groupB', 'starter', 'ender']);
        });
    }

    // ───────────────────────── คะแนน / จบ / ยกเลิก ─────────────────────────

    /** บันทึกคะแนนปัจจุบัน: ฝ่ายใดถึงจำนวนไฟต์ที่กำหนด = สตอรี่สิ้นสุดอัตโนมัติ */
    public function score(User $actor, Story $story, array $data): Story
    {
        $this->assertCouncil($actor);
        $d = Validator::make($data, [
            'score_a' => ['required', 'integer', 'min:0', 'max:99'],
            'score_b' => ['required', 'integer', 'min:0', 'max:99'],
            'protect_a' => ['nullable', 'boolean'],
            'protect_b' => ['nullable', 'boolean'],
        ], [
            'score_a.required' => 'กรุณากรอกคะแนนฝ่าย A',
            'score_b.required' => 'กรุณากรอกคะแนนฝ่าย B',
        ])->validate();
        $this->syncLocks(); // ถึงเวลาล็อกแล้วต้องล็อกก่อน เพื่อให้ปลดล็อกถูกต้องตอนจบ

        return DB::transaction(function () use ($actor, $story, $d) {
            $story = Story::whereKey($story->id)->lockForUpdate()->firstOrFail();
            abort_unless($story->isActive(), 409, 'สตอรี่นี้สิ้นสุดหรือถูกยกเลิกไปแล้ว');
            abort_if($story->starts_at && now()->lt($story->starts_at), 422, 'ยังไม่ถึงเวลาเริ่มเล่น บันทึกคะแนนไม่ได้');

            $a = (int) $d['score_a'];
            $b = (int) $d['score_b'];
            $target = (int) $story->fights;
            $errors = [];
            if ($a > $target) {
                $errors['score_a'] = "คะแนนต้องไม่เกิน {$target}";
            }
            if ($b > $target) {
                $errors['score_b'] = "คะแนนต้องไม่เกิน {$target}";
            }
            if (! $errors && $a === $target && $b === $target) {
                $errors['score_b'] = "ทั้งสองฝ่ายถึง {$target} คะแนนพร้อมกันไม่ได้";
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $story->load(['groupA', 'groupB']);
            if ($a === $target || $b === $target) {
                $winner = $a === $target ? $story->group_a_id : $story->group_b_id;
                $protect = $this->protectChoice($d); // สตอรี่จะจบ: ต้องระบุว่าแต่ละฝั่งรับ Protect หรือไม่ (PROT-006)
                $this->finish($story, 'normal', $a, $b, $winner, null, null, $actor, $protect);
                $this->log($actor, $story, "สตอรี่ {$story->number} สิ้นสุด: {$story->groupA->name} {$a} – {$b} {$story->groupB->name}", 'ended');
            } else {
                $story->update(['score_a' => $a, 'score_b' => $b]);
                $this->log($actor, $story, "บันทึกคะแนนสตอรี่ {$story->number}: {$story->groupA->name} {$a} – {$b} {$story->groupB->name}", 'scored');
            }

            return $story->refresh()->load(['groupA', 'groupB', 'starter', 'ender']);
        });
    }

    /** จบแบบพิเศษ: ยอมแพ้ (สินเดิมพัน 2 เท่า) หรือไม่มาเจรจาปิดเรื่อง (0 คะแนน) */
    public function end(User $actor, Story $story, array $data): Story
    {
        $this->assertCouncil($actor);
        $d = Validator::make($data, [
            'end_kind' => ['required', Rule::in(['surrender', 'no_show'])],
            'surrender_group_id' => ['nullable', 'integer'],
            'protect_a' => ['nullable', 'boolean'],
            'protect_b' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['end_kind.required' => 'กรุณาเลือกลักษณะการจบ'])->validate();

        return DB::transaction(function () use ($actor, $story, $d) {
            $story = Story::whereKey($story->id)->lockForUpdate()->firstOrFail();
            abort_unless($story->isActive(), 409, 'สตอรี่นี้สิ้นสุดหรือถูกยกเลิกไปแล้ว');
            $story->load(['groupA', 'groupB']);

            if ($d['end_kind'] === 'no_show') {
                // PROT-009: ตั้งใจไม่มาเจรจาปิดเรื่อง = 0 คะแนน แก้ภายหลังไม่ได้
                // PROT-009: ไม่มาเจรจาปิดเรื่อง = งด Protect ทั้งสองฝั่ง
                $this->finish($story, 'no_show', 0, 0, null, null, $d['note'] ?? null, $actor, [false, false]);
                $text = "สตอรี่ {$story->number} สิ้นสุด: ไม่มาเจรจาปิดเรื่อง (0 คะแนน)";
            } else {
                $loser = (int) ($d['surrender_group_id'] ?? 0);
                if (! in_array($loser, [$story->group_a_id, $story->group_b_id], true)) {
                    throw ValidationException::withMessages(['surrender_group_id' => 'กรุณาเลือกฝ่ายที่ยอมแพ้']);
                }
                $winner = $loser === $story->group_a_id ? $story->group_b_id : $story->group_a_id;
                $this->finish($story, 'surrender', $story->score_a, $story->score_b, $winner, $loser, $d['note'] ?? null, $actor, $this->protectChoice($d));
                $text = "สตอรี่ {$story->number} สิ้นสุด: ".($loser === $story->group_a_id ? $story->groupA->name : $story->groupB->name).' ยอมแพ้';
            }
            $this->log($actor, $story, $text, 'ended');

            return $story->refresh()->load(['groupA', 'groupB', 'starter', 'ender']);
        });
    }

    public function cancel(User $actor, Story $story, string $reason): Story
    {
        $this->assertCouncil($actor);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'กรุณากรอกเหตุผลที่ยกเลิก']);
        }

        return DB::transaction(function () use ($actor, $story, $reason) {
            $story = Story::whereKey($story->id)->lockForUpdate()->firstOrFail();
            abort_unless($story->isActive(), 409, 'สตอรี่นี้สิ้นสุดหรือถูกยกเลิกไปแล้ว');
            abort_unless($story->started_by === $actor->id || $actor->atLeast(Role::VicePresident), 403, 'ยกเลิกได้เฉพาะผู้สร้างหรือรองประธานขึ้นไป');

            $story->update(['status' => StoryStatus::Cancelled, 'end_note' => $reason, 'ended_at' => now(), 'ended_by' => $actor->id]);
            $this->unlock($story);
            $story->load(['groupA', 'groupB']);
            $this->log($actor, $story, "ยกเลิกสตอรี่ {$story->number}: {$reason}", 'cancelled');

            return $story->refresh()->load(['groupA', 'groupB', 'starter', 'ender']);
        });
    }

    // ───────────────────────── ล็อกสล็อตตามเวลา ─────────────────────────

    /** ล็อกสล็อตของสตอรี่ที่ถึงเวลาล็อกแล้ว (เรียกจากตัวรันตารางงานทุกนาที และตอนเปิดดูรายการ) @return int จำนวนสตอรี่ที่ล็อกเพิ่ม */
    public function syncLocks(): int
    {
        $due = Story::active()->where('lock_applied', false)->whereNotNull('lock_at')->where('lock_at', '<=', now())->get();
        foreach ($due as $story) {
            DB::transaction(function () use ($story) {
                $story = Story::whereKey($story->id)->lockForUpdate()->first();
                if (! $story || ! $story->isActive() || $story->lock_applied) {
                    return;
                }
                $a = Group::withTrashed()->find($story->group_a_id);
                $b = Group::withTrashed()->find($story->group_b_id);
                $story->update([
                    'lock_applied' => true,
                    'locked_a' => $a ? $this->lock($a, "สตอรี่ {$story->number}") : false,
                    'locked_b' => $b ? $this->lock($b, "สตอรี่ {$story->number}") : false,
                ]);
            });
        }

        return $due->count();
    }

    // ───────────────────────── ตรวจกติกา ─────────────────────────

    /** จำนวนคู่ที่นัดเล่นในวันนั้น (ตามเวลาไทย ไม่นับที่ยกเลิก) */
    public function countOnDay(Carbon $when, ?int $exceptId = null): int
    {
        $tz = config('council.report_timezone');
        $from = $when->copy()->setTimezone($tz)->startOfDay()->utc();
        $to = $from->copy()->addDay();

        return Story::where('status', '!=', StoryStatus::Cancelled->value)
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->count();
    }

    private function assertDailyLimit(Carbon $startsAt, ?int $exceptId): void
    {
        $limit = (int) config('council.story.daily_limit');
        if ($this->countOnDay($startsAt, $exceptId) >= $limit) {
            throw ValidationException::withMessages(['starts_at' => "วันที่นัดเล่นรับสตอรี่ครบ {$limit} คู่แล้ว (DEAL-003) ให้เลื่อนไปวันถัดไป"]);
        }
    }

    private function assertGroupsFree(Group $a, Group $b, ?int $exceptId): void
    {
        $errors = [];
        foreach (['group_a_id' => $a, 'group_b_id' => $b] as $field => $g) {
            $other = Story::active()->where(fn ($q) => $q->where('group_a_id', $g->id)->orWhere('group_b_id', $g->id))
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->first();
            if ($other) {
                $errors[$field] = "{$g->name} ติดสตอรี่ {$other->number} อยู่ ห้ามสตอรี่ซ้อน (BUY-013) ต้องสิ้นสุดหรือยกเลิกคู่เดิมก่อน";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return list<string> คำเตือน (ไม่ปฏิเสธ) ตามกฎ DEAL */
    private function warnings(Group $a, Group $b, Carbon $startsAt, int $stake, ?int $guns): array
    {
        $w = [];
        $cfg = config('council.story');
        $at = $startsAt->copy()->setTimezone(config('council.report_timezone'));
        $mins = $at->hour * 60 + $at->minute;
        if ($mins < $cfg['window_start'] || $mins > $cfg['window_grace_end']) {
            $w[] = 'เวลานัดเริ่มเล่นอยู่นอกช่วงคุยสตอรี่ 19.00–23.30 น. (DEAL-003)';
        } elseif ($mins > $cfg['window_end']) {
            $w[] = 'เลย 23.30 น. เริ่มได้เฉพาะกรณีสองฝ่ายยินยอมตรงกัน ไม่เกิน 23.45 น. และเล่นสูงสุด 5 ไฟต์ (DEAL-004)';
        }
        if ($stake < $cfg['stake_min'] || $stake > $cfg['stake_max']) {
            $w[] = 'เดิมพันนอกมาตรฐาน '.number_format($cfg['stake_min']).'–'.number_format($cfg['stake_max']).' WVDL ต้องเป็นข้อตกลงของสองฝ่าย (DEAL-010)';
        }
        if ($guns !== null && $guns > 0) {
            $min = collect([$a, $b])->map(fn (Group $g) => $g->type === GroupType::Family ? $cfg['guns_min_family'] : $cfg['guns_min_gang'])->max();
            if ($guns < $min) {
                $w[] = "จำนวนปืนต่ำกว่าเกณฑ์ขั้นต่ำ (แก๊งละ {$cfg['guns_min_gang']} ครอบครัวละ {$cfg['guns_min_family']} กระบอก) ต้องเป็นข้อตกลงที่ผู้เสียหายยินยอม (DEAL-012)";
            }
        }
        if ($startsAt->lt(now()->subMinutes(5))) {
            $w[] = 'เวลานัดเริ่มเล่นผ่านไปแล้ว';
        }

        return $w;
    }

    // ───────────────────────── ภายใน ─────────────────────────

    /** @return array<string, mixed> */
    private function validated(array $data, ?Story $story): array
    {
        $cfg = config('council.story');
        $d = Validator::make($data, [
            'story_type' => ['required', Rule::in(['gang', 'lady', 'family'])],
            'play_format' => ['required', Rule::in(['random', ...$cfg['formats']])],
            'starts_at' => ['required', 'date'],
            'group_a_id' => ['required', 'integer', 'different:group_b_id'],
            'group_b_id' => ['required', 'integer'],
            'fights' => ['required', 'integer', $this->fightsRule($cfg, $story)],
            'venue' => ['required', Rule::in(['random', ...$cfg['venues']])],
            'stake_amount' => ['required', 'integer', $this->stakeRule($cfg)],
            // ชำระหลังเริ่ม: ไม่ระบุ/none = ไม่มี · ต้องระบุฝั่งที่ชำระหลังเริ่มชัดเจน (a | b | both)
            'stake_late' => ['nullable', Rule::in(['none', 'a', 'b', 'both'])],
            'guns' => ['required', 'integer', 'min:0', 'max:50'],
            'ticket_policy' => ['required', Rule::in($cfg['ticket_options'])],
            // ครอบครัวไม่มีตัวสำรอง (config groups.slots.family.reserve = 0) จึงไม่ต้องระบุและบังคับเป็นไม่อนุญาต
            'allow_reserve_swap' => [Rule::requiredIf(fn () => ($data['story_type'] ?? null) !== 'family'), 'boolean'],
            'allow_slot_change' => ['required', 'boolean'],
            'toxic_level' => ['nullable', Rule::in(self::TOXIC)],
            'extra_conditions' => ['nullable', 'string', 'max:2000'],
        ], [
            'story_type.required' => 'กรุณาเลือกประเภท',
            'play_format.required' => 'กรุณาเลือกรูปแบบการเล่น',
            'play_format.in' => 'รูปแบบการเล่นไม่ถูกต้อง',
            'starts_at.required' => 'กรุณากรอกวันและเวลาเริ่มเล่น',
            'starts_at.date' => 'วันและเวลาเริ่มเล่นไม่ถูกต้อง',
            'group_a_id.required' => 'กรุณาเลือกฝั่ง A',
            'group_b_id.required' => 'กรุณาเลือกฝั่ง B',
            'group_a_id.different' => 'ต้องเลือกสองฝั่งที่ต่างกัน',
            'fights.required' => 'กรุณากรอกจำนวนไฟต์',
            'fights.in' => 'จำนวนไฟต์ต้องเลือกจาก '.implode(', ', $cfg['fights_options']),
            'fights.between' => "จำนวนไฟต์ต้องอยู่ระหว่าง {$cfg['fights_min']}–{$cfg['fights_max']}",
            'venue.required' => 'กรุณาเลือกสถานที่ไฟต์',
            'venue.in' => 'สถานที่ไฟต์ไม่ถูกต้อง',
            'stake_amount.required' => 'กรุณากรอกเดิมพันฝั่งละ (ไม่มี = 0)',
            'stake_amount.integer' => 'เดิมพันต้องเป็นตัวเลข',
            'guns.required' => 'กรุณากรอกจำนวนปืน (ไม่เล่นปืน = 0)',
            'ticket_policy.required' => 'กรุณาเลือกการแจ้งใบ',
            'ticket_policy.in' => 'การแจ้งใบไม่ถูกต้อง',
            'allow_reserve_swap.required' => 'กรุณาเลือกการสลับ Slot ตัวสำรอง',
            'allow_slot_change.required' => 'กรุณาเลือกการเพิ่ม-ถอน Slot',
        ])->validate();

        if ($d['story_type'] === 'family') {
            $d['allow_reserve_swap'] = false;
        }
        // เวลาที่กรอกคือเวลาไทย
        $d['starts_at'] = Carbon::parse($d['starts_at'], config('council.report_timezone'))->utc();
        // สุ่มโดยระบบ: เลือกจากรายการที่กำหนดแล้วเก็บค่าจริง (ตอนแก้ไขหน้าเว็บส่งค่าจริงที่เก็บไว้มา)
        // ส่งค่าจริง + <field>_random = true ได้ (หน้าแก้ไขที่ไม่ได้เปลี่ยนค่าสุ่มเดิม) จะคงค่าที่สุ่มไว้และจำว่ามาจากการสุ่ม
        foreach (['play_format' => 'formats', 'venue' => 'venues'] as $field => $list) {
            $d[$field.'_random'] = $d[$field] === 'random' || (bool) ($data[$field.'_random'] ?? false);
            if ($d[$field] === 'random') {
                $d[$field] = collect($cfg[$list])->random();
            }
        }

        return $d;
    }

    /** เดิมพัน: เดิมไม่ติดลบและไม่เกินเพดาน; โหมดบังคับ (stake_enforce) ต้องอยู่ในช่วงมาตรฐาน min–max ด้วย (0 = ไม่วางเดิมพัน ยังได้) */
    private function stakeRule(array $cfg): \Closure
    {
        return function (string $attr, mixed $value, \Closure $fail) use ($cfg) {
            $n = (int) $value;
            if ($n < 0 || $n > $cfg['stake_cap']) {
                $fail('เดิมพันต้องอยู่ระหว่าง 0–'.number_format($cfg['stake_cap']).' WVDL');
            } elseif ($cfg['stake_enforce'] && $n !== 0 && ($n < $cfg['stake_min'] || $n > $cfg['stake_max'])) {
                $fail('เดิมพันต้องอยู่ระหว่าง '.number_format($cfg['stake_min']).'–'.number_format($cfg['stake_max']).' WVDL (หรือ 0 = ไม่วางเดิมพัน)');
            }
        };
    }

    /** จำนวนไฟต์: เลือกจากรายการที่ตั้งไว้ (เปิดโหมดกำหนดเองได้ใน config) และสตอรี่เดิมคงค่าเดิมได้แม้เลิกใช้ตัวเลือกนั้นแล้ว */
    private function fightsRule(array $cfg, ?Story $story): \Closure
    {
        $allowed = $cfg['fights_options'];
        if ($story?->fights) {
            $allowed[] = (int) $story->fights;
        }

        return function (string $attr, mixed $value, \Closure $fail) use ($cfg, $allowed) {
            $n = (int) $value;
            if (in_array($n, $allowed, true)) {
                return;
            }
            if (! $cfg['fights_custom']) {
                $fail('จำนวนไฟต์ต้องเลือกจาก '.implode(', ', $cfg['fights_options']));
            } elseif ($n < $cfg['fights_min'] || $n > $cfg['fights_max']) {
                $fail("จำนวนไฟต์ต้องอยู่ระหว่าง {$cfg['fights_min']}–{$cfg['fights_max']}");
            }
        };
    }

    /** @return array{0: Group, 1: Group} */
    private function loadGroups(array $d): array
    {
        $groups = Group::withTrashed()->whereIn('id', [$d['group_a_id'], $d['group_b_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $a = $groups->get((int) $d['group_a_id']);
        $b = $groups->get((int) $d['group_b_id']);
        $errors = [];
        foreach (['group_a_id' => $a, 'group_b_id' => $b] as $field => $g) {
            if (! $g || $g->trashed()) {
                $errors[$field] = 'ไม่พบแก๊ง/ครอบครัว หรือถูกยุบไปแล้ว';
            } elseif (($d['story_type'] === 'family') !== ($g->type === GroupType::Family)) {
                $errors[$field] = $d['story_type'] === 'family' ? 'สตอรี่ประเภท FAMILY ต้องเลือกครอบครัวเท่านั้น' : 'สตอรี่ประเภท GANG/LADY-GANG ต้องเลือกแก๊งเท่านั้น';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$a, $b];
    }

    /** @return array<string, mixed> */
    private function attributes(array $d, Group $a, Group $b): array
    {
        $startsAt = $d['starts_at'];

        return [
            'story_type' => $d['story_type'],
            'play_format' => $d['play_format'],
            'play_format_random' => $d['play_format_random'],
            'starts_at' => $startsAt,
            // ไม่อนุญาตทั้งสลับสล็อตตัวสำรองและเพิ่ม-ถอนสล็อต = ล็อกสล็อตทันที ไม่รอใกล้เริ่มเล่น (ครอบครัวไม่มีตัวสำรอง ดูแค่เพิ่ม-ถอน)
            'lock_at' => ! $d['allow_reserve_swap'] && ! $d['allow_slot_change']
                ? now()
                : $startsAt->copy()->subMinutes((int) config('council.story.lock_minutes')),
            'group_a_id' => $a->id,
            'group_b_id' => $b->id,
            'fights' => (int) $d['fights'],
            'venue' => $d['venue'],
            'venue_random' => $d['venue_random'],
            'stake_amount' => (int) $d['stake_amount'],
            'stake_late_a' => in_array($d['stake_late'] ?? 'none', ['a', 'both'], true),
            'stake_late_b' => in_array($d['stake_late'] ?? 'none', ['b', 'both'], true),
            'guns' => (int) $d['guns'],
            'ticket_policy' => $d['ticket_policy'],
            'allow_reserve_swap' => (bool) $d['allow_reserve_swap'],
            'allow_slot_change' => (bool) $d['allow_slot_change'],
            'toxic_level' => $d['toxic_level'] ?? null,
            'extra_conditions' => $d['extra_conditions'] ?? null,
            'warnings' => $this->warnings($a, $b, $startsAt, (int) $d['stake_amount'], (int) $d['guns']) ?: null,
        ];
    }

    /**
     * Protect หลังจบสตอรี่ (PROT-006): ต้องระบุทั้งสองฝั่งว่ารับหรือไม่รับ
     *
     * @return array{0: bool, 1: bool}
     */
    private function protectChoice(array $d): array
    {
        $errors = [];
        foreach (['protect_a' => 'ฝั่ง A', 'protect_b' => 'ฝั่ง B'] as $key => $label) {
            if (! array_key_exists($key, $d) || $d[$key] === null) {
                $errors[$key] = "กรุณาระบุว่า{$label}รับ Protect หรือไม่";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [(bool) $d['protect_a'], (bool) $d['protect_b']];
    }

    /** @param  array{0: bool, 1: bool}  $protect */
    private function finish(Story $story, string $kind, int $sa, int $sb, ?int $winner, ?int $surrender, ?string $note, User $actor, array $protect): void
    {
        // ชำระหลังเริ่ม (DEAL-011) ของฝั่งนั้น หรือฝั่งที่ยอมแพ้ (PROT-010) = 2 เท่า ไม่ทบซ้อนกัน
        $final = fn (bool $late, int $groupId) => $story->stake_amount * (($late || $surrender === $groupId) ? 2 : 1);
        $story->update([
            'status' => StoryStatus::Ended,
            'end_kind' => $kind,
            'score_a' => $sa,
            'score_b' => $sb,
            'winner_group_id' => $winner,
            'surrender_group_id' => $surrender,
            'stake_final_a' => $final($story->stake_late_a, $story->group_a_id),
            'stake_final_b' => $final($story->stake_late_b, $story->group_b_id),
            'protect_a' => $protect[0],
            'protect_b' => $protect[1],
            'end_note' => $note,
            'ended_at' => now(),
            'ended_by' => $actor->id,
        ]);
        $this->unlock($story);
        $this->grantProtect($story, $protect, $actor);
    }

    /**
     * ฝั่งที่รับ Protect (PROT-006): ตั้ง Protect ให้กลุ่มทันทีที่บันทึกคะแนน นับ N วัน (council.story.protect_days)
     * ถ้ากลุ่มมี Protect เดิมที่หมดช้ากว่า คงของเดิมไว้ · บันทึกลงทะเบียนของกลุ่ม (ประวัติ Protect) และบันทึกกิจกรรม
     *
     * @param  array{0: bool, 1: bool}  $protect
     */
    private function grantProtect(Story $story, array $protect, User $actor): void
    {
        $days = (int) config('council.story.protect_days');
        $updates = [];
        foreach ([['a', $story->group_a_id, $protect[0]], ['b', $story->group_b_id, $protect[1]]] as [$side, $groupId, $accepted]) {
            if (! $accepted) {
                continue;
            }
            $group = Group::withTrashed()->find($groupId);
            if (! $group || $group->trashed()) {
                continue;
            }

            $until = now()->addDays($days);
            if ($group->protect_until && $group->protect_until->gt($until)) {
                $until = $group->protect_until;
            }
            $before = ['protect_from' => $group->protect_from?->toIso8601String(), 'protect_until' => $group->protect_until?->toIso8601String()];
            $group->update([
                'protect_requested' => true,
                'protect_from' => $group->protect_until && $group->protect_until->isFuture() ? ($group->protect_from ?? now()) : now(),
                'protect_until' => $until,
            ]);
            $group->refresh();
            $after = ['protect_from' => $group->protect_from?->toIso8601String(), 'protect_until' => $group->protect_until?->toIso8601String()];

            $group->records()->create([
                'kind' => 'protect',
                'data' => ['before' => $before, 'after' => $after],
                'note' => "รับ Protect หลังสตอรี่ {$story->number} ({$days} วัน)",
                'recorded_by' => $actor->id,
            ]);
            activity('groups')->causedBy($actor)->performedOn($group)
                ->withProperties(['before' => $before, 'after' => $after])
                ->log("รับ Protect {$group->name} {$days} วัน หลังสตอรี่ {$story->number}");

            $updates['protect_until_'.$side] = $until;
        }
        if ($updates) {
            $story->update($updates);
        }
    }

    private function assertCouncil(User $actor): void
    {
        abort_unless($actor->atLeast(Role::Trainee) && ! $actor->role->isAdmin(), 403, 'เฉพาะสภาเท่านั้นที่จัดการสตอรี่ได้');
    }

    /** ล็อกสล็อตกลุ่ม (DEAL-005/007): true = สตอรี่เป็นฝ่ายล็อกเอง จะปลดให้ตอนจบ; ถ้าสภาล็อกไว้ก่อนแล้วไม่แตะ */
    private function lock(Group $g, string $reason): bool
    {
        if ($g->slots_locked) {
            return false;
        }
        $g->update(['slots_locked' => true, 'locked_reason' => $reason]);

        return true;
    }

    private function unlock(Story $story): void
    {
        foreach ([[$story->locked_a, $story->group_a_id], [$story->locked_b, $story->group_b_id]] as [$locked, $id]) {
            if ($locked) {
                Group::withTrashed()->whereKey($id)->update(['slots_locked' => false, 'locked_reason' => null]);
            }
        }
    }

    private function log(User $by, Story $story, string $text, string $event): void
    {
        activity('stories')->causedBy($by)->performedOn($story)->event($event)->withProperties([
            'number' => $story->number,
            'group_ids' => [$story->group_a_id, $story->group_b_id],
        ])->log($text);
    }

    private function newNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($i = 0; $i < 20; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $number = "WV-ST-{$code}";
            if (! Story::where('number', $number)->exists()) {
                return $number;
            }
        }

        throw new \RuntimeException('สร้างเลขสตอรี่ไม่สำเร็จ');
    }
}
