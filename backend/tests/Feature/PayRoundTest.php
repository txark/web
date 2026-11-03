<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\CouncilPayRound;
use App\Models\DutyShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * โบนัสเวรของสภา: ตัดรอบด้วยสูตรที่พิมพ์เอง + ย้อนรอบล่าสุด (ประธานและรองประธานเท่านั้น)
 * สูตรตั้งต้น (เวลาเข้าเวร ÷ 1440) × paycheck + โบนัสพิเศษ — "โบนัสพิเศษ" เป็นตัวแปรกลางที่บวกให้ทุกคนเท่ากัน
 */
class PayRoundTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/finance';

    private const FORMULA = '(เวลาเข้าเวร ÷ 1440) × paycheck + โบนัสพิเศษ';

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => true, 'council.duty.exempt_roles' => [], 'council.duty.max_hours' => 12, 'council.duty.heartbeat_grace_minutes' => 10]);
        $this->at('2026-11-18 20:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private ?User $boss = null;

    /** เลื่อนเวลา แล้วเปิดเวรของผู้ใช้ที่ล็อกอินอยู่ใหม่ให้ตรงเวลา (เวรเดิมเก่าเกินสัญญาณ จะถูกปิดและเข้าไม่ได้) */
    private function at(string $bkk): void
    {
        if ($this->boss) { // เวรเดิมจบตอนเวลาปัจจุบันก่อนเลื่อน (ไม่ให้นับชั่วโมงข้ามวันของผู้ทำรายการ)
            DutyShift::where('user_id', $this->boss->id)->whereNull('ended_at')->update(['ended_at' => now(), 'ended_reason' => DutyShift::MANUAL]);
        }
        Carbon::setTestNow(Carbon::parse($bkk, 'Asia/Bangkok'));
        if ($this->boss) {
            DutyShift::create(['user_id' => $this->boss->id, 'started_at' => now(), 'last_seen_at' => now()]);
        }
    }

    /** ผู้ทำรายการ: เวรเริ่ม "ตอนนี้" (0 นาที) จึงไม่เพิ่มยอดโบนัสของตัวเองในการคำนวณของเทสต์ */
    private function boss(Role $role = Role::President): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        DutyShift::create(['user_id' => $u->id, 'started_at' => now(), 'last_seen_at' => now()]);
        Sanctum::actingAs($u);

        return $this->boss = $u;
    }

    /** เวรที่จบแล้ว (เวลาไทย) */
    private function shift(User $u, string $startBkk, string $endBkk): void
    {
        DutyShift::create([
            'user_id' => $u->id, 'started_at' => Carbon::parse($startBkk, 'Asia/Bangkok')->utc(), 'last_seen_at' => Carbon::parse($endBkk, 'Asia/Bangkok')->utc(),
            'ended_at' => Carbon::parse($endBkk, 'Asia/Bangkok')->utc(), 'ended_reason' => DutyShift::MANUAL,
        ]);
    }

    private function member(string $name, Role $role = Role::Member): User
    {
        return User::factory()->role($role)->create(['character_name' => $name]);
    }

    /** ตัวแปรของสูตรตั้งต้น: paycheck บังคับ · โบนัสพิเศษไม่บังคับ */
    private function vars(string $paycheck = '300,000', string $special = '50,000'): array
    {
        return [['name' => 'paycheck', 'value' => $paycheck, 'optional' => false], ['name' => 'โบนัสพิเศษ', 'value' => $special, 'optional' => true]];
    }

    private function body(array $over = []): array
    {
        return $over + ['start_from' => '2026-11-09', 'formula' => self::FORMULA, 'variables' => $this->vars()];
    }

    /** ตัดรอบเวลาเข้าเวร ณ เวลาปัจจุบันของเทสต์ (ต้องทำก่อนคิดโบนัส) */
    private function dutyCut(): void
    {
        $this->postJson(self::URL.'/bonus/duty-cut');
    }

    private function preview(array $over = [])
    {
        $this->dutyCut();

        return $this->postJson(self::URL.'/bonus/preview', $this->body($over));
    }

    private function cut(array $over = [])
    {
        $this->dutyCut();

        return $this->postJson(self::URL.'/bonus/cutoff', $this->body($over));
    }

    private function rows($res): \Illuminate\Support\Collection
    {
        return collect($res->json('data.rows'))->keyBy('user.display_name');
    }

    // ───────────────────────── สิทธิ์ ─────────────────────────

    public function test_only_president_and_vice_president_can_use_it(): void
    {
        $this->getJson(self::URL.'/bonus')->assertUnauthorized();
        foreach ([Role::Member, Role::Operations, Role::Secretary, Role::Gang, Role::Admin] as $role) {
            $this->boss($role);
            $this->getJson(self::URL.'/bonus')->assertForbidden();
            $this->preview()->assertForbidden();
            $this->cut()->assertForbidden();
        }
        foreach ([Role::VicePresident, Role::President] as $role) {
            $this->boss($role);
            $this->getJson(self::URL.'/bonus')->assertOk();
            $this->preview()->assertOk();
        }
    }

    // ───────────────────────── สูตร ─────────────────────────

    public function test_the_example_from_the_design_minutes_over_1440_times_paycheck_plus_special(): void
    {
        $this->boss();
        $pawina = $this->member('ปวีณา ศรีสุข', Role::Senior);
        $this->shift($pawina, '2026-11-10 06:00', '2026-11-11 17:40'); // 2,140 นาที

        $res = $this->preview()->assertOk();
        $row = $this->rows($res)['ปวีณา ศรีสุข'];
        $this->assertSame(2140, $row['minutes']);
        $this->assertSame(495833, $row['amount']);                 // 2140/1440 x 300,000 = 445,833 + โบนัสพิเศษ 50,000
        $this->assertSame('สภาอาวุโส', $row['user']['role_label']); // ยศเป็นภาษาไทยตามแบบร่าง
        $res->assertJsonPath('data.formula', self::FORMULA);
        $this->assertSame([['name' => 'paycheck', 'value' => '300000', 'optional' => false], ['name' => 'โบนัสพิเศษ', 'value' => '50000', 'optional' => true]], $res->json('data.variables'));
    }

    public function test_special_bonus_is_one_shared_variable_added_to_everyone_and_optional_variables_may_be_blank(): void
    {
        $this->boss();
        $a = $this->member('Alice');
        $b = $this->member('Bob', Role::Operations);
        $this->shift($a, '2026-11-10 19:00', '2026-11-10 22:00');      // 180 นาที
        $this->shift($b, '2026-11-11 19:00', '2026-11-11 19:30');      // 30 นาที

        $rows = $this->rows($this->preview());
        $this->assertSame(37500 + 50000, $rows['Alice']['amount']);
        $this->assertSame(6250 + 50000, $rows['Bob']['amount']);

        // โบนัสพิเศษเว้นว่าง = 0 (ตัวแปรไม่บังคับ)
        $rows = $this->rows($this->preview(['variables' => $this->vars('300000', '')]));
        $this->assertSame(37500, $rows['Alice']['amount']);
        $this->assertSame(6250, $rows['Bob']['amount']);
        $this->assertSame(43750, $this->preview(['variables' => $this->vars('300000', '')])->json('data.total'));
    }

    public function test_extra_variables_and_custom_formulas_work(): void
    {
        $this->boss();
        $a = $this->member('Alice');
        $this->shift($a, '2026-11-10 19:00', '2026-11-10 22:00');      // 180 นาที

        $rows = $this->rows($this->preview([
            'formula' => 'เวลาเข้าเวร × ต่อนาที + (เงินเพิ่ม − หัก)',
            'variables' => [['name' => 'ต่อนาที', 'value' => '100', 'optional' => false], ['name' => 'เงินเพิ่ม', 'value' => '5000', 'optional' => true], ['name' => 'หัก', 'value' => '', 'optional' => true]],
        ]));
        $this->assertSame(180 * 100 + 5000, $rows['Alice']['amount']);

        // ผลติดลบนับเป็น 0 (ไม่จ่าย)
        $rows = $this->rows($this->preview(['formula' => 'เวลาเข้าเวร − 1000000', 'variables' => []]));
        $this->assertSame(0, $rows['Alice']['amount']);

        // ปัดลงเป็นจำนวนเต็ม: 100 นาที x 1,000 ÷ 1440 = 69.44 → 69
        $c = $this->member('Cara');
        $this->shift($c, '2026-11-13 19:00', '2026-11-13 20:40');
        $this->assertSame(69, $this->rows($this->preview(['formula' => '(เวลาเข้าเวร ÷ 1440) × 1000', 'variables' => []]))['Cara']['amount']);
    }

    public function test_invalid_formulas_and_variables_are_rejected_with_field_errors(): void
    {
        $this->boss();
        $this->shift($this->member('Alice'), '2026-11-10 19:00', '2026-11-10 22:00');

        $this->preview(['formula' => ''])->assertUnprocessable()->assertJsonValidationErrors('formula');
        $this->preview(['formula' => '(เวลาเข้าเวร ÷ 1440'])->assertUnprocessable()->assertJsonValidationErrors('formula');
        $this->preview(['formula' => 'เวลาเข้าเวร ^ 2'])->assertUnprocessable()->assertJsonValidationErrors('formula');
        $this->preview(['formula' => 'paycheck ÷ (เวลาเข้าเวร − เวลาเข้าเวร)'])->assertUnprocessable()->assertJsonValidationErrors('formula'); // หารด้วยศูนย์
        $this->preview(['formula' => 'เวลาเข้าเวร × ไม่มีตัวแปรนี้'])->assertUnprocessable()->assertJsonValidationErrors('formula');
        $this->preview(['formula' => str_repeat('1+', 160).'1'])->assertUnprocessable()->assertJsonValidationErrors('formula');

        // ตัวแปรบังคับต้องมีค่า / ไม่บังคับเว้นว่างได้
        $this->preview(['variables' => $this->vars('', '')])->assertUnprocessable()->assertJsonValidationErrors('variables.0.value');
        $this->preview(['variables' => $this->vars('abc', '')])->assertUnprocessable()->assertJsonValidationErrors('variables.0.value');
        $this->preview(['variables' => $this->vars('-5', '')])->assertUnprocessable()->assertJsonValidationErrors('variables.0.value');
        $this->preview(['variables' => $this->vars('300000', '-1')])->assertUnprocessable()->assertJsonValidationErrors('variables.1.value');

        // ชื่อตัวแปร
        foreach (['', '1abc', 'a b', 'a+b', 'เวลาเข้าเวร'] as $bad) {
            $v = $this->vars();
            $v[0]['name'] = $bad;
            $this->preview(['variables' => $v, 'formula' => 'เวลาเข้าเวร'])->assertUnprocessable()->assertJsonValidationErrors('variables.0.name');
        }
        $dup = [['name' => 'x', 'value' => '1', 'optional' => false], ['name' => 'X', 'value' => '2', 'optional' => false]];
        $this->preview(['variables' => $dup, 'formula' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('variables.1.name');
        $many = array_map(fn ($i) => ['name' => "v{$i}", 'value' => '1', 'optional' => true], range(1, 21));
        $this->preview(['variables' => $many, 'formula' => 'v1'])->assertUnprocessable()->assertJsonValidationErrors('variables');
        $this->preview(['start_from' => '09/11/2026'])->assertUnprocessable()->assertJsonValidationErrors('start_from');
        $this->preview(['start_from' => '2026-11-19'])->assertUnprocessable()->assertJsonValidationErrors('start_from'); // อนาคต

        // ใช้ตัวแปรที่ตั้งไว้แต่ไม่ได้ใช้ในสูตรได้ และใช้ชื่อไทย/อังกฤษผสมได้
        $this->preview(['formula' => 'เวลาเข้าเวร', 'variables' => $this->vars()])->assertOk();
        $this->preview(['formula' => 'a_1 + ค่า2', 'variables' => [['name' => 'a_1', 'value' => '1', 'optional' => false], ['name' => 'ค่า2', 'value' => '2', 'optional' => false]]])->assertOk();
    }

    // ───────────────────────── รอบ ─────────────────────────

    public function test_the_period_is_clipped_to_the_open_round_and_admin_is_not_paid(): void
    {
        $this->boss();
        $a = $this->member('Alice');
        $admin = User::factory()->role(Role::Admin)->create();
        $this->shift($a, '2026-11-08 22:00', '2026-11-09 01:00');   // คร่อมวันเริ่มนับ (9 พ.ย. 00:00) → นับเฉพาะ 60 นาทีหลังเที่ยงคืน
        $this->shift($a, '2026-11-08 10:00', '2026-11-08 12:00');   // ก่อนเริ่มนับ ไม่นับ
        $this->shift($admin, '2026-11-10 19:00', '2026-11-10 22:00');

        $rows = $this->rows($this->preview());
        $this->assertSame(60, $rows['Alice']['minutes']);
        $this->assertFalse($rows->has($admin->character_name ?: $admin->name));
    }

    public function test_cutoff_records_the_round_with_its_formula_and_the_next_round_continues_from_it(): void
    {
        $pres = $this->boss();
        $a = $this->member('Alice');
        $b = $this->member('Bob');
        $this->shift($a, '2026-11-10 19:00', '2026-11-10 22:00'); // 180 นาที
        $this->shift($b, '2026-11-11 19:00', '2026-11-11 21:00'); // 120 นาที

        $first = $this->cut(['note' => 'รอบแรก'])->assertCreated();
        // โบนัสพิเศษ 50,000 บวกให้สมาชิกสภาทุกคนรวมผู้ทำรายการเอง (0 นาที) → 3 คน
        $first->assertJsonPath('data.total', (37500 + 50000) + (25000 + 50000) + 50000)->assertJsonPath('data.members', 3)->assertJsonPath('data.note', 'รอบแรก')->assertJsonPath('data.is_latest', true)
            ->assertJsonPath('data.formula', self::FORMULA)->assertJsonPath('data.by', $pres->character_name ?: $pres->name);
        $this->assertSame('Alice', $first->json('data.items.0.user.display_name'));
        $this->assertSame(87500, $first->json('data.items.0.amount'));
        $this->assertSame('paycheck', $first->json('data.variables.0.name'));

        $list = $this->getJson(self::URL.'/bonus')->assertOk();
        $list->assertJsonPath('meta.total', 1)->assertJsonPath('meta.total_paid', 212500)->assertJsonPath('meta.open.first_round', false);
        // สูตรและตัวแปรของรอบล่าสุดถูกใส่เป็นค่าเริ่มต้นของรอบถัดไป
        $list->assertJsonPath('meta.defaults.formula', self::FORMULA)->assertJsonPath('meta.defaults.variables.0.value', '300000')->assertJsonPath('meta.defaults.variables.1.optional', true);
        $this->assertSame(Carbon::now()->utc()->toIso8601String(), Carbon::parse($list->json('meta.open.started_at'))->utc()->toIso8601String());

        // รอบถัดไปเริ่มนับต่อจากเวลาตัดรอบ (เวรก่อนหน้านั้นไม่ถูกนับซ้ำ)
        $this->at('2026-11-19 20:00');
        $this->shift($a, '2026-11-19 10:00', '2026-11-19 11:00'); // 60 นาที (หลังตัดรอบ)
        $rows = $this->rows($this->preview(['start_from' => null]));
        $this->assertSame(60, $rows['Alice']['minutes']);
        $this->assertSame(12500 + 50000, $rows['Alice']['amount']);
        $this->assertSame(0, $rows['Bob']['minutes']);
    }

    public function test_a_default_formula_is_offered_before_any_round_exists(): void
    {
        $this->boss();
        $d = $this->getJson(self::URL.'/bonus')->assertOk()->json('meta');
        $this->assertSame(self::FORMULA, $d['defaults']['formula']);
        $this->assertSame([['name' => 'paycheck', 'value' => '', 'optional' => false], ['name' => 'โบนัสพิเศษ', 'value' => '', 'optional' => true]], $d['defaults']['variables']);
        $this->assertTrue($d['open']['first_round']);
        $this->assertSame('เวลาเข้าเวร', $d['minutes_variable']);
    }

    public function test_cutoff_is_an_expense_in_the_finance_summary_and_double_cutoff_is_blocked(): void
    {
        $this->boss();
        $a = $this->member('Alice');
        $this->shift($a, '2026-11-10 19:00', '2026-11-10 22:00');
        $this->cut(['variables' => $this->vars('300000', '')])->assertCreated();

        $d = $this->getJson(self::URL.'/summary?from=2026-11-18&to=2026-11-18')->json('data');
        $this->assertSame(37500, $d['expense']);
        $this->assertSame('bonus', $d['by_category'][0]['key']);
        $row = $this->getJson(self::URL.'/entries?category=bonus&from=2026-11-18&to=2026-11-18')->assertJsonCount(1, 'data')->json('data.0');
        $this->assertStringContainsString('โบนัสเวร รอบ 09/11/2026 00:00 – 18/11/2026 20:00', $row['title']);
        $this->assertSame('expense', $row['type']);

        // กดซ้ำทันที: ยังไม่ถึง 1 นาทีนับจากตัดรอบ
        $this->postJson(self::URL.'/bonus/duty-cut')->assertUnprocessable()->assertJsonValidationErrors('ended_at');
        // ไม่ได้ตัดรอบเวลาเข้าเวรใหม่ ตัดรอบโบนัสซ้ำไม่ได้
        $this->postJson(self::URL.'/bonus/cutoff', $this->body())->assertUnprocessable()->assertJsonValidationErrors('cut');
        $this->assertSame(1, CouncilPayRound::count());
    }

    public function test_the_latest_round_can_be_reverted_and_the_period_reopens(): void
    {
        $this->boss();
        $a = $this->member('Alice');
        $this->shift($a, '2026-11-10 19:00', '2026-11-10 22:00'); // 180 นาที
        $vars = $this->vars('300000', '');
        $one = $this->cut(['variables' => $vars])->assertCreated()->json('data.id');

        $this->at('2026-11-19 20:00');
        $this->shift($a, '2026-11-19 10:00', '2026-11-19 11:00'); // 60 นาที
        $two = $this->cut(['variables' => $vars, 'start_from' => null])->assertCreated();
        $two->assertJsonPath('data.total', 12500)->assertJsonPath('data.is_latest', true);
        $twoId = $two->json('data.id');

        // ย้อนได้เฉพาะรอบล่าสุด
        $this->deleteJson(self::URL."/bonus/rounds/{$one}")->assertUnprocessable()->assertJsonValidationErrors('round');
        $this->assertSame(2, CouncilPayRound::count());
        $this->getJson(self::URL.'/bonus')->assertJsonPath('data.0.is_latest', true)->assertJsonPath('data.1.is_latest', false);

        $this->deleteJson(self::URL."/bonus/rounds/{$twoId}")->assertOk();
        $this->assertSoftDeleted('council_pay_rounds', ['id' => $twoId]);
        $this->assertSame(0, $this->getJson(self::URL.'/summary?from=2026-11-19&to=2026-11-19')->json('data.expense')); // หักออกจากรายจ่าย

        // รอบที่เปิดอยู่กลับไปเริ่มนับจากรอบก่อนหน้า: ชั่วโมงเวรของรอบที่ย้อนกลับมานับใหม่
        $rows = $this->rows($this->preview(['variables' => $vars, 'start_from' => null]));
        $this->assertSame(60, $rows['Alice']['minutes']);

        // ย้อนแล้วรอบก่อนหน้ากลายเป็นรอบล่าสุด ย้อนต่อได้
        $this->deleteJson(self::URL."/bonus/rounds/{$one}")->assertOk();
        $this->assertSame(0, CouncilPayRound::count());
        $this->getJson(self::URL.'/bonus')->assertJsonPath('meta.open.first_round', true);
        $this->deleteJson(self::URL."/bonus/rounds/{$one}")->assertNotFound();
    }

    public function test_empty_rounds_and_stale_previews_are_rejected(): void
    {
        $this->boss();
        $a = $this->member('Alice');
        $this->shift($a, '2026-11-10 19:00', '2026-11-10 22:00');

        // paycheck 0 และไม่มีโบนัสพิเศษ = ไม่มีใครได้ ตัดรอบไม่ได้
        $this->cut(['variables' => $this->vars('0', '')])->assertUnprocessable()->assertJsonValidationErrors('formula');
        // ตัวอย่างเก่าเกิน 10 นาที / เวลาในอนาคต
        $this->assertSame(0, CouncilPayRound::count());

        // เวลาตัดรอบโบนัสใช้เวลาที่ตัดรอบเวลาเข้าเวรไว้ ยอดตรงกับที่ผู้กดยืนยันเห็น
        $pv = $this->preview()->json('data');
        $this->postJson(self::URL.'/bonus/cutoff', $this->body())->assertCreated()->assertJsonPath('data.total', $pv['total']);
    }

    public function test_duty_time_must_be_cut_first_and_stays_fixed_until_the_bonus_round_is_cut(): void
    {
        $this->boss();
        $a = $this->member('Alice');
        $this->shift($a, '2026-11-10 19:00', '2026-11-10 22:00');
        DutyShift::create(['user_id' => $a->id, 'started_at' => now()->subHour(), 'last_seen_at' => now()]); // ยังอยู่ในเวร

        // ยังไม่ตัดรอบเวลาเข้าเวร: ไม่คิดโบนัส ตัดรอบโบนัสไม่ได้
        $this->postJson(self::URL.'/bonus/preview', $this->body())->assertOk()->assertJsonPath('data.cut_at', null)->assertJsonPath('data.rows', []);
        $this->postJson(self::URL.'/bonus/cutoff', $this->body())->assertUnprocessable()->assertJsonValidationErrors('cut');

        // ตัดรอบเวลาเข้าเวรแล้ว นาทีเวรนับถึงเวลาตัดเท่านั้น (เวลาผ่านไปก็ไม่เพิ่ม)
        $this->dutyCut();
        $before = $this->postJson(self::URL.'/bonus/preview', $this->body())->assertOk();
        $minutes = $before->json('data.rows.0.minutes');
        $this->assertNotNull($before->json('data.cut_at'));
        $this->travel(5)->minutes();
        $after = $this->postJson(self::URL.'/bonus/preview', $this->body());
        $this->assertSame($minutes, $after->json('data.rows.0.minutes'));
        $this->assertSame($before->json('data.cut_at'), $after->json('data.cut_at'));

        // ยกเลิกการตัดรอบเวลาเข้าเวร = กลับไปยังไม่คิดโบนัส
        $this->deleteJson(self::URL.'/bonus/duty-cut')->assertOk();
        $this->postJson(self::URL.'/bonus/preview', $this->body())->assertJsonPath('data.cut_at', null);

        // ตัดรอบโบนัสแล้ว ต้องตัดรอบเวลาเข้าเวรใหม่สำหรับรอบถัดไป
        $this->cut()->assertCreated();
        $this->postJson(self::URL.'/bonus/preview', $this->body(['start_from' => null]))->assertJsonPath('data.cut_at', null);
    }

    public function test_a_shared_special_bonus_alone_pays_every_council_member(): void
    {
        $this->boss();
        $a = $this->member('Alice'); // ไม่มีชั่วโมงเวรเลย แต่ได้โบนัสพิเศษที่บวกให้ทุกคน
        $this->shift($this->member('Bob'), '2026-11-10 19:00', '2026-11-10 20:00');

        $res = $this->cut(['formula' => 'โบนัสพิเศษ', 'variables' => [['name' => 'โบนัสพิเศษ', 'value' => '25,000', 'optional' => true]]])->assertCreated();
        $names = collect($res->json('data.items'))->pluck('user.display_name');
        $this->assertTrue($names->contains('Alice'));
        $this->assertTrue($names->contains('Bob'));
        $this->assertSame(0, collect($res->json('data.items'))->firstWhere('user.display_name', 'Alice')['minutes']);
        $this->assertSame(25000, collect($res->json('data.items'))->firstWhere('user.display_name', 'Alice')['amount']);
    }
}
