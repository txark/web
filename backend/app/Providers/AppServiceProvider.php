<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Support\CauserResolver;
use SocialiteProviders\Discord\DiscordExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // กันกดรัว/เรียกซ้ำ: จำกัดการเขียนข้อมูลต่อผู้ใช้ต่อนาที (คำขอที่แนบไฟล์จำกัดเข้มกว่า) · ยกเว้นการเปิดดูและสัญญาณเวร
        RateLimiter::for('writes', function (Request $request) {
            if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) || $request->is('api/duty/heartbeat')) {
                return Limit::none();
            }
            $who = $request->user()?->getAuthIdentifier() ?? $request->ip();
            $limits = [Limit::perMinute(config('council.rate.writes'))->by("w:{$who}")];
            if ($request->allFiles()) {
                $limits[] = Limit::perMinute(config('council.rate.uploads'))->by("u:{$who}");
            }

            return $limits;
        });
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('council.rate.login'))->by($request->ip()));

        Event::listen(SocialiteWasCalled::class, DiscordExtendSocialite::class.'@handle');

        // ผู้กระทำของบันทึกกิจกรรม: ใช้ผู้ที่ยืนยันตัวตนอยู่ไม่ว่าด้วยวิธีใด (session ของเว็บ หรือโทเค็น API)
        $this->callAfterResolving(CauserResolver::class, function (CauserResolver $resolver) {
            $resolver->resolveUsing(function ($subject = null) {
                // ผู้กระทำที่ระบุชัดเจน (causedBy($user)) มาก่อนเสมอ ไม่ระบุจึงใช้ผู้ที่ยืนยันตัวตนอยู่
                if ($subject instanceof Model) {
                    return $subject;
                }

                if ($subject !== null) {
                    return User::find($subject);
                }

                return Auth::guard('web')->user() ?? Auth::guard('sanctum')->user();
            });
        });

        // ทุกบันทึกกิจกรรมแนบ "มาจากไหน": IP + อุปกรณ์ (คำสั่ง artisan ระบุว่า console)
        Activity::creating(function (Activity $activity) {
            $context = app()->runningInConsole() && ! app()->runningUnitTests()
                ? ['via' => 'console']
                : ['ip' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 255)];

            $activity->properties = collect($activity->properties)->merge($context);
        });
    }
}
