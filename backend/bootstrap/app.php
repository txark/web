<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // หลัง proxy ของ Render (HTTPS): ตั้ง TRUSTED_PROXIES=* ใน .env บนเซิร์ฟเวอร์ ให้ Laravel รู้ว่าเป็น https
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
        // ตัวตรวจสิทธิ์ของระบบต้องทำงานก่อนการค้นหาข้อมูลตามรหัสในเส้นทาง (SubstituteBindings)
        // ไม่งั้นผู้ที่ไม่มีสิทธิ์จะเห็นความต่างระหว่าง 404 (ไม่มีรหัสนี้) กับ 403 (มี แต่เข้าไม่ได้) และเดารหัสได้
        foreach ([\App\Http\Middleware\EnsureActive::class, \App\Http\Middleware\EnsureRole::class, \App\Http\Middleware\EnsureOnDuty::class] as $guard) {
            $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: $guard);
        }
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'log.access' => \App\Http\Middleware\LogAccess::class,
            'active' => \App\Http\Middleware\EnsureActive::class,
            'duty' => \App\Http\Middleware\EnsureOnDuty::class,
            'group.portal' => \App\Http\Middleware\EnsureGroupPortalOpen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ทำรายการถี่เกินไป: แจ้งเป็นภาษาไทย พร้อมบอกว่าต้องรอกี่วินาที
        $exceptions->render(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e, Request $request) {
            $wait = (int) ($e->getHeaders()['Retry-After'] ?? 5);

            return response()->json([
                'message' => 'ทำรายการถี่เกินไป กรุณารอ '.max($wait, 1).' วินาทีแล้วลองใหม่',
                'code' => 'throttled',
            ], 429, $e->getHeaders());
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
