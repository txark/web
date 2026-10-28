<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Announcement;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** ประกาศ/ประกาศเรียก: ภายในสภาเท่านั้น (ผู้เยี่ยมชม/แก๊ง/ครอบครัวไม่เห็น) */
class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(Role $role): User
    {
        $u = User::factory()->role($role)->loggedIn()->create();
        Sanctum::actingAs($u);

        return $u;
    }

    private function payload(array $over = []): array
    {
        return array_merge(['type' => 'general', 'title' => 'ประชุมสภา', 'body' => 'วันเสาร์ 20.00 น.', 'is_published' => true], $over);
    }

    public function test_only_council_can_manage_announcements(): void
    {
        $this->getJson('/api/council/announcements')->assertUnauthorized();

        foreach ([Role::Gang, Role::Family] as $role) {
            $this->actAs($role);
            $this->getJson('/api/council/announcements')->assertForbidden();
            $this->postJson('/api/council/announcements', $this->payload())->assertForbidden();
        }

        $this->assertSame(0, Announcement::count());
    }

    public function test_council_creates_a_summon_for_a_group(): void
    {
        $user = $this->actAs(Role::Member);
        $group = Group::factory()->create(['name' => 'Wolves']);

        $this->postJson('/api/council/announcements', $this->payload([
            'type' => 'summon', 'title' => 'เรียกพบ Wolves', 'group_id' => $group->id, 'due_at' => '2026-11-01 20:00:00',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.type_label', 'ประกาศเรียก')
            ->assertJsonPath('data.group.name', 'Wolves')
            ->assertJsonPath('data.is_published', true)
            ->assertJsonPath('data.created_by', $user->id);

        $this->assertNotNull(Announcement::first()->published_at);
        $this->assertTrue(Activity::where('subject_type', Announcement::class)->exists(), 'การสร้างประกาศต้องอยู่ใน log');
    }

    public function test_validation(): void
    {
        $this->actAs(Role::Member);

        $this->postJson('/api/council/announcements', [])->assertUnprocessable()->assertJsonValidationErrors(['type', 'title', 'body', 'is_published']);
        $this->postJson('/api/council/announcements', $this->payload(['type' => 'x']))->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/api/council/announcements', $this->payload(['title' => str_repeat('ก', 151)]))->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->postJson('/api/council/announcements', $this->payload(['group_id' => 9999]))->assertUnprocessable()->assertJsonValidationErrors('group_id');

        $deleted = Group::factory()->create();
        $deleted->delete();
        $this->postJson('/api/council/announcements', $this->payload(['group_id' => $deleted->id]))->assertUnprocessable()->assertJsonValidationErrors('group_id');
    }

    public function test_announcements_are_not_public_at_all(): void
    {
        $this->actAs(Role::Member);
        $this->postJson('/api/council/announcements', $this->payload(['title' => 'ลับภายในสภา']))->assertCreated();

        // ผู้เยี่ยมชม: ไม่มี API สาธารณะ และอ่านรายการของสภาไม่ได้
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public/announcements')->assertNotFound();
        $this->getJson('/api/council/announcements')->assertUnauthorized();

        // แก๊ง/ครอบครัว: เห็นไม่ได้เช่นกัน
        foreach ([Role::Gang, Role::Family] as $role) {
            $this->actAs($role);
            $this->getJson('/api/public/announcements')->assertNotFound();
            $this->getJson('/api/council/announcements')->assertForbidden();
        }
    }

    public function test_public_rules_and_registry_do_not_leak_announcements(): void
    {
        $this->actAs(Role::Member);
        $this->postJson('/api/council/announcements', $this->payload(['title' => 'ห้ามหลุด']));

        $this->app['auth']->forgetGuards();
        foreach (['/api/public/rules', '/api/public/groups'] as $url) {
            $this->assertStringNotContainsString('ห้ามหลุด', $this->getJson($url)->getContent());
        }
    }

    public function test_council_list_includes_drafts_and_can_filter(): void
    {
        $this->actAs(Role::Member);
        $this->postJson('/api/council/announcements', $this->payload(['title' => 'A']));
        $this->postJson('/api/council/announcements', $this->payload(['title' => 'B', 'is_published' => false]));

        $this->getJson('/api/council/announcements')->assertJsonCount(2, 'data');
        $this->getJson('/api/council/announcements?status=draft')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'B');
    }

    public function test_publishing_a_draft_sets_published_at_and_unpublishing_returns_it_to_drafts(): void
    {
        $this->actAs(Role::Member);
        $id = $this->postJson('/api/council/announcements', $this->payload(['is_published' => false]))->json('data.id');
        $this->assertNull(Announcement::find($id)->published_at);

        $this->putJson("/api/council/announcements/{$id}", $this->payload(['is_published' => true]))->assertOk();
        $this->assertNotNull(Announcement::find($id)->published_at);
        $this->getJson('/api/council/announcements?status=published')->assertJsonCount(1, 'data');

        $this->putJson("/api/council/announcements/{$id}", $this->payload(['is_published' => false]))->assertOk();
        $this->getJson('/api/council/announcements?status=published')->assertJsonCount(0, 'data');
        $this->getJson('/api/council/announcements?status=draft')->assertJsonCount(1, 'data');
    }

    public function test_delete_is_limited_to_author_or_vice_president(): void
    {
        $author = $this->actAs(Role::Member);
        $id = $this->postJson('/api/council/announcements', $this->payload())->json('data.id');

        $this->actAs(Role::Member); // สภาคนอื่น
        $this->deleteJson("/api/council/announcements/{$id}")->assertForbidden();
        $this->assertNotNull(Announcement::find($id));

        Sanctum::actingAs($author);
        $this->deleteJson("/api/council/announcements/{$id}")->assertNoContent();

        $id2 = Announcement::create(['type' => 'general', 'title' => 'x', 'body' => 'x', 'is_published' => true, 'published_at' => now(), 'created_by' => $author->id])->id;
        $this->actAs(Role::VicePresident);
        $this->deleteJson("/api/council/announcements/{$id2}")->assertNoContent();
        $this->assertSoftDeleted('announcements', ['id' => $id2]);
    }
}
