<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class DiscordController extends Controller
{
    public function redirect(): RedirectResponse
    {
        $scopes = ['identify'];
        if (config('council.discord_guild_id')) {
            $scopes[] = 'guilds';
        }

        return Socialite::driver('discord')->scopes($scopes)->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $discord = Socialite::driver('discord')->user();
        } catch (Throwable $e) {
            report($e);
            $this->audit('login_failed', 'เข้าสู่ระบบ Discord ไม่สำเร็จ', null, ['reason' => 'discord_failed']);

            return $this->toFrontend('login.html', ['error' => 'discord_failed']);
        }

        $discordId = (string) $discord->getId();
        $discordName = $discord->getName() ?: $discord->getNickname() ?: 'Discord User';

        if (! $this->isInGuild($discord->token)) {
            return $this->reject('not_in_guild', 'ไม่ได้อยู่ในเซิร์ฟเวอร์ Discord ของสภา', $discordId, $discordName);
        }

        $user = User::where('discord_id', $discordId)->first();
        $bootstrap = $this->bootstrapRole($discordId);

        if (! $user) {
            // ระบบปิด: ต้องมีคนในสภาเพิ่ม Discord ID นี้ไว้ก่อน (ยกเว้นบัญชีตั้งต้นใน .env)
            if (! $bootstrap && ! config('council.open_signup')) {
                return $this->reject('not_registered', 'Discord ID นี้ยังไม่ได้ถูกเพิ่มเข้าระบบ', $discordId, $discordName);
            }

            $user = new User(['discord_id' => $discordId, 'role' => $bootstrap ?? Role::Gang]);
        }

        if ($user->isDisabled()) {
            return $this->reject('disabled', 'บัญชีนี้ถูกระงับสิทธิ์', $discordId, $discordName, $user);
        }

        // บัญชีตั้งต้นใน .env: เลื่อนขึ้นถ้าสิทธิ์สูงกว่าที่มี แต่ไม่ลดหรือทับ role ที่แก้ในระบบแล้ว
        if ($bootstrap && $bootstrap->level() > $user->role->level()) {
            $user->role = $bootstrap;
        }

        $firstLogin = $user->last_login_at === null;

        $user->name = $discordName;
        $user->avatar = $discord->getAvatar();
        $user->last_login_at = now();
        $user->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        $this->audit('login', 'เข้าสู่ระบบด้วย Discord', $user, [
            'discord_id' => $user->discord_id,
            'first_login' => $firstLogin,
        ]);

        return $this->toFrontend('index.html');
    }

    public function logout(Request $request): Response
    {
        if ($user = $request->user()) {
            $this->audit('logout', 'ออกจากระบบ', $user);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /** ปฏิเสธการเข้าสู่ระบบ: บันทึก log (เก็บ Discord ID ไว้ให้สภาเห็นว่าใครพยายามเข้า) แล้วส่งกลับหน้า login */
    private function reject(string $reason, string $description, string $discordId, string $name, ?User $user = null): RedirectResponse
    {
        $this->audit('login_failed', $description, $user, [
            'reason' => $reason,
            'discord_id' => $discordId,
            'name' => $name,
        ]);

        return $this->toFrontend('login.html', ['error' => $reason] + ($reason === 'not_registered' ? ['id' => $discordId] : []));
    }

    /** บันทึกลง log ประเภท auth (ผู้กระทำ = $user หรือไม่ระบุถ้ายังไม่ล็อกอิน) */
    private function audit(string $event, string $description, ?User $user = null, array $properties = []): void
    {
        $logger = activity('auth')->event($event)->withProperties($properties);

        $user ? $logger->causedBy($user) : $logger->causedByAnonymous();
        $logger->log($description);
    }

    /** บทบาทของบัญชีตั้งต้นใน .env (null = ไม่ใช่บัญชีตั้งต้น) */
    private function bootstrapRole(string $discordId): ?Role
    {
        return match (true) {
            in_array($discordId, config('council.admin_discord_ids'), true) => Role::Admin,
            in_array($discordId, config('council.president_discord_ids'), true) => Role::President,
            in_array($discordId, config('council.vice_president_discord_ids'), true) => Role::VicePresident,
            default => null,
        };
    }

    private function isInGuild(?string $token): bool
    {
        $guildId = config('council.discord_guild_id');
        if (! $guildId) {
            return true;
        }

        $response = Http::withToken((string) $token)->get('https://discord.com/api/v10/users/@me/guilds');

        return $response->ok() && collect($response->json())->contains('id', $guildId);
    }

    private function toFrontend(string $page, array $query = []): RedirectResponse
    {
        $url = rtrim(config('council.frontend_url'), '/').'/'.$page;

        return redirect()->away($query ? $url.'?'.http_build_query($query) : $url);
    }
}
