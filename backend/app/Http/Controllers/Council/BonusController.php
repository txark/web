<?php

namespace App\Http\Controllers\Council;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\CouncilPayItem;
use App\Models\CouncilPayRound;
use App\Models\DutyShift;
use App\Models\User;
use App\Services\DutyService;
use App\Services\PayFormula;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * โบนัสเวรของสภา (ประธานและรองประธาน): "ตัดรอบ" นับชั่วโมงเวรตั้งแต่เวลาตัดรอบครั้งก่อนถึงตอนกดตัดรอบ
 *
 * สูตรกรอกครั้งเดียวต่อรอบ ระบบคำนวณทุกคนด้วยสูตรเดียวกัน เช่น (เวลาเข้าเวร ÷ 1440) × paycheck + โบนัสพิเศษ
 * - "เวลาเข้าเวร" = นาทีเวรของแต่ละคน ระบบดึงให้เอง (÷ 1440 = จำนวนวัน)
 * - ตัวแปรอื่น (paycheck, โบนัสพิเศษ, ที่เพิ่มเอง) เป็นค่าเดียวกันทุกคน ตัวแปรที่ตั้งเป็น "ไม่บังคับ" เว้นว่างได้ (นับเป็น 0)
 * ตัดรอบแล้วถือว่าจ่ายแล้ว (ไม่มีสถานะรอจ่าย) นับเป็นรายจ่ายหมวดโบนัสของหน้าการเงิน · ย้อนได้เฉพาะรอบล่าสุด
 * ยอดคำนวณฝั่งเซิร์ฟเวอร์เสมอ รอบแรกเริ่มนับจากวันที่ที่เลือก (ค่าเริ่มต้น = เวรแรกสุดในระบบ)
 */
class BonusController extends Controller
{
    private const MAX_VARIABLES = 20;

    private const CUT_KEY = 'council.bonus.duty_cut';

    private const MESSAGES = [
        'start_from.date_format' => 'วันที่เริ่มนับไม่ถูกต้อง',
        'formula.required' => 'กรุณากรอกสูตร',
        'formula.max' => 'สูตรยาวเกินไป (ไม่เกิน 300 ตัวอักษร)',
        'variables.max' => 'เพิ่มตัวแปรได้ไม่เกิน 20 ตัว',
        'note.max' => 'หมายเหตุยาวเกินไป (ไม่เกิน 1,000 ตัวอักษร)',
    ];

    public function __construct(private DutyService $duty) {}

    /** รอบที่เปิดอยู่ (จากเวลาตัดรอบล่าสุดถึงตอนนี้) + ประวัติรอบที่ตัดแล้ว (ใหม่สุดก่อน) + สูตร/ตัวแปรครั้งล่าสุดเป็นค่าเริ่มต้น */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $perPage = (int) ($f['per_page'] ?? 10);
        $page = (int) ($f['page'] ?? 1);

        $all = CouncilPayRound::query()->with(['items.user:id,name,character_name,role,avatar', 'creator:id,name,character_name'])->orderByDesc('ended_at')->orderByDesc('id')->get();
        $latest = $all->first();
        $tz = config('council.report_timezone');
        $earliest = DutyShift::query()->min('started_at');

        return response()->json([
            'data' => $all->forPage($page, $perPage)->map(fn (CouncilPayRound $r) => $this->roundRow($r, $r->id === $latest?->id))->values(),
            'meta' => [
                'total' => $all->count(), 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($all->count() / $perPage)),
                'total_paid' => (int) $all->sum(fn (CouncilPayRound $r) => $r->items->sum('amount')),
                'open' => [
                    'started_at' => $latest?->ended_at->copy()->utc()->toIso8601String(),
                    'first_round' => $latest === null,
                    'earliest_shift_date' => $earliest ? Carbon::parse($earliest, 'UTC')->timezone($tz)->toDateString() : null,
                ],
                'cut_at' => $this->pendingCut()?->copy()->utc()->toIso8601String(),
                'defaults' => $this->defaults($latest),
                'minutes_variable' => PayFormula::MINUTES,
                'currency' => config('council.fees.currency'),
            ],
        ]);
    }

    /** คำนวณโบนัสของรอบที่เปิดอยู่ ณ ตอนนี้ให้ดูก่อนตัดรอบ (ไม่บันทึก) */
    public function preview(Request $request): JsonResponse
    {
        [$d, $ast, $vars, $clean] = $this->validated($request);
        $cut = $this->pendingCut();
        [$start, $end] = $this->period($d['start_from'] ?? null, $cut ?? now());
        // ต้องตัดรอบเวลาเข้าเวรก่อนจึงจะคิดโบนัส (นาทีเวรถึงตอนนี้ยังไม่นิ่ง) ยังไม่ตัด = ไม่คำนวณ
        $rows = $cut ? $this->compute($start, $end, $ast, $vars) : collect();

        return response()->json(['data' => [
            'started_at' => $start->copy()->utc()->toIso8601String(), 'ended_at' => $end->copy()->utc()->toIso8601String(),
            'formula' => $d['formula'], 'variables' => $clean,
            'rows' => $rows->values(), 'total' => (int) $rows->sum('amount'), 'minutes_total' => (int) $rows->sum('minutes'),
            'first_round' => $this->latestRound() === null,
            'cut_at' => $cut?->copy()->utc()->toIso8601String(),
        ]]);
    }

    /** ตัดรอบเวลาเข้าเวร: ล็อกเวลา ณ ตอนนี้ไว้ นาทีเวรของทุกคนนับถึงเวลานี้ แล้วจึงคิดโบนัสและตัดรอบโบนัสได้ */
    public function dutyCut(): JsonResponse
    {
        $cut = now();
        if (($latest = $this->latestRound()) && $cut->lte($latest->ended_at->copy()->addMinute())) {
            throw ValidationException::withMessages(['ended_at' => 'รอบนี้ยังไม่ถึง 1 นาที (เพิ่งตัดรอบไป) ตัดรอบเวลาเข้าเวรซ้ำไม่ได้']);
        }
        Cache::forever(self::CUT_KEY, $cut->copy()->utc()->toIso8601String());

        return response()->json(['data' => ['cut_at' => $cut->copy()->utc()->toIso8601String()]]);
    }

    /** ยกเลิกการตัดรอบเวลาเข้าเวร (ก่อนตัดรอบโบนัส) เพื่อตัดใหม่ */
    public function clearDutyCut(): JsonResponse
    {
        Cache::forget(self::CUT_KEY);

        return response()->json(['message' => 'ยกเลิกการตัดรอบเวลาเข้าเวรแล้ว']);
    }

    /**
     * ตัดรอบ: บันทึกโบนัสของรอบที่เปิดอยู่ (ถือว่าจ่ายแล้ว) ถึงเวลาที่ตัดรอบเวลาเข้าเวรไว้ (ต้องตัดรอบเวลาเข้าเวรก่อน)
     * เพื่อให้ยอดที่ผู้กดยืนยันเห็นตรงกับที่บันทึก คนที่ยอดเป็น 0 ไม่ถูกบันทึกเป็นรายการ
     */
    public function cutoff(Request $request): JsonResponse
    {
        [$d, $ast, $vars, $clean] = $this->validated($request);
        $request->validate(['note' => ['nullable', 'string', 'max:1000']], self::MESSAGES);

        $end = $this->pendingCut();
        if (! $end) {
            throw ValidationException::withMessages(['cut' => 'ต้องตัดรอบเวลาเข้าเวรก่อนจึงจะตัดรอบโบนัสได้']);
        }

        $result = DB::transaction(function () use ($request, $d, $ast, $vars, $clean, $end) {
            // ล็อกแถวผู้ใช้ที่ทำรายการกันกดตัดรอบซ้อนกันสองคน (รอบถัดไปต้องเริ่มจากรอบที่เพิ่งตัด)
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            [$start] = $this->period($d['start_from'] ?? null, $end);
            if ($end->lte($start->copy()->addMinute())) {
                throw ValidationException::withMessages(['ended_at' => 'รอบนี้ยังไม่ถึง 1 นาที (เพิ่งตัดรอบไป) ตัดรอบซ้ำไม่ได้']);
            }

            $rows = $this->compute($start, $end, $ast, $vars)->filter(fn (array $r) => $r['amount'] > 0)->values();
            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['formula' => 'ไม่มีสมาชิกสภาที่ได้รับโบนัสในรอบนี้ (ตรวจสูตร ตัวแปร และชั่วโมงเวร)']);
            }

            $round = CouncilPayRound::create([
                'started_at' => $start->copy()->utc(), 'ended_at' => $end->copy()->utc(), 'formula' => $d['formula'], 'variables' => $clean,
                'note' => $request->input('note') ?: null, 'created_by' => $request->user()->id,
            ]);
            foreach ($rows as $r) {
                // salary/base/special เป็นคอลัมน์เดิมของระบบรายคน ไม่ใช้แล้วกับสูตร (เก็บ 0 / ยอดรวม) ยอดจริงอยู่ที่ amount
                CouncilPayItem::create(['round_id' => $round->id, 'user_id' => $r['user']['id'], 'minutes' => $r['minutes'], 'salary' => 0, 'base' => $r['amount'], 'special' => 0, 'amount' => $r['amount']]);
            }

            return $round;
        });
        Cache::forget(self::CUT_KEY);

        return response()->json(['data' => $this->roundRow($result->load(['items.user:id,name,character_name,role,avatar', 'creator:id,name,character_name']), true)], 201);
    }

    /** ย้อนรอบล่าสุด (ลบแบบซ่อน): หักออกจากรายจ่าย และรอบที่เปิดอยู่กลับไปเริ่มนับจากรอบก่อนหน้า ย้อนรอบที่เก่ากว่านั้นไม่ได้ */
    public function destroy(CouncilPayRound $round): JsonResponse
    {
        $latest = $this->latestRound();
        if (! $latest || $latest->id !== $round->id) {
            throw ValidationException::withMessages(['round' => 'ย้อนได้เฉพาะรอบล่าสุดเท่านั้น']);
        }
        $round->delete();
        Cache::forget(self::CUT_KEY);

        return response()->json(['message' => 'ย้อนรอบล่าสุดแล้ว']);
    }

    // ───────────────────────── ภายใน ─────────────────────────

    /** สูตรและตัวแปรที่ใส่ให้ในหน้า: ของรอบล่าสุด ไม่มีก็ใช้ค่าเริ่มต้น (paycheck บังคับ · โบนัสพิเศษไม่บังคับ) @return array<string, mixed> */
    private function defaults(?CouncilPayRound $latest): array
    {
        if ($latest && $latest->formula) {
            return ['formula' => $latest->formula, 'variables' => $latest->variables ?? []];
        }

        return [
            'formula' => '('.PayFormula::MINUTES.' ÷ 1440) × paycheck + โบนัสพิเศษ',
            'variables' => [['name' => 'paycheck', 'value' => '', 'optional' => false], ['name' => 'โบนัสพิเศษ', 'value' => '', 'optional' => true]],
        ];
    }

    /**
     * ตรวจและแยกสูตร + ตัวแปร
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, float>, 3: list<array<string, mixed>>} [ข้อมูลที่ตรวจแล้ว, ต้นไม้สูตร, ค่าตัวแปร, ตัวแปรที่ทำความสะอาดแล้ว]
     */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'start_from' => ['nullable', 'date_format:Y-m-d'],
            'formula' => ['required', 'string', 'max:300'],
            'variables' => ['nullable', 'array', 'max:'.self::MAX_VARIABLES],
            'variables.*.name' => ['required', 'string', 'max:40'],
            'variables.*.value' => ['nullable'],
            'variables.*.optional' => ['nullable', 'boolean'],
        ], self::MESSAGES + ['variables.*.name.required' => 'กรุณาตั้งชื่อตัวแปร']);

        $errors = [];
        $clean = [];
        $vars = [];
        $seen = [];
        foreach (($d['variables'] ?? []) as $i => $v) {
            $name = trim((string) $v['name']);
            $optional = filter_var($v['optional'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $raw = trim(str_replace(',', '', (string) ($v['value'] ?? '')));

            if (! PayFormula::validName($name)) {
                $errors["variables.{$i}.name"] = 'ชื่อตัวแปรใช้ได้เฉพาะตัวอักษร ตัวเลข และ _ (ขึ้นต้นด้วยตัวอักษร ไม่มีเว้นวรรค)';
            } elseif ($name === PayFormula::MINUTES) {
                $errors["variables.{$i}.name"] = '"'.PayFormula::MINUTES.'" เป็นตัวแปรของระบบ ใช้เป็นชื่อตัวแปรเองไม่ได้';
            } elseif (isset($seen[mb_strtolower($name)])) {
                $errors["variables.{$i}.name"] = "ชื่อตัวแปร \"{$name}\" ซ้ำกัน";
            }
            $seen[mb_strtolower($name)] = true;

            if ($raw === '') {
                if (! $optional) {
                    $errors["variables.{$i}.value"] = "กรุณากรอกค่าของ {$name} (หรือตั้งเป็นไม่บังคับ)";
                }
                $number = 0.0;
            } elseif (! is_numeric($raw) || (float) $raw < 0 || (float) $raw > 1e12) {
                $errors["variables.{$i}.value"] = 'ค่าต้องเป็นตัวเลข 0 ขึ้นไป';
                $number = 0.0;
            } else {
                $number = (float) $raw;
            }

            $clean[] = ['name' => $name, 'value' => $raw, 'optional' => $optional];
            $vars[$name] = $number;
        }

        $ast = [];
        try {
            $ast = PayFormula::parse($d['formula']);
            foreach (PayFormula::variables($ast) as $used) {
                if ($used !== PayFormula::MINUTES && ! array_key_exists($used, $vars)) {
                    $errors['formula'] = "สูตรใช้ตัวแปร \"{$used}\" ที่ยังไม่ได้ตั้ง (กด + เพิ่มตัวแปร) หรือสะกดชื่อผิด";
                    break;
                }
            }
            if (! isset($errors['formula'])) {
                PayFormula::evaluate($ast, $vars + [PayFormula::MINUTES => 1440]); // ทดลองคำนวณจับหารด้วยศูนย์ล่วงหน้า
            }
        } catch (InvalidArgumentException $e) {
            $errors['formula'] = $e->getMessage();
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$d, $ast, $vars, $clean];
    }

    /** เวลาที่ตัดรอบเวลาเข้าเวรไว้ (รอตัดรอบโบนัส) นาทีเวรทุกคนนับถึงเวลานี้เท่านั้นจึงคงที่ */
    private function pendingCut(): ?Carbon
    {
        $v = Cache::get(self::CUT_KEY);
        $cut = $v ? Carbon::parse($v) : null;
        $latest = $this->latestRound();

        return $cut && (! $latest || $cut->gt($latest->ended_at)) ? $cut : null;
    }

    private function latestRound(): ?CouncilPayRound
    {
        return CouncilPayRound::query()->orderByDesc('ended_at')->orderByDesc('id')->first();
    }

    /**
     * ช่วงเวลาของรอบที่เปิดอยู่ [เริ่ม, $end): เริ่มจากเวลาตัดรอบล่าสุด; รอบแรกเริ่มจากวันที่ที่เลือก (เที่ยงคืนเวลาไทย) หรือเวรแรกสุด
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(?string $startFrom, Carbon $end): array
    {
        $latest = $this->latestRound();
        if ($latest) {
            return [$latest->ended_at->copy(), $end->copy()];
        }
        if ($startFrom) {
            $start = Carbon::parse($startFrom, config('council.report_timezone'))->startOfDay()->utc();
        } else {
            $first = DutyShift::query()->min('started_at');
            $start = $first ? Carbon::parse($first, 'UTC') : $end->copy();
        }
        if ($startFrom && $start->gte($end)) {
            throw ValidationException::withMessages(['start_from' => 'วันที่เริ่มนับต้องอยู่ก่อนตอนนี้']);
        }

        return [$start, $end->copy()];
    }

    /**
     * โบนัสของสมาชิกสภาทุกคนในรอบ [$start, $end) ด้วยสูตรเดียวกัน
     *
     * @param  array<string, mixed>  $ast
     * @param  array<string, float>  $vars
     * @return Collection<int, array<string, mixed>>
     */
    private function compute(Carbon $start, Carbon $end, array $ast, array $vars): Collection
    {
        $seconds = $this->duty->secondsByUser($start, $end);

        $councilRoles = array_map(fn (Role $r) => $r->value, array_filter(Role::cases(), fn (Role $r) => ! $r->isAdmin() && $r->atLeast(Role::Member)));
        $users = User::query()
            ->where(fn ($q) => $q->whereIn('role', $councilRoles)->whereNull('disabled_at'))
            ->orWhereIn('id', array_keys($seconds))
            ->get()
            ->reject(fn (User $u) => $u->role->isAdmin() || in_array($u->role->value, config('council.duty.exempt_roles'), true));

        return $users->map(function (User $u) use ($seconds, $ast, $vars) {
            $minutes = intdiv((int) ($seconds[$u->id] ?? 0), 60);
            $value = PayFormula::evaluate($ast, $vars + [PayFormula::MINUTES => $minutes]);

            return ['user' => $this->userData($u), 'minutes' => $minutes, 'hours' => round($minutes / 60, 2), 'amount' => PayFormula::amount($value)];
        })->sortBy([['minutes', 'desc'], fn ($a, $b) => strcmp($a['user']['display_name'], $b['user']['display_name'])])->values();
    }

    /** @return array<string, mixed> */
    private function roundRow(CouncilPayRound $r, bool $latest): array
    {
        $items = $r->items->map(fn (CouncilPayItem $i) => [
            'user' => $i->user ? $this->userData($i->user) : null, 'minutes' => (int) $i->minutes, 'hours' => round($i->minutes / 60, 2), 'amount' => (int) $i->amount,
        ])->sortByDesc('amount')->values();

        return [
            'id' => $r->id, 'started_at' => $r->started_at->copy()->utc()->toIso8601String(), 'ended_at' => $r->ended_at->copy()->utc()->toIso8601String(),
            'formula' => (string) $r->formula, 'variables' => $r->variables ?? [],
            'note' => (string) $r->note, 'by' => $this->nameOf($r->creator), 'members' => $items->count(), 'total' => (int) $items->sum('amount'),
            'is_latest' => $latest, 'items' => $items,
        ];
    }

    /** @return array<string, mixed> */
    private function userData(User $u): array
    {
        return ['id' => $u->id, 'display_name' => $u->character_name ?: $u->name, 'role_label' => $u->role->thaiName(), 'avatar' => $u->avatar];
    }

    private function nameOf(?User $u): string
    {
        return $u ? ($u->character_name ?: $u->name) : '';
    }
}
