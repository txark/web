<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ตรวจทุกเส้นทาง /api ของระบบแบบอัตโนมัติ (กันลืมใส่ตัวตรวจสิทธิ์เมื่อเพิ่มเส้นทางใหม่):
 *  - ไม่ล็อกอิน → 401 ทุกเส้นทาง ยกเว้นข้อมูลสาธารณะ /api/public/*
 *  - แก๊ง/ครอบครัว (สิทธิ์ต่ำสุด) → ถูกปฏิเสธ (403) ทุกเส้นทางของสภา/จัดการ
 */
class RouteGuardTest extends TestCase
{
    use RefreshDatabase;

    /** เส้นทางที่ผู้ใช้ทั่วไปที่ล็อกอินแล้วเรียกได้ตามออกแบบ */
    private const OPEN_TO_ANY_LOGIN = ['api/user', 'api/logout'];

    /** @return list<array{string, string}> [method, uri ที่แทนค่าพารามิเตอร์แล้ว] */
    private function apiRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/')) {
                continue;
            }
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $out[] = [$method, $uri, preg_replace(['/\{key\}/', '/\{[^}]+\}/'], ['photo', '1'], $uri)];
            }
        }

        return $out;
    }

    public function test_every_non_public_api_route_requires_login(): void
    {
        $checked = 0;
        foreach ($this->apiRoutes() as [$method, $uri, $url]) {
            if (str_starts_with($uri, 'api/public/')) {
                continue;
            }
            $res = $this->json($method, '/'.$url);
            $this->assertSame(401, $res->getStatusCode(), "{$method} /{$url} ต้องตอบ 401 เมื่อไม่ล็อกอิน แต่ได้ {$res->getStatusCode()}");
            $checked++;
        }
        $this->assertGreaterThan(80, $checked);
    }

    public function test_the_lowest_login_role_is_refused_on_every_council_and_management_route(): void
    {
        config(['council.duty.enforced' => false, 'council.rate.writes' => 100000]);
        Sanctum::actingAs(User::factory()->role(Role::Gang)->loggedIn()->create());

        $checked = 0;
        foreach ($this->apiRoutes() as [$method, $uri, $url]) {
            if (str_starts_with($uri, 'api/public/') || in_array($uri, self::OPEN_TO_ANY_LOGIN, true) || str_starts_with($uri, 'api/my-group')) {
                continue;
            }
            $res = $this->json($method, '/'.$url);
            $this->assertSame(403, $res->getStatusCode(), "{$method} /{$url} ต้องปฏิเสธแก๊ง/ครอบครัว (403) แต่ได้ {$res->getStatusCode()}");
            $checked++;
        }
        $this->assertGreaterThan(80, $checked);
    }

    public function test_group_portal_routes_refuse_council_accounts_and_visitors(): void
    {
        foreach ($this->apiRoutes() as [$method, $uri, $url]) {
            if (str_starts_with($uri, 'api/my-group')) {
                $this->assertSame(401, $this->json($method, '/'.$url)->getStatusCode(), "{$method} /{$url}");
            }
        }
    }
}
