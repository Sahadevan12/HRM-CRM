<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Database\Seeders\PermissionRoleSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workdo\Notes\Database\Seeders\PermissionTableSeeder;
use Workdo\Notes\Models\Note;

/**
 * Reference test for the generator: packages/workdo/Notes was produced by `make:package Notes` + `make:crud Notes Note ...`.
 * It proves the generated CRUD stays tenant-safe, validated, plan-gated and event-driven.
 * Delete this test together with the Notes sample module before shipping.
 */
class NotesSampleModuleTest extends TestCase
{
    use RefreshDatabase;

    private Plan $pro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionRoleSeeder::class);
        $this->seed(PlanSeeder::class);
        $this->seed(PermissionTableSeeder::class);
        $this->pro = Plan::where('name', 'Pro')->firstOrFail();
        $this->pro->update(['modules' => ['Hello', 'Notes']]);
    }

    private function company(string $email, bool $pro = true): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create([
            'name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company',
            'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id,
        ]);
        $c->assignRole('company');
        app(PlanService::class)->assign($c, $pro ? $this->pro : Plan::where('free_plan', true)->first(), $pro ? 'month' : null);

        return $c->refresh();
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['title' => 'T', 'priority' => 2, 'is_pinned' => false];
    }

    public function test_crud_happy_path_sets_tenant_columns(): void
    {
        $a = $this->company('a@test.com');

        $this->actingAs($a)->post('/notes/notes', $this->payload(['body' => 'x', 'budget' => 12.5]))->assertSessionHas('success');
        $note = Note::firstOrFail();
        $this->assertSame($a->id, $note->created_by);
        $this->assertSame($a->id, $note->creator_id);

        $this->actingAs($a)->put("/notes/notes/{$note->id}", $this->payload(['title' => 'Renamed']))->assertSessionHas('success');
        $this->assertSame('Renamed', $note->fresh()->title);

        $this->actingAs($a)->get('/notes/notes')->assertOk()
            ->assertInertia(fn ($p) => $p->component('Notes/Notes/Index', false)->has('notes.data', 1));

        $this->actingAs($a)->delete("/notes/notes/{$note->id}")->assertSessionHas('success');
        $this->assertSame(0, Note::count());
    }

    public function test_validation_rules_come_from_the_field_spec(): void
    {
        $a = $this->company('a@test.com');

        $this->actingAs($a)->post('/notes/notes', ['title' => '', 'priority' => 'abc', 'budget' => -5, 'due_on' => 'not-a-date'])
            ->assertSessionHasErrors(['title', 'priority', 'budget', 'due_on']);

        $this->actingAs($a)->post('/notes/notes', $this->payload(['title' => str_repeat('x', 256)]))->assertSessionHasErrors('title');
        $this->assertSame(0, Note::count());
    }

    public function test_tenants_cannot_see_or_touch_each_others_records(): void
    {
        $a = $this->company('a@test.com');
        $b = $this->company('b@test.com');
        $this->actingAs($a)->post('/notes/notes', $this->payload(['title' => 'A-secret']));
        $note = Note::firstOrFail();

        $this->actingAs($b)->get('/notes/notes')->assertOk()
            ->assertInertia(fn ($p) => $p->has('notes.data', 0));
        $this->actingAs($b)->put("/notes/notes/{$note->id}", $this->payload(['title' => 'Hacked']))->assertSessionHas('error');
        $this->actingAs($b)->delete("/notes/notes/{$note->id}")->assertSessionHas('error');

        $this->assertSame('A-secret', $note->fresh()->title);
    }

    public function test_sorting_and_search_are_whitelisted(): void
    {
        $a = $this->company('a@test.com');
        foreach (['Banana', 'Apple', 'Cherry'] as $title) {
            $this->actingAs($a)->post('/notes/notes', $this->payload(['title' => $title]));
        }

        $this->actingAs($a)->get('/notes/notes?sort=title&direction=asc')
            ->assertInertia(fn ($p) => $p->where('notes.data.0.title', 'Apple'));
        $this->actingAs($a)->get('/notes/notes?search=Cher')
            ->assertInertia(fn ($p) => $p->has('notes.data', 1)->where('notes.data.0.title', 'Cherry'));
        // sorting by an arbitrary column must not blow up / inject
        $this->actingAs($a)->get('/notes/notes?sort=password;drop')->assertOk();
    }

    public function test_module_is_gated_by_plan_and_permissions(): void
    {
        $free = $this->company('free@test.com', pro: false);
        $this->actingAs($free)->get('/notes/notes')->assertRedirect(route('dashboard'));
        $this->actingAs($free)->post('/notes/notes', $this->payload())->assertRedirect(route('dashboard'));
        $this->assertSame(0, Note::count());

        // a staff member of a Pro company without the permission is refused
        $pro = $this->company('pro@test.com');
        $staff = User::create([
            'name' => 's', 'email' => 's@test.com', 'password' => 'secret-pass-1', 'type' => 'staff',
            'email_verified_at' => now(), 'creator_id' => $pro->id, 'created_by' => $pro->id,
        ]);
        $staff->assignRole('staff');
        $this->actingAs($staff)->post('/notes/notes', $this->payload())->assertSessionHas('error');
        $this->assertSame(0, Note::count());

        $staff->givePermissionTo(['manage-notes', 'create-notes']);
        $this->actingAs($staff)->post('/notes/notes', $this->payload())->assertSessionHas('success');
        $this->assertSame($pro->id, Note::firstOrFail()->created_by); // sub-user writes into the company's tenant
    }

    public function test_events_are_dispatched(): void
    {
        \Illuminate\Support\Facades\Event::fake();
        $a = $this->company('a@test.com');

        $this->actingAs($a)->post('/notes/notes', $this->payload());
        \Illuminate\Support\Facades\Event::assertDispatched(\Workdo\Notes\Events\CreateNote::class);

        $note = Note::firstOrFail();
        $this->actingAs($a)->put("/notes/notes/{$note->id}", $this->payload());
        \Illuminate\Support\Facades\Event::assertDispatched(\Workdo\Notes\Events\UpdateNote::class);

        $this->actingAs($a)->delete("/notes/notes/{$note->id}");
        \Illuminate\Support\Facades\Event::assertDispatched(\Workdo\Notes\Events\DestroyNote::class);
    }
}
