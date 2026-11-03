<?php

namespace App\Http\Middleware;

use App\Support\GroupPortal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ระบบส่วนแก๊ง/ครอบครัวใช้งานได้เฉพาะช่วงเวลาที่กำหนด (config council.group_portal) */
class EnsureGroupPortalOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        $s = GroupPortal::status();
        abort_unless($s['open'], 403, "ระบบส่วนแก๊ง/ครอบครัวเปิดให้ใช้งานเวลา {$s['label']} (เวลาไทย)");

        return $next($request);
    }
}
