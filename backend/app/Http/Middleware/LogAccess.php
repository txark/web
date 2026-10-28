<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * บันทึกว่าใคร (ที่ล็อกอิน) เปิดดูข้อมูลส่วนนี้ ใช้กับเส้นทางที่อ่อนไหว:
 * ->middleware('log.access:ชื่อส่วนที่แสดงใน log')
 * บันทึกเฉพาะ GET ที่สำเร็จ และไม่ซ้ำคนเดิมหน้าเดิมภายใน 5 นาที (กัน log ท่วมเมื่อรีเฟรช)
 */
class LogAccess
{
    public function handle(Request $request, Closure $next, string $label = ''): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user && $request->isMethod('GET') && $response->isSuccessful()) {
            $path = '/'.$request->path();

            if (Cache::add("access-log:{$user->getKey()}:{$path}", 1, now()->addMinutes(5))) {
                activity('access')
                    ->causedBy($user)
                    ->event('view')
                    ->withProperties(['path' => $path])
                    ->log('เปิดดู: '.($label ?: $path));
            }
        }

        return $response;
    }
}
