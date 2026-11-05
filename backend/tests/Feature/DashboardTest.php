<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** แดชบอร์ดสรุปภาพรวม (รองประธานขึ้นไป) */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/council/dashboard';

    private function actAs(Role $role): User
    {
        config(['council.duty.enforced' => false]);
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_only_vice_president_up_can_open_it(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
        $this->actAs(Role::Member);
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_vice_president_and_president_get_the_summary(): void
    {
        foreach ([Role::VicePresident, Role::President] as $role) {
            $this->actAs($role);
            $this->getJson(self::URL)->assertOk()->assertJsonStructure(['data' => [
                'groups' => ['total', 'gangs', 'families', 'by_status'],
                'requests' => ['pending', 'by_kind'],
                'finance' => ['central', 'month_income', 'month_expense'],
                'loans' => ['active', 'overdue', 'outstanding'],
                'duty' => ['on_now', 'week_total_seconds', 'top_week'],
                'alerts',
            ]]);
        }
    }

    public function test_group_counts(): void
    {
        $this->actAs(Role::President);
        Group::factory()->count(2)->create();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.groups.total', 2);
    }
}
