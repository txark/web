<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** ใช้: ->middleware('role:member') = ต้องมีสิทธิ์ member ขึ้นไป */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $minRole): Response
    {
        $user = $request->user();

        if (! $user || ! $user->atLeast(Role::from($minRole))) {
            if ($user) {
                activity('access')
                    ->causedBy($user)
                    ->event('denied')
                    ->withProperties(['path' => '/'.$request->path(), 'method' => $request->method(), 'required_role' => $minRole])
                    ->log('พยายามเข้าส่วนที่ไม่มีสิทธิ์ (ต้องเป็น '.Role::from($minRole)->label().' ['.Role::from($minRole)->thaiName().'] ขึ้นไป)');
            }

            abort(403, 'คุณไม่มีสิทธิ์เข้าถึงส่วนนี้');
        }

        return $next($request);
    }
}
