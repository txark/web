<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** ระบบปิด: ล็อกอินได้เฉพาะ Discord ID ที่สภาเพิ่มไว้ + การจัดการผู้ใช้ */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private const ID = '123456789012345678';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'council.open_signup' => false,
            'council.admin_discord_ids' => [],
            'council.president_discord_ids' => [],
            'council.vice_president_discord_ids' => [],
            'council.discord_guild_id' => null,
            'council.frontend_url' => 'http://localhost:3000',
        ]);
    }

    private function fakeDiscord(string $id, string $name = 'DiscordName'): void
    {
        $u = (new SocialiteUser)->map(['id' => $id, 'nickname' => 'nick', 'name' => $name, 'avatar' => 'https://cdn.example/a.png']);
        $u->token = 'fake';

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($u);
        Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
    }

    private function as(Role $role, array $attrs = []): User
    {
        return User::factory()->role($role)->loggedIn()->create($attrs);
    }

    // ───────── ล็อกอินแบบระบบปิด ─────────

    public function test_unregistered_discord_id_cannot_log_in_and_is_logged(): void
    {
        $this->fakeDiscord(self::ID, 'Stranger');

        $this->get('/auth/discord/callback')
            ->assertRedirect('http://localhost:3000/login.html?error=not_registered&id='.self::ID);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['discord_id' => self::ID]);

        $log = Activity::where('log_name', 'auth')->where('event', 'login_failed')->firstOrFail();
        $this->assertSame('not_registered', $log->properties['reason']);
        $this->assertSame(self::ID, $log->properties['discord_id']); // ให้สภาเห็นว่า ID ไหนพยายามเข้า
        $this->assertSame('Stranger', $log->properties['name']);
    }

    public function test_pre_registered_user_logs_in_keeping_role_and_character_name(): void
    {
        $added = User::factory()->role(Role::Family)->create([
            'discord_id' => self::ID, 'name' => 'ชื่อชั่วคราว', 'character_name' => 'Somchai K.',
        ]);
        $this->fakeDiscord(self::ID, 'RealDiscordName');

        $this->get('/auth/discord/callback')->assertRedirect('http://localhost:3000/index.html');

        $this->assertAuthenticatedAs($added->fresh());
        $user = $added->fresh();
        $this->assertSame(Role::Family, $user->role);                 // บทบาทที่สภากำหนดไม่ถูกทับ
        $this->assertSame('Somchai K.', $user->character_name);       // ชื่อตัวละครที่สภาใส่ไว้ยังอยู่
        $this->assertSame('RealDiscordName', $user->name);            // ชื่อแสดงอัปเดตจาก Discord
        $this->assertSame('https://cdn.example/a.png', $user->avatar);
        $this->assertNotNull($user->last_login_at);
    }

    public function test_first_login_flag_is_true_only_once(): void
    {
        User::factory()->create(['discord_id' => self::ID]);

        $this->fakeDiscord(self::ID);
        $this->get('/auth/discord/callback');
        auth()->logout();
        $this->get('/auth/discord/callback');

        $flags = Activity::where('event', 'login')->orderBy('id')->get()->map(fn ($a) => $a->properties['first_login'])->all();
        $this->assertSame([true, false], $flags);
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        $user = User::factory()->disabled()->create(['discord_id' => self::ID]);
        $this->fakeDiscord(self::ID);

        $this->get('/auth/discord/callback')
            ->assertRedirect('http://localhost:3000/login.html?error=disabled');

        $this->assertGuest();
        $log = Activity::where('event', 'login_failed')->firstOrFail();
        $this->assertSame('disabled', $log->properties['reason']);
        $this->assertSame($user->id, $log->causer_id);
    }

    public function test_env_bootstrap_accounts_can_log_in_without_being_added(): void
    {
        config(['council.president_discord_ids' => [self::ID]]);
        $this->fakeDiscord(self::ID);

        $this->get('/auth/discord/callback')->assertRedirect('http://localhost:3000/index.html');

        $this->assertSame(Role::President, User::where('discord_id', self::ID)->firstOrFail()->role);
    }

    public function test_bootstrap_promotion_never_demotes_an_existing_account(): void
    {
        config(['council.vice_president_discord_ids' => [self::ID]]);
        User::factory()->role(Role::President)->create(['discord_id' => self::ID]);
        $this->fakeDiscord(self::ID);

        $this->get('/auth/discord/callback');

        $this->assertSame(Role::President, User::where('discord_id', self::ID)->firstOrFail()->role);
    }

    public function test_open_signup_switch_allows_unknown_ids_as_gang(): void
    {
        config(['council.open_signup' => true]);
        $this->fakeDiscord(self::ID);

        $this->get('/auth/discord/callback')->assertRedirect('http://localhost:3000/index.html');

        $this->assertSame(Role::Gang, User::where('discord_id', self::ID)->firstOrFail()->role);
    }

    public function test_session_of_a_disabled_user_stops_working(): void
    {
        $user = User::factory()->disabled()->create();

        $this->actingAs($user)->getJson('/api/user')->assertForbidden();
    }

    // ───────── API จัดการผู้ใช้: สิทธิ์ ─────────

    public function test_only_vice_president_and_above_can_manage_users(): void
    {
        foreach ([Role::Gang, Role::Family, Role::Member] as $role) {
            $this->actingAs($this->as($role))->getJson('/api/users')->assertForbidden();
            $this->actingAs($this->as($role))->postJson('/api/users', [])->assertForbidden();
        }
        foreach ([Role::VicePresident, Role::President, Role::Admin] as $role) {
            $this->actingAs($this->as($role))->getJson('/api/users')->assertOk();
        }
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/users')->assertUnauthorized();
    }

    public function test_assignable_roles_are_strictly_below_the_actor(): void
    {
        $values = fn (Role $role) => collect($this->actingAs($this->as($role))->getJson('/api/users')->json('assignable_roles'))->pluck('value')->all();

        $this->assertSame('member', $values(Role::VicePresident)[0]); // เรียงสิทธิ์สูง→ต่ำ
        $this->assertEqualsCanonicalizing(['member', 'gang', 'family'], $values(Role::VicePresident));
        $this->assertEqualsCanonicalizing(['vice_president', 'member', 'gang', 'family'], $values(Role::President));
        $this->assertEqualsCanonicalizing(['president', 'vice_president', 'member', 'gang', 'family'], $values(Role::Admin));
    }

    // ───────── เพิ่มผู้ใช้ ─────────

    public function test_vice_president_adds_a_discord_id_with_a_role(): void
    {
        $vp = $this->as(Role::VicePresident);

        $res = $this->actingAs($vp)->postJson('/api/users', [
            'discord_id' => self::ID, 'character_name' => 'Somchai K.', 'role' => 'family',
        ])->assertCreated();

        $res->assertJsonPath('data.role', 'family')
            ->assertJsonPath('data.role_label', 'Family')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.added_by.id', $vp->id);

        $user = User::where('discord_id', self::ID)->firstOrFail();
        $this->assertSame('Somchai K.', $user->name);
        $this->assertSame($vp->id, $user->added_by);

        $log = Activity::where('subject_type', User::class)->where('subject_id', $user->id)->where('event', 'created')->firstOrFail();
        $this->assertSame($vp->id, $log->causer_id); // ใครเพิ่ม อยู่ใน log
    }

    public function test_cannot_grant_a_role_equal_or_above_your_own(): void
    {
        $payload = fn (string $role) => ['discord_id' => (string) fake()->unique()->numerify('##################'), 'character_name' => 'X', 'role' => $role];

        $this->actingAs($this->as(Role::VicePresident))->postJson('/api/users', $payload('vice_president'))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->actingAs($this->as(Role::VicePresident))->postJson('/api/users', $payload('admin'))->assertUnprocessable();

        $this->actingAs($this->as(Role::President))->postJson('/api/users', $payload('vice_president'))->assertCreated();
        $this->actingAs($this->as(Role::President))->postJson('/api/users', $payload('president'))->assertUnprocessable();

        $this->actingAs($this->as(Role::Admin))->postJson('/api/users', $payload('president'))->assertCreated();
        $this->actingAs($this->as(Role::Admin))->postJson('/api/users', $payload('admin'))->assertUnprocessable();
    }

    public function test_store_validation_has_thai_messages(): void
    {
        $vp = $this->as(Role::VicePresident);
        User::factory()->create(['discord_id' => self::ID]);

        $this->actingAs($vp)->postJson('/api/users', ['discord_id' => 'abc', 'character_name' => 'A', 'role' => 'gang'])
            ->assertUnprocessable()->assertJsonPath('errors.discord_id.0', 'Discord ID ต้องเป็นตัวเลข 17–20 หลัก');

        $this->actingAs($vp)->postJson('/api/users', ['discord_id' => self::ID, 'character_name' => 'A', 'role' => 'gang'])
            ->assertUnprocessable()->assertJsonPath('errors.discord_id.0', 'Discord ID นี้อยู่ในระบบแล้ว');

        $this->actingAs($vp)->postJson('/api/users', ['discord_id' => '98765432109876543', 'role' => 'gang'])
            ->assertUnprocessable()->assertJsonPath('errors.character_name.0', 'กรุณากรอกชื่อตัวละคร');

        $this->actingAs($vp)->postJson('/api/users', ['discord_id' => '98765432109876543', 'character_name' => 'A', 'role' => 'nope'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
    }

    // ───────── แก้ไข / ระงับ / ลบ ─────────

    public function test_change_role_is_logged_with_old_and_new_value_and_who(): void
    {
        $vp = $this->as(Role::VicePresident);
        $target = $this->as(Role::Gang);

        $this->actingAs($vp)->patchJson("/api/users/{$target->id}", ['role' => 'member'])
            ->assertOk()->assertJsonPath('data.role', 'member');

        $log = Activity::where('subject_id', $target->id)->where('subject_type', User::class)->where('event', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($vp->id, $log->causer_id);
        $this->assertSame('gang', $log->attribute_changes['old']['role']);
        $this->assertSame('member', $log->attribute_changes['attributes']['role']);
    }

    public function test_cannot_edit_yourself_or_peers_or_superiors(): void
    {
        $vp = $this->as(Role::VicePresident);

        $this->actingAs($vp)->patchJson("/api/users/{$vp->id}", ['role' => 'member'])->assertForbidden();
        $this->actingAs($vp)->patchJson('/api/users/'.$this->as(Role::VicePresident)->id, ['disabled' => true])->assertForbidden();
        $this->actingAs($vp)->patchJson('/api/users/'.$this->as(Role::President)->id, ['role' => 'gang'])->assertForbidden();
        $this->actingAs($vp)->deleteJson('/api/users/'.$this->as(Role::Admin)->id)->assertForbidden();
    }

    public function test_cannot_promote_someone_to_your_own_level(): void
    {
        $vp = $this->as(Role::VicePresident);
        $target = $this->as(Role::Member);

        $this->actingAs($vp)->patchJson("/api/users/{$target->id}", ['role' => 'vice_president'])->assertUnprocessable();
        $this->assertSame(Role::Member, $target->fresh()->role);
    }

    public function test_disabling_revokes_tokens_and_sessions_and_blocks_login_until_re_enabled(): void
    {
        $vp = $this->as(Role::VicePresident);
        $target = $this->as(Role::Gang, ['discord_id' => self::ID]);
        $target->createToken('t');
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($vp)->patchJson("/api/users/{$target->id}", ['disabled' => true])
            ->assertOk()->assertJsonPath('data.status', 'disabled');

        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());

        $this->fakeDiscord(self::ID);
        $this->get('/auth/discord/callback')->assertRedirect('http://localhost:3000/login.html?error=disabled');

        $this->actingAs($vp)->patchJson("/api/users/{$target->id}", ['disabled' => false])->assertOk()->assertJsonPath('data.status', 'active');
        $this->assertNull($target->fresh()->disabled_at);
    }

    public function test_only_never_logged_in_users_can_be_deleted(): void
    {
        $vp = $this->as(Role::VicePresident);
        $pending = User::factory()->create(); // เพิ่มไว้แต่ยังไม่เคยเข้าระบบ
        $veteran = $this->as(Role::Gang);

        $this->actingAs($vp)->deleteJson("/api/users/{$pending->id}")->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $pending->id]);

        $this->actingAs($vp)->deleteJson("/api/users/{$veteran->id}")->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $veteran->id]);
    }

    // ───────── รายการ / ตัวกรอง ─────────

    public function test_list_filters_search_and_ordering(): void
    {
        $vp = $this->as(Role::VicePresident, ['name' => 'Vee']);
        $this->as(Role::Admin, ['name' => 'Ada']);
        $this->as(Role::Gang, ['name' => 'Gus', 'discord_id' => '111111111111111111']);
        User::factory()->create(['name' => 'Pending Pat', 'character_name' => 'Pat C.']);          // ยังไม่เคยเข้า
        User::factory()->disabled()->loggedIn()->create(['name' => 'Dan Disabled']);

        $this->actingAs($vp);
        $names = fn (string $qs) => collect($this->getJson("/api/users?{$qs}")->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame('Ada', $names('')[0]);                       // สิทธิ์สูงสุดมาก่อน
        $this->assertSame(['Pending Pat'], $names('status=pending'));
        $this->assertSame(['Dan Disabled'], $names('status=disabled'));
        $this->assertEqualsCanonicalizing(['Ada', 'Vee', 'Gus'], $names('status=active'));
        $this->assertSame(['Gus'], $names('role=gang&status=active'));
        $this->assertSame(['Gus'], $names('q=111111'));                // ค้นด้วย Discord ID
        $this->assertSame(['Pending Pat'], $names('q='.urlencode('Pat C.')));  // ค้นด้วยชื่อตัวละคร
        $this->getJson('/api/users?role=bogus')->assertUnprocessable();
        $this->getJson('/api/users?per_page=999')->assertUnprocessable();
    }

    public function test_can_manage_flag_reflects_who_the_actor_may_edit(): void
    {
        $vp = $this->as(Role::VicePresident, ['name' => 'Vee']);
        $this->as(Role::Member, ['name' => 'Mia']);
        $this->as(Role::President, ['name' => 'Pru']);

        $rows = collect($this->actingAs($vp)->getJson('/api/users')->json('data'))->pluck('can_manage', 'name');

        $this->assertTrue($rows['Mia']);
        $this->assertFalse($rows['Vee']);  // ตัวเอง
        $this->assertFalse($rows['Pru']);  // สูงกว่า
    }

    public function test_viewing_the_user_list_is_logged(): void
    {
        $this->actingAs($this->as(Role::VicePresident))->getJson('/api/users')->assertOk();

        $this->assertTrue(Activity::where('log_name', 'access')->where('description', 'เปิดดู: จัดการผู้ใช้')->exists());
    }
}
