<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PRESIDENT = '724900567637295204';

    private const PRESIDENT_2 = '509929220743495693';

    private const VICE = '891661520696659968';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'council.admin_discord_ids' => [self::PRESIDENT_2],
            'council.president_discord_ids' => [self::PRESIDENT],
            'council.vice_president_discord_ids' => [self::VICE],
            'council.discord_guild_id' => null,
            'council.open_signup' => true, // เทสต์ชุดนี้ทดสอบพฤติกรรมแบบเปิด (ระบบปิดอยู่ใน AccessControlTest)
            'council.frontend_url' => 'http://localhost:3000',
        ]);
    }

    private function fakeDiscord(string $id, string $name = 'Tester'): void
    {
        $discordUser = (new SocialiteUser)->map([
            'id' => $id,
            'nickname' => 'tester_nick',
            'name' => $name,
            'avatar' => 'https://cdn.example/a.png',
        ]);
        $discordUser->token = 'fake-token';

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($discordUser);
        Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);
    }

    public function test_new_user_gets_gang_role_and_is_logged_in(): void
    {
        $this->fakeDiscord('111');

        $this->get('/auth/discord/callback')
            ->assertRedirect('http://localhost:3000/index.html');

        $user = User::where('discord_id', '111')->firstOrFail();
        $this->assertSame(Role::Gang, $user->role);
        $this->assertSame('Tester', $user->name);
        $this->assertAuthenticatedAs($user);
    }

    public function test_president_gets_role_on_first_login(): void
    {
        $this->fakeDiscord(self::PRESIDENT);
        $this->get('/auth/discord/callback');

        $this->assertSame(Role::President, User::where('discord_id', self::PRESIDENT)->first()->role);
    }

    public function test_existing_gang_user_is_promoted_to_admin_when_env_designates_it(): void
    {
        User::factory()->create(['discord_id' => self::PRESIDENT_2]);
        $this->fakeDiscord(self::PRESIDENT_2);

        $this->get('/auth/discord/callback');

        $this->assertSame(Role::Admin, User::where('discord_id', self::PRESIDENT_2)->first()->role);
    }

    public function test_env_role_never_demotes_existing_user(): void
    {
        User::factory()->role(Role::President)->create(['discord_id' => self::VICE]);
        $this->fakeDiscord(self::VICE);

        $this->get('/auth/discord/callback');

        $this->assertSame(Role::President, User::where('discord_id', self::VICE)->first()->role);
    }

    public function test_vice_president_gets_role_on_first_login(): void
    {
        $this->fakeDiscord(self::VICE);
        $this->get('/auth/discord/callback');

        $this->assertSame(Role::VicePresident, User::where('discord_id', self::VICE)->first()->role);
    }

    public function test_existing_users_role_is_not_overwritten_on_login(): void
    {
        User::factory()->role(Role::Member)->create(['discord_id' => '222', 'name' => 'Old']);
        $this->fakeDiscord('222', 'New Name');

        $this->get('/auth/discord/callback');

        $user = User::where('discord_id', '222')->first();
        $this->assertSame(Role::Member, $user->role);
        $this->assertSame('New Name', $user->name);
    }

    public function test_user_not_in_required_guild_is_rejected(): void
    {
        config(['council.discord_guild_id' => '999']);
        \Illuminate\Support\Facades\Http::fake([
            'discord.com/*' => \Illuminate\Support\Facades\Http::response([['id' => '123']]),
        ]);
        $this->fakeDiscord('333');

        $this->get('/auth/discord/callback')
            ->assertRedirect('http://localhost:3000/login.html?error=not_in_guild');

        $this->assertDatabaseMissing('users', ['discord_id' => '333']);
        $this->assertGuest();
    }

    public function test_failed_discord_exchange_redirects_with_error(): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andThrow(new \RuntimeException('boom'));
        Socialite::shouldReceive('driver')->with('discord')->andReturn($provider);

        $this->get('/auth/discord/callback')
            ->assertRedirect('http://localhost:3000/login.html?error=discord_failed');
    }

    public function test_api_user_requires_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_api_user_returns_role(): void
    {
        $user = User::factory()->role(Role::VicePresident)->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.role', 'vice_president')
            ->assertJsonPath('data.role_label', 'Sub-CC')
            ->assertJsonPath('data.role_name', 'รองประธานสภา');
    }

    public function test_role_middleware_enforces_minimum_role(): void
    {
        Route::middleware(['auth:sanctum', 'role:vice_president'])
            ->get('/api/_test/council-only', fn () => response()->json(['ok' => true]));

        $this->actingAs(User::factory()->role(Role::Member)->create(), 'sanctum')
            ->getJson('/api/_test/council-only')->assertForbidden();

        $this->actingAs(User::factory()->role(Role::VicePresident)->create(), 'sanctum')
            ->getJson('/api/_test/council-only')->assertOk();

        $this->actingAs(User::factory()->role(Role::President)->create(), 'sanctum')
            ->getJson('/api/_test/council-only')->assertOk();
    }

    public function test_logout_ends_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->withHeader('Referer', 'http://localhost:3000')
            ->postJson('/api/logout')
            ->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_role_changes_are_written_to_activity_log(): void
    {
        $user = User::factory()->create();
        $user->update(['role' => Role::Member]);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'event' => 'updated',
        ]);
    }
}
