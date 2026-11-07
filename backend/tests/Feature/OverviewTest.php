<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** ภาพรวมหน้าแรกของสภา (Council ขึ้นไป) */
class OverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_council_gets_overview_but_gang_does_not(): void
    {
        config(['council.duty.enforced' => false]);
        $this->getJson('/api/council/overview')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->role(Role::Gang)->loggedIn()->create());
        $this->getJson('/api/council/overview')->assertForbidden();

        Sanctum::actingAs(User::factory()->role(Role::Trainee)->loggedIn()->create());
        $this->getJson('/api/council/overview')->assertOk()
            ->assertJsonStructure(['data' => ['gangs', 'families', 'pending', 'on_duty', 'recent', 'protect']]);
    }
}
