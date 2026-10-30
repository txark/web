<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Group;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'council.president_discord_ids' => [],
            'council.vice_president_discord_ids' => [],
            'council.admin_discord_ids' => [],
            'council.discord_guild_id' => null,
            'council.open_signup' => true,
            'council.frontend_url' => 'http://localhost:3000',
        ]);
    }

    private function fakeDiscord(string $id, string $name = 'Tester'): void
    {
        $u = (new SocialiteUser)->map(['id' => $id, 'nickname' => 'nick', 'name' => $name, 'avatar' => null]);
        $u->token = 'fake';

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($u);
        Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
    }

    private function logs(string $name, ?string $event = null)
    {
        return Activity::where('log_name', $name)->when($event, fn ($q) => $q->where('event', $event));
    }

    // ───────── A: เข้า/ออกระบบ ─────────

    public function test_login_is_logged_with_user_ip_and_first_login_flag(): void
    {
        $this->fakeDiscord('111', 'Alice');

        $this->get('/auth/discord/callback');

        $log = $this->logs('auth', 'login')->firstOrFail();
        $user = User::where('discord_id', '111')->firstOrFail();

        $this->assertSame($user->id, $log->causer_id);
        $this->assertSame('111', $log->properties['discord_id']);
        $this->assertTrue($log->properties['first_login']);
        $this->assertNotEmpty($log->properties['ip']);
    }

    public function test_second_login_is_not_marked_as_first_login(): void
    {
        User::factory()->create(['discord_id' => '222', 'last_login_at' => now()->subDay()]);
        $this->fakeDiscord('222');

        $this->get('/auth/discord/callback');

        $this->assertFalse($this->logs('auth', 'login')->firstOrFail()->properties['first_login']);
    }

    public function test_failed_discord_exchange_is_logged_anonymously(): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('boom'));
        Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

        $this->get('/auth/discord/callback');

        $log = $this->logs('auth', 'login_failed')->firstOrFail();
        $this->assertNull($log->causer_id);
        $this->assertSame('discord_failed', $log->properties['reason']);
        $this->assertNotEmpty($log->properties['ip']);
    }

    public function test_rejected_non_member_is_logged_with_discord_id_but_no_account_is_created(): void
    {
        config(['council.discord_guild_id' => '999']);
        \Illuminate\Support\Facades\Http::fake(['discord.com/*' => \Illuminate\Support\Facades\Http::response([['id' => '1']])]);
        $this->fakeDiscord('333', 'Stranger');

        $this->get('/auth/discord/callback');

        $log = $this->logs('auth', 'login_failed')->firstOrFail();
        $this->assertSame('not_in_guild', $log->properties['reason']);
        $this->assertSame('333', $log->properties['discord_id']);
        $this->assertDatabaseMissing('users', ['discord_id' => '333']);
    }

    public function test_logout_is_logged_with_the_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withHeader('Referer', 'http://localhost:3000')->postJson('/api/logout')->assertNoContent();

        $this->assertSame($user->id, $this->logs('auth', 'logout')->firstOrFail()->causer_id);
    }

    // ───────── D: การแก้ไขข้อมูลระบุผู้กระทำ ─────────

    public function test_model_changes_record_who_did_it_and_from_where(): void
    {
        $group = Group::factory()->create(['name' => 'Before']);
        $admin = User::factory()->role(Role::Admin)->create();

        $this->actingAs($admin);
        $group->update(['name' => 'After']);

        $log = $this->logs('default', 'updated')->where('subject_type', Group::class)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->causer_id);
        $this->assertSame('After', $log->attribute_changes['attributes']['name']);
        $this->assertSame('Before', $log->attribute_changes['old']['name']);
        $this->assertNotEmpty($log->properties['ip']);
    }

    public function test_model_changes_made_with_an_api_token_also_record_the_causer(): void
    {
        $group = Group::factory()->create(['name' => 'Before']);
        $vp = User::factory()->role(Role::VicePresident)->create();

        Sanctum::actingAs($vp); // ยืนยันตัวตนด้วยโทเค็น ไม่มี session ของเว็บ
        $group->update(['name' => 'After']);

        $log = $this->logs('default', 'updated')->where('subject_type', Group::class)->latest('id')->firstOrFail();
        $this->assertSame($vp->id, $log->causer_id);
    }

    // ───────── B: การเข้าดู / ถูกปฏิเสธ ─────────

    public function test_denied_access_is_logged_for_logged_in_users_only(): void
    {
        $this->getJson('/api/logs')->assertUnauthorized();
        $this->assertSame(0, $this->logs('access')->count());

        $member = User::factory()->role(Role::Member)->create();
        $this->actingAs($member)->getJson('/api/logs')->assertForbidden();

        $log = $this->logs('access', 'denied')->firstOrFail();
        $this->assertSame($member->id, $log->causer_id);
        $this->assertSame('vice_president', $log->properties['required_role']);
        $this->assertSame('/api/logs', $log->properties['path']);
    }

    public function test_viewing_the_logs_is_itself_logged_once_per_five_minutes(): void
    {
        $vp = User::factory()->role(Role::VicePresident)->create();

        $this->actingAs($vp)->getJson('/api/logs')->assertOk();
        $this->actingAs($vp)->getJson('/api/logs')->assertOk();
        $this->actingAs($vp)->getJson('/api/logs?type=auth')->assertOk(); // path เดิม (query ไม่นับ)

        $this->assertSame(1, $this->logs('access', 'view')->count());
        $view = $this->logs('access', 'view')->firstOrFail();
        $this->assertSame($vp->id, $view->causer_id);
        $this->assertSame('เปิดดู: บันทึกกิจกรรม', $view->description);
    }

    // ───────── สิทธิ์ดู log ─────────

    public function test_only_vice_president_and_above_can_read_logs(): void
    {
        foreach ([Role::Gang, Role::Member] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->getJson('/api/logs')->assertForbidden();
        }
        foreach ([Role::VicePresident, Role::President, Role::Admin] as $role) {
            $this->actingAs(User::factory()->role($role)->create())->getJson('/api/logs')->assertOk();
        }
    }

    public function test_log_entry_shape_has_labels_causer_and_changes(): void
    {
        $vp = User::factory()->role(Role::VicePresident)->create(['name' => 'Vee']);
        $this->actingAs($vp);
        Group::factory()->create(['name' => 'Shape Test']);

        $row = collect($this->getJson('/api/logs?type=changes')->assertOk()->json('data'))
            ->firstWhere('subject.label', 'แก๊ง/ครอบครัว');

        $this->assertSame('created', $row['event']);
        $this->assertSame('สร้าง แก๊ง/ครอบครัว', $row['description']);
        $this->assertSame('สร้าง', $row['event_label']);
        $this->assertSame('แก้ไขข้อมูล', $row['log_label']);
        $this->assertSame('Vee', $row['causer']['name']);
        $this->assertSame('Sub-CC', $row['causer']['role_label']);
        $this->assertSame('รองประธานสภา', $row['causer']['role_name']);
        $this->assertNotNull($row['created_at']);
    }

    // ───────── ตัวกรอง ─────────

    public function test_filters_by_type_event_user_and_search(): void
    {
        $vp = User::factory()->role(Role::VicePresident)->create();
        $alice = User::factory()->create(['name' => 'Alice Wonder']);
        $bob = User::factory()->create(['name' => 'Bob Builder']);

        activity('auth')->causedBy($alice)->event('login')->log('เข้าสู่ระบบด้วย Discord');
        activity('auth')->causedBy($bob)->event('logout')->log('ออกจากระบบ');
        activity('access')->causedBy($bob)->event('view')->log('เปิดดู: รายงานค่าใช้จ่าย');

        $this->actingAs($vp);
        $ids = fn (string $qs) => collect($this->getJson("/api/logs?{$qs}")->assertOk()->json('data'))->pluck('description')->all();

        $this->assertEqualsCanonicalizing(['เข้าสู่ระบบด้วย Discord', 'ออกจากระบบ'], $ids('type=auth'));
        $this->assertSame(['ออกจากระบบ'], $ids('event=logout'));
        $this->assertEqualsCanonicalizing(['ออกจากระบบ', 'เปิดดู: รายงานค่าใช้จ่าย'], $ids("user_id={$bob->id}"));
        $this->assertSame(['เปิดดู: รายงานค่าใช้จ่าย'], $ids('q='.urlencode('ค่าใช้จ่าย')));
        $this->assertSame(['เข้าสู่ระบบด้วย Discord'], $ids('q=Alice'));      // ค้นจากชื่อผู้กระทำ
        $this->assertSame([], $ids('q=ไม่มีแน่นอน'));
    }

    public function test_date_filter_uses_thai_calendar_days(): void
    {
        $vp = User::factory()->role(Role::VicePresident)->create();

        // 01:30 เวลาไทยของวันที่ 28 (= 18:30 UTC ของวันที่ 27)  และ  23:30 เวลาไทยของวันที่ 27
        activity('auth')->event('login')->createdAt(now()->setDate(2026, 10, 27)->setTime(18, 30))->log('A-28th-early');
        activity('auth')->event('login')->createdAt(now()->setDate(2026, 10, 27)->setTime(16, 30))->log('B-27th-late');

        $this->actingAs($vp);
        $names = fn (string $qs) => collect($this->getJson("/api/logs?type=auth&{$qs}")->json('data'))->pluck('description')->all();

        $this->assertSame(['A-28th-early'], $names('from=2026-10-28&to=2026-10-28'));
        $this->assertSame(['B-27th-late'], $names('from=2026-10-27&to=2026-10-27'));
    }

    public function test_validation_and_pagination(): void
    {
        $this->actingAs(User::factory()->role(Role::VicePresident)->create());

        $this->getJson('/api/logs?type=bogus')->assertUnprocessable();
        $this->getJson('/api/logs?per_page=500')->assertUnprocessable();
        $this->getJson('/api/logs?from=28/10/2026')->assertUnprocessable();

        foreach (range(1, 5) as $i) {
            activity('auth')->event('login')->log("row {$i}");
        }
        $this->getJson('/api/logs?type=auth&per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5);
    }

    // ───────── อายุการเก็บ ─────────

    public function test_clean_command_removes_only_logs_older_than_retention(): void
    {
        $this->assertSame(90, config('activitylog.clean_after_days'));

        activity('auth')->event('login')->createdAt(now()->subDays(120))->log('old');
        activity('auth')->event('login')->createdAt(now()->subDays(10))->log('recent');

        $this->artisan('activitylog:clean', ['--force' => true])->assertSuccessful();

        $left = Activity::where('log_name', 'auth')->pluck('description')->all();
        $this->assertSame(['recent'], $left);
    }

    public function test_cleanup_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command.' @ '.$e->expression);

        $this->assertTrue($events->contains(fn ($e) => str_contains($e, 'activitylog:clean') && str_ends_with($e, '30 3 * * *')));
    }
}
