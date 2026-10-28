<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** ผู้ใช้ที่ถูกระงับสิทธิ์ต้องใช้งานต่อไม่ได้ แม้ยังมี session ค้างอยู่ */
class EnsureActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isDisabled()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            abort(403, 'บัญชีนี้ถูกระงับสิทธิ์');
        }

        return $next($request);
    }
}
