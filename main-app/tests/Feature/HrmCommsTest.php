<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Workdo\Hrm\Models\Acknowledgment;
use Workdo\Hrm\Models\Announcement;
use Workdo\Hrm\Models\AnnouncementCategory;
use Workdo\Hrm\Models\Department;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\Event;
use Workdo\Hrm\Models\EventType;
use Workdo\Hrm\Models\HrmDocument;
use Workdo\Hrm\Models\LeaveType;

class HrmCommsTest extends TestCase
{
    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->a = $this->company('a@test.com');
    }

    private function company(string $email): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company', 'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id]);
        $c->assignRole('company');
        $p = Plan::where('name', 'Pro')->first();
        app(PlanService::class)->assign($c, $p, $p->free_plan ? null : 'year');

        return $c->refresh();
    }

    private function dept(User $t, string $name): Department
    {
        return Department::create(['name' => $name, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function hire(string $email, ?Department $dept = null, ?User $as = null, array $extra = []): Employee
    {
        $this->actingAs($as ?? $this->a)->post('/hrm/employees', $extra + [
            'name' => 'Emp ' . $email, 'email' => $email, 'password' => 'secret-pass-1', 'employment_type' => 'full_time', 'basic_salary' => 1000,
            'department_id' => $dept?->id,
        ])->assertSessionHasNoErrors();

        return Employee::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
    }

    private function announce(array $extra = []): Announcement
    {
        $this->actingAs($this->a)->post('/hrm/announcements', $extra + ['title' => 'Hello', 'body' => 'Welcome', 'start_date' => now()->subDay()->toDateString()])->assertSessionHasNoErrors();

        return Announcement::latest('id')->firstOrFail();
    }

    // ───────────── announcements ─────────────

    public function test_announcements_reach_only_their_audience(): void
    {
        $sales = $this->dept($this->a, 'Sales');
        $ops = $this->dept($this->a, 'Ops');
        $inSales = $this->hire('s@test.com', $sales);
        $inOps = $this->hire('o@test.com', $ops);
        $noDept = $this->hire('n@test.com');

        $this->announce(['title' => 'For all']);
        $this->announce(['title' => 'Sales only', 'department_ids' => [$sales->id]]);

        $titles = fn (Employee $e) => $this->actingAs($e->user)->get('/hrm/announcements/mine')->assertOk()->viewData('page')['props']['announcements'];

        $this->assertSame(['For all', 'Sales only'], collect($titles($inSales))->pluck('title')->sort()->values()->all());
        $this->assertSame(['For all'], collect($titles($inOps))->pluck('title')->all());
        $this->assertSame(['For all'], collect($titles($noDept))->pluck('title')->all());
    }

    public function test_only_running_announcements_are_shown_to_employees(): void
    {
        $e = $this->hire('e@test.com');
        $this->announce(['title' => 'Running', 'end_date' => now()->addDays(3)->toDateString()]);
        $this->announce(['title' => 'Open ended']);
        $this->announce(['title' => 'Expired', 'start_date' => now()->subDays(10)->toDateString(), 'end_date' => now()->subDays(2)->toDateString()]);
        $this->announce(['title' => 'Future', 'start_date' => now()->addDays(2)->toDateString()]);

        $titles = collect($this->actingAs($e->user)->get('/hrm/announcements/mine')->viewData('page')['props']['announcements'])->pluck('title')->sort()->values()->all();

        $this->assertSame(['Open ended', 'Running'], $titles);
    }

    public function test_acknowledging_is_idempotent_and_only_for_visible_announcements(): void
    {
        $sales = $this->dept($this->a, 'Sales');
        $ops = $this->dept($this->a, 'Ops');
        $e = $this->hire('e@test.com', $ops);
        $a = $this->announce(['requires_acknowledgment' => true]);
        $salesOnly = $this->announce(['title' => 'Sales', 'department_ids' => [$sales->id]]);

        $this->actingAs($e->user)->post("/hrm/announcements/{$a->id}/acknowledge")->assertSessionHas('success');
        $this->actingAs($e->user)->post("/hrm/announcements/{$a->id}/acknowledge")->assertSessionHas('success');
        $this->assertSame(1, Acknowledgment::where('kind', 'announcement')->count());

        $this->actingAs($e->user)->post("/hrm/announcements/{$salesOnly->id}/acknowledge")->assertSessionHas('error');
        $this->assertSame(1, Acknowledgment::count());

        // HR sees 1 of 1 active employee
        $row = collect($this->actingAs($this->a)->get('/hrm/announcements')->viewData('page')['props']['announcements']['data'])->firstWhere('id', $a->id);
        $this->assertSame(1, $row['acknowledged']);
        $this->assertSame(1, $row['audience']);
    }

    public function test_announcement_rules_and_tenant_isolation(): void
    {
        $b = $this->company('b@test.com');
        $foreignDept = $this->dept($b, 'Foreign');
        $foreignCat = AnnouncementCategory::create(['name' => 'X', 'creator_id' => $b->id, 'created_by' => $b->id]);

        $base = ['title' => 'T', 'body' => 'B', 'start_date' => '2026-03-01'];
        $this->actingAs($this->a)->post('/hrm/announcements', $base + ['department_ids' => [$foreignDept->id]])->assertSessionHasErrors('department_ids.0');
        $this->actingAs($this->a)->post('/hrm/announcements', $base + ['announcement_category_id' => $foreignCat->id])->assertSessionHasErrors('announcement_category_id');
        $this->actingAs($this->a)->post('/hrm/announcements', $base + ['end_date' => '2026-02-01'])->assertSessionHasErrors('end_date');

        $mine = $this->announce();
        $this->actingAs($b)->put("/hrm/announcements/{$mine->id}", $base)->assertSessionHas('error');
        $this->actingAs($b)->delete("/hrm/announcements/{$mine->id}")->assertSessionHas('error');
        $this->actingAs($b)->get('/hrm/announcements')->assertInertia(fn ($p) => $p->where('announcements.total', 0));
        $this->assertNotNull($mine->fresh());

        $this->actingAs($this->a)->put("/hrm/announcements/{$mine->id}", array_merge($base, ['title' => 'Renamed']))->assertSessionHas('success');
        $this->assertSame('Renamed', $mine->refresh()->title);
        $this->actingAs($this->a)->delete("/hrm/announcements/{$mine->id}")->assertSessionHas('success');
    }

    public function test_staff_cannot_manage_announcements(): void
    {
        $e = $this->hire('e@test.com');

        $this->actingAs($e->user)->get('/hrm/announcements')->assertRedirect(route('dashboard'));
        $this->actingAs($e->user)->post('/hrm/announcements', ['title' => 'T', 'body' => 'B', 'start_date' => '2026-03-01'])->assertSessionHas('error');
        $this->assertSame(0, Announcement::count());
    }

    // ───────────── events ─────────────

    public function test_event_validation_and_calendar_range(): void
    {
        $b = $this->company('b@test.com');
        $foreignType = EventType::create(['name' => 'X', 'color' => '#000000', 'creator_id' => $b->id, 'created_by' => $b->id]);

        $this->actingAs($this->a)->post('/hrm/events', ['title' => 'X', 'start_date' => '2026-03-05', 'end_date' => '2026-03-04'])->assertSessionHasErrors('end_date');
        $this->actingAs($this->a)->post('/hrm/events', ['title' => 'X', 'start_date' => '2026-03-05', 'end_date' => '2026-03-05', 'start_time' => '25:99'])->assertSessionHasErrors('start_time');
        $this->actingAs($this->a)->post('/hrm/events', ['title' => 'X', 'start_date' => '2026-03-05', 'end_date' => '2026-03-05', 'event_type_id' => $foreignType->id])->assertSessionHasErrors('event_type_id');

        $this->actingAs($this->a)->post('/hrm/events', ['title' => 'Offsite', 'start_date' => '2026-02-27', 'end_date' => '2026-03-03', 'start_time' => '10:00'])->assertSessionHas('success');
        $this->actingAs($this->a)->post('/hrm/events', ['title' => 'Later', 'start_date' => '2026-06-10', 'end_date' => '2026-06-10'])->assertSessionHas('success');

        $titles = fn (string $q) => collect($this->actingAs($this->a)->get('/hrm/events' . $q)->assertOk()->viewData('page')['props']['events'])->pluck('title')->all();

        $this->assertSame(['Offsite'], $titles('?month=2026-03'));      // starts in February but runs into March (and into the grid)
        $this->assertSame(['Later'], $titles('?month=2026-06'));
        $this->assertSame([], $titles('?month=2026-09'));
        $this->assertOk($this->actingAs($this->a)->get('/hrm/events?month=garbage'));        // falls back to the current month

        $page = $this->actingAs($this->a)->get('/hrm/events?month=2026-03')->viewData('page')['props'];
        $this->assertSame('2026-02-23', $page['from']);   // Monday first
        $this->assertSame('2026-04-05', $page['to']);
    }

    private function assertOk($response): void
    {
        $response->assertOk();
    }

    public function test_employees_see_only_the_events_of_their_department(): void
    {
        $sales = $this->dept($this->a, 'Sales');
        $ops = $this->dept($this->a, 'Ops');
        $e = $this->hire('e@test.com', $ops);

        $this->actingAs($this->a)->post('/hrm/events', ['title' => 'All hands', 'start_date' => '2026-03-05', 'end_date' => '2026-03-05']);
        $this->actingAs($this->a)->post('/hrm/events', ['title' => 'Sales party', 'start_date' => '2026-03-06', 'end_date' => '2026-03-06', 'department_ids' => [$sales->id]]);
        $this->actingAs($this->a)->post('/hrm/events', ['title' => 'Ops day', 'start_date' => '2026-03-07', 'end_date' => '2026-03-07', 'department_ids' => [$ops->id]]);

        $seen = collect($this->actingAs($e->user)->get('/hrm/events?month=2026-03')->assertOk()->viewData('page')['props']['events'])->pluck('title')->sort()->values()->all();
        $this->assertSame(['All hands', 'Ops day'], $seen);

        $hr = collect($this->actingAs($this->a)->get('/hrm/events?month=2026-03')->viewData('page')['props']['events'])->pluck('title')->all();
        $this->assertCount(3, $hr);

        // an employee can view, not change
        $this->actingAs($e->user)->post('/hrm/events', ['title' => 'Mine', 'start_date' => '2026-03-05', 'end_date' => '2026-03-05'])->assertSessionHas('error');
        $event = Event::firstOrFail();
        $this->actingAs($e->user)->delete("/hrm/events/{$event->id}")->assertSessionHas('error');
        $this->assertSame(3, Event::count());
    }

    public function test_event_update_delete_and_isolation(): void
    {
        $sales = $this->dept($this->a, 'Sales');
        $this->actingAs($this->a)->post('/hrm/events', ['title' => 'E', 'start_date' => '2026-03-05', 'end_date' => '2026-03-05', 'department_ids' => [$sales->id]]);
        $e = Event::firstOrFail();
        $b = $this->company('b@test.com');

        $this->actingAs($b)->put("/hrm/events/{$e->id}", ['title' => 'Hacked', 'start_date' => '2026-03-05', 'end_date' => '2026-03-05'])->assertSessionHas('error');
        $this->actingAs($b)->delete("/hrm/events/{$e->id}")->assertSessionHas('error');
        $this->assertSame('E', $e->refresh()->title);

        $this->actingAs($this->a)->put("/hrm/events/{$e->id}", ['title' => 'E2', 'start_date' => '2026-03-05', 'end_date' => '2026-03-06', 'department_ids' => []])->assertSessionHas('success');
        $this->assertSame(0, $e->departments()->count());                          // an empty list means everyone

        $this->actingAs($this->a)->delete("/hrm/events/{$e->id}")->assertSessionHas('success');
        $this->assertSame(0, Event::count());
    }

    public function test_event_type_color_must_be_a_hex_colour(): void
    {
        $this->actingAs($this->a)->post('/hrm/event-types', ['name' => 'Bad', 'color' => 'blue'])->assertSessionHasErrors('color');
        $this->actingAs($this->a)->post('/hrm/event-types', ['name' => 'Good', 'color' => '#1a2B3c'])->assertSessionHas('success');
    }

    // ───────────── documents ─────────────

    public function test_documents_upload_download_acknowledge_and_delete(): void
    {
        $e = $this->hire('e@test.com');

        $this->actingAs($this->a)->post('/hrm/documents', ['title' => 'Handbook', 'file' => UploadedFile::fake()->create('handbook.pdf', 100, 'application/pdf'), 'requires_acknowledgment' => true])->assertSessionHas('success');
        $doc = HrmDocument::firstOrFail();
        Storage::disk('local')->assertExists($doc->makeVisible('file_path')->file_path);

        // every employee can read and download, nobody outside the company
        $this->actingAs($e->user)->get('/hrm/documents')->assertOk()->assertInertia(fn ($p) => $p->component('Hrm/Documents/Index', false)->has('documents', 1));
        $this->actingAs($e->user)->get("/hrm/documents/{$doc->id}/download")->assertOk()->assertDownload('handbook.pdf');

        $this->actingAs($e->user)->post("/hrm/documents/{$doc->id}/acknowledge")->assertSessionHas('success');
        $this->actingAs($e->user)->post("/hrm/documents/{$doc->id}/acknowledge");
        $this->assertSame(1, Acknowledgment::where('kind', 'document')->count());

        $b = $this->company('b@test.com');
        $this->actingAs($b)->get("/hrm/documents/{$doc->id}/download")->assertSessionHas('error');
        $this->actingAs($b)->delete("/hrm/documents/{$doc->id}")->assertSessionHas('error');
        $this->actingAs($e->user)->delete("/hrm/documents/{$doc->id}")->assertSessionHas('error');

        $this->actingAs($this->a)->delete("/hrm/documents/{$doc->id}")->assertSessionHas('success');
        Storage::disk('local')->assertMissing($doc->file_path);
        $this->assertSame(0, Acknowledgment::count());
    }

    public function test_document_upload_rules(): void
    {
        $e = $this->hire('e@test.com');

        $this->actingAs($this->a)->post('/hrm/documents', ['title' => 'Bad', 'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('file');
        $this->actingAs($this->a)->post('/hrm/documents', ['title' => 'Huge', 'file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')])->assertSessionHasErrors('file');
        $this->actingAs($this->a)->post('/hrm/documents', ['title' => '', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertSessionHasErrors('title');
        $this->actingAs($e->user)->post('/hrm/documents', ['title' => 'Mine', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertSessionHas('error');
        $this->assertSame(0, HrmDocument::count());
    }

    // ───────────── dashboard ─────────────

    public function test_dashboard_seeds_the_defaults_once_and_never_brings_back_deleted_ones(): void
    {
        $this->assertSame(0, LeaveType::where('created_by', $this->a->id)->count());

        $this->actingAs($this->a)->get('/hrm/dashboard')->assertOk()->assertInertia(fn ($p) => $p->component('Hrm/Dashboard/Index', false)->has('kpis'));

        $this->assertSame(3, LeaveType::where('created_by', $this->a->id)->count());
        $this->assertSame(3, EventType::where('created_by', $this->a->id)->count());
        $this->assertSame(3, AnnouncementCategory::where('created_by', $this->a->id)->count());

        LeaveType::where('created_by', $this->a->id)->where('name', 'Sick Leave')->delete();
        $this->actingAs($this->a)->get('/hrm/dashboard')->assertOk();
        $this->assertSame(2, LeaveType::where('created_by', $this->a->id)->count());

        // another company gets its own set
        $b = $this->company('b@test.com');
        $this->actingAs($b)->get('/hrm/dashboard')->assertOk();
        $this->assertSame(3, LeaveType::where('created_by', $b->id)->count());
        $this->assertSame(2, LeaveType::where('created_by', $this->a->id)->count());
    }

    public function test_dashboard_numbers(): void
    {
        $sales = $this->dept($this->a, 'Sales');
        $e1 = $this->hire('e1@test.com', $sales, null, ['date_of_joining' => now()->toDateString(), 'gender' => 'female', 'date_of_birth' => now()->addDays(5)->subYears(30)->toDateString()]);
        $e2 = $this->hire('e2@test.com', $sales);
        $e2->update(['status' => 'resigned']);

        \Workdo\Hrm\Models\Attendance::create(['employee_id' => $e1->id, 'date' => now()->toDateString(), 'status' => 'present', 'total_hours' => 8, 'source' => 'manual', 'created_by' => $this->a->id]);

        $page = $this->actingAs($this->a)->get('/hrm/dashboard')->viewData('page')['props'];

        $this->assertSame(2, $page['kpis']['employees']);
        $this->assertSame(1, $page['kpis']['active']);
        $this->assertSame(1, $page['kpis']['present_today']);
        $this->assertSame(1, $page['kpis']['new_this_month']);
        $this->assertSame([['name' => 'Sales', 'value' => 1]], collect($page['byDepartment'])->map(fn ($r) => ['name' => $r['name'], 'value' => (int) $r['value']])->all());
        $this->assertCount(7, $page['attendanceTrend']);
        $this->assertSame(1, $page['attendanceTrend'][6]['present']);
        $this->assertCount(6, $page['joiners']);
        $this->assertSame('Emp e1@test.com', $page['birthdays'][0]['name']);
        $this->assertSame(5, $page['birthdays'][0]['in_days']);
    }

    public function test_staff_cannot_open_the_dashboard(): void
    {
        $e = $this->hire('e@test.com');

        $this->actingAs($e->user)->get('/hrm/dashboard')->assertRedirect(route('dashboard'));
    }
}
