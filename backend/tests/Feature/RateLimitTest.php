<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** กันกดรัว/เรียกซ้ำ: จำกัดเฉพาะการเขียนข้อมูล ต่อผู้ใช้ · เปิดดูและสัญญาณเวรไม่ถูกจำกัด */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['council.duty.enforced' => false, 'council.rate.writes' => 3, 'council.rate.uploads' => 2]);
        RateLimiter::clear('w:1');
    }

    private function member(): User
    {
        $u = User::factory()->role(Role::Member)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_writes_over_the_limit_get_429_in_thai_while_reads_are_never_limited(): void
    {
        $this->member();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/council/reminders', [])->assertStatus(422); // ผ่านตัวจำกัดแล้วค่อยติดตรวจข้อมูล
        }
        $res = $this->postJson('/api/council/reminders', [])->assertStatus(429);
        $res->assertJsonPath('code', 'throttled');
        $this->assertStringContainsString('ถี่เกินไป', $res->json('message'));
        $this->assertNotNull($res->headers->get('Retry-After'));

        // อ่านข้อมูลยังได้ตามปกติ
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/council/alerts')->assertOk();
        }
    }

    public function test_the_duty_heartbeat_is_not_limited(): void
    {
        $this->member();
        for ($i = 0; $i < 8; $i++) {
            $this->assertNotSame(429, $this->postJson('/api/duty/heartbeat')->getStatusCode());
        }
    }

    public function test_requests_with_files_have_a_stricter_limit(): void
    {
        $this->member();
        for ($i = 0; $i < 2; $i++) {
            $this->assertNotSame(429, $this->post('/api/council/disputes', ['images' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])->getStatusCode());
        }
        $this->post('/api/council/disputes', ['images' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])->assertStatus(429);
    }

    public function test_limits_are_per_user(): void
    {
        $this->member();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/council/reminders', []);
        }
        $this->postJson('/api/council/reminders', [])->assertStatus(429);

        $this->member(); // อีกคน: ยังไม่ถูกจำกัด
        $this->postJson('/api/council/reminders', [])->assertStatus(422);
    }
}
