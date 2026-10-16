<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\UserActiveModule;
use App\Services\PlanService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Announcement;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\IpRestriction;
use Workdo\Hrm\Models\LeaveApplication;
use Workdo\Hrm\Models\LeaveType;
use Workdo\Hrm\Models\Payroll;
use Workdo\Hrm\Models\Payslip;
use Workdo\Hrm\Services\PayrollService;
use Workdo\Hrm\Services\WorkCalendar;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;

/** The mobile API: token login, the same rules as the website, JSON everywhere. */
class MobileApiTest extends TestCase
{
    private const PASSWORD = 'secret-pass-1';

    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
        RateLimiter::clear('api-login:a@test.com|127.0.0.1');
    }

    private function company(string $email): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create(['name' => $email, 'email' => $email, 'password' => self::PASSWORD, 'type' => 'company', 'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id]);
        $c->assignRole('company');
        $p = Plan::where('name', 'Pro')->first();
        app(PlanService::class)->assign($c, $p, $p->free_plan ? null : 'year');

        return $c->refresh();
    }

    private function staff(string $email, array $permissions = []): User
    {
        $u = User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => self::PASSWORD, 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $u->assignRole('staff');
        $u->givePermissionTo($permissions);

        return $u;
    }

    /** log in through the API and return the bearer token */
    private function token(User $user, string $device = 'test-phone'): string
    {
        $res = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD, 'device_name' => $device])->assertOk();

        // every request of a test shares one app: forget the user of the previous call, as a new HTTP request would
        Auth::forgetGuards();

        return $res->json('token');
    }

    private function api(User $as, string $method, string $uri, array $data = [])
    {
        $token = $this->token($as);

        return $this->withToken($token)->json($method, $uri, $data);
    }

    // ───────────── login ─────────────

    public function test_login_gives_a_token_and_the_profile_the_app_needs(): void
    {
        $res = $this->postJson('/api/v1/auth/login', ['email' => 'a@test.com', 'password' => self::PASSWORD, 'device_name' => 'Pixel']);

        $res->assertOk()->assertJsonPath('user.email', 'a@test.com')->assertJsonPath('user.type', 'company')->assertJsonStructure(['token', 'expires_at', 'user' => ['id', 'name', 'permissions', 'modules']]);
        $this->assertContains('manage-leads', $res->json('user.permissions'));
        $this->assertContains('Hrm', $res->json('user.modules'));
        $this->assertSame('Pixel', $this->a->tokens()->firstOrFail()->name);
        $this->assertTrue($this->a->tokens()->firstOrFail()->expires_at->isFuture());

        Auth::forgetGuards();
        $this->withToken($res->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.id', $this->a->id);
    }

    public function test_logout_revokes_only_this_token(): void
    {
        $one = $this->token($this->a, 'phone');
        $two = $this->token($this->a, 'tablet');

        $this->withToken($one)->postJson('/api/v1/auth/logout')->assertOk();
        Auth::forgetGuards();

        $this->withToken($one)->getJson('/api/v1/auth/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($two)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(1, $this->a->tokens()->count());
    }

    public function test_bad_logins_are_refused_and_throttled(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@test.com', 'password' => 'wrong', 'device_name' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@test.com', 'password' => 'wrong', 'device_name' => 'x'])->assertStatus(422)->assertJsonPath('message', 'These credentials do not match our records.');
        $this->postJson('/api/v1/auth/login', ['email' => 'a@test.com', 'password' => self::PASSWORD])->assertStatus(422)->assertJsonValidationErrors('device_name');
        $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email', 'password' => 'x', 'device_name' => 'x'])->assertStatus(422);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'a@test.com', 'password' => 'wrong', 'device_name' => 'x']);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'a@test.com', 'password' => self::PASSWORD, 'device_name' => 'x'])->assertStatus(429);   // even the right one waits
        $this->assertSame(0, $this->a->tokens()->count());
    }

    public function test_only_company_and_staff_accounts_may_use_the_app(): void
    {
        $client = User::create(['name' => 'C', 'email' => 'c@test.com', 'password' => self::PASSWORD, 'type' => 'client', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $admin = User::where('type', 'superadmin')->first();
        $admin->update(['password' => self::PASSWORD]);

        $this->postJson('/api/v1/auth/login', ['email' => 'c@test.com', 'password' => self::PASSWORD, 'device_name' => 'x'])->assertForbidden()->assertJsonPath('code', 'not_allowed');
        $this->postJson('/api/v1/auth/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'device_name' => 'x'])->assertForbidden();
        $this->assertSame(0, $client->tokens()->count());
    }

    public function test_disabled_accounts_and_expired_plans_are_refused_at_login_and_on_every_call(): void
    {
        $staff = $this->staff('s@test.com');
        $token = $this->token($staff);

        // the company is switched off while the staff member is logged in
        $this->a->update(['is_enable_login' => false]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'account_disabled');
        $this->assertSame(0, $staff->tokens()->count());                                          // and the token is gone
        $this->postJson('/api/v1/auth/login', ['email' => 's@test.com', 'password' => self::PASSWORD, 'device_name' => 'x'])->assertForbidden();

        $this->a->update(['is_enable_login' => true]);
        $token = $this->token($staff);
        $this->a->update(['plan_expire_date' => now()->subDay()]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'plan_expired');
        $this->postJson('/api/v1/auth/login', ['email' => 'a@test.com', 'password' => self::PASSWORD, 'device_name' => 'x'])->assertForbidden()->assertJsonPath('code', 'plan_expired');

        $this->a->update(['plan_expire_date' => now()->addYear()]);
        $staff->update(['is_enable_login' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => 's@test.com', 'password' => self::PASSWORD, 'device_name' => 'x'])->assertForbidden();
    }

    public function test_every_call_needs_a_token_and_answers_in_json(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
        $this->getJson('/api/v1/crm/leads')->assertUnauthorized();
        $this->getJson('/api/v1/hrm/me')->assertUnauthorized();
        $this->get('/api/v1/crm/leads')->assertUnauthorized();                                    // even without an Accept header: no redirect to a login page
        $this->withToken('1|not-a-real-token')->getJson('/api/v1/auth/me')->assertUnauthorized();

        // a logged-in browser session is not an API token
        $this->actingAs($this->a)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/nope')->assertNotFound();
    }

    public function test_a_module_the_plan_does_not_include_is_closed(): void
    {
        $token = $this->token($this->a);
        UserActiveModule::where('user_id', $this->a->id)->where('module', 'Hrm')->delete();
        Auth::forgetGuards();

        $this->withToken($token)->getJson('/api/v1/hrm/me')->assertForbidden()->assertJsonPath('code', 'module_inactive');
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/crm/pipelines')->assertOk();                  // the other modules still work
    }

    // ───────────── CRM ─────────────

    private function crm(?User $t = null): array
    {
        $t ??= $this->a;
        $this->actingAs($t)->get('/crm/setup')->assertOk();
        Auth::forgetGuards();
        $pipeline = Pipeline::where('created_by', $t->id)->firstOrFail();

        return [$pipeline, LeadStage::where('pipeline_id', $pipeline->id)->orderBy('order')->pluck('id', 'name')->all(), DealStage::where('pipeline_id', $pipeline->id)->orderBy('order')->pluck('id', 'name')->all()];
    }

    public function test_pipelines_and_leads_follow_the_visibility_rules(): void
    {
        [$pipeline, $stages] = $this->crm();
        $sam = $this->staff('sam@test.com', ['manage-leads']);
        $mine = Lead::create(['subject' => 'Assigned to Sam', 'name' => 'A', 'pipeline_id' => $pipeline->id, 'lead_stage_id' => $stages['New'], 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $mine->users()->attach($sam->id);
        Lead::create(['subject' => 'Not for Sam', 'name' => 'B', 'pipeline_id' => $pipeline->id, 'lead_stage_id' => $stages['New'], 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        $res = $this->api($this->a, 'GET', '/api/v1/crm/pipelines')->assertOk();
        $this->assertSame(['New', 'Contacted', 'Qualified', 'Proposal Sent'], collect($res->json('data.0.lead_stages'))->pluck('name')->all());
        $this->assertCount(5, $res->json('data.0.deal_stages'));

        $this->assertSame(2, $this->api($this->a, 'GET', '/api/v1/crm/leads')->assertOk()->json('total'));
        $this->assertSame(['Assigned to Sam'], collect($this->api($sam, 'GET', '/api/v1/crm/leads')->json('data'))->pluck('subject')->all());
        $this->assertSame(1, $this->api($this->a, 'GET', '/api/v1/crm/leads?search=Assigned')->json('total'));
        $this->assertSame(0, $this->api($this->a, 'GET', '/api/v1/crm/leads?stage=' . $stages['Qualified'])->json('total'));
        $this->api($this->a, 'GET', '/api/v1/crm/leads?per_page=500')->assertStatus(422);                            // a page is capped

        $this->api($this->staff('none@test.com'), 'GET', '/api/v1/crm/leads')->assertForbidden();
    }

    public function test_leads_can_be_created_and_moved_from_the_phone(): void
    {
        [$pipeline, $stages] = $this->crm();
        $creator = $this->staff('c@test.com', ['manage-leads', 'create-leads', 'move-leads']);

        $this->api($creator, 'POST', '/api/v1/crm/leads', ['name' => 'N'])->assertStatus(422)->assertJsonValidationErrors(['subject', 'pipeline_id']);
        $lead = $this->api($creator, 'POST', '/api/v1/crm/leads', ['subject' => 'From the app', 'name' => 'Anita', 'pipeline_id' => $pipeline->id, 'user_ids' => [$creator->id]])->assertCreated()->assertJsonPath('data.stage.name', 'New');
        $id = $lead->json('data.id');

        $this->api($creator, 'POST', "/api/v1/crm/leads/{$id}/move", ['lead_stage_id' => $stages['Qualified']])->assertOk()->assertJsonPath('data.stage.name', 'Qualified');
        $this->api($creator, 'POST', "/api/v1/crm/leads/{$id}/move", ['lead_stage_id' => 999999])->assertStatus(422);
        $this->assertSame('moved', \Workdo\Lead\Models\LeadActivityLog::where('lead_id', $id)->latest('id')->value('type'));

        // no create / move permission, or a lead of somebody else
        $viewer = $this->staff('v@test.com', ['manage-leads']);
        $this->api($viewer, 'POST', '/api/v1/crm/leads', ['subject' => 'x', 'name' => 'y', 'pipeline_id' => $pipeline->id])->assertForbidden();
        $this->api($viewer, 'POST', "/api/v1/crm/leads/{$id}/move", ['lead_stage_id' => $stages['New']])->assertForbidden();
        $other = $this->company('b@test.com');
        $this->api($other, 'POST', "/api/v1/crm/leads/{$id}/move", ['lead_stage_id' => $stages['New']])->assertForbidden();
    }

    public function test_the_lead_detail_and_its_children_use_the_same_rules_as_the_drawer(): void
    {
        [$pipeline, $stages] = $this->crm();
        $lead = Lead::create(['subject' => 'Detail', 'name' => 'A', 'pipeline_id' => $pipeline->id, 'lead_stage_id' => $stages['New'], 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        $this->api($this->a, 'GET', "/api/v1/crm/leads/{$lead->id}")->assertOk()->assertJsonPath('lead.subject', 'Detail')->assertJsonStructure(['lead' => ['tasks', 'calls', 'discussions', 'files', 'activities']]);
        $this->api($this->a, 'POST', "/api/v1/crm/leads/{$lead->id}/items/discussion", ['comment' => 'Called, no answer'])->assertOk()->assertJsonPath('lead.discussions.0.comment', 'Called, no answer');
        $this->api($this->a, 'POST', "/api/v1/crm/leads/{$lead->id}/items/task", ['name' => 'Call back', 'due_date' => '2026-12-01', 'priority' => 'high'])->assertOk();
        $this->api($this->a, 'POST', "/api/v1/crm/leads/{$lead->id}/items/discussion", ['comment' => ''])->assertStatus(422);

        $other = $this->company('b@test.com');
        $this->api($other, 'GET', "/api/v1/crm/leads/{$lead->id}")->assertForbidden();
        $this->api($this->staff('s@test.com', ['manage-leads']), 'GET', "/api/v1/crm/leads/{$lead->id}")->assertForbidden();        // not theirs
    }

    public function test_deals_status_and_the_dashboard(): void
    {
        [$pipeline, , $deals] = $this->crm();
        $deal = Deal::create(['name' => 'Big', 'price' => 900, 'pipeline_id' => $pipeline->id, 'deal_stage_id' => $deals['Initial Contact'], 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        $this->assertSame(['Big'], collect($this->api($this->a, 'GET', '/api/v1/crm/deals?status=active')->assertOk()->json('data'))->pluck('name')->all());
        $this->api($this->a, 'POST', "/api/v1/crm/deals/{$deal->id}/status", ['status' => 'won'])->assertOk()->assertJsonPath('data.status', 'won');
        $this->api($this->a, 'POST', "/api/v1/crm/deals/{$deal->id}/status", ['status' => 'won'])->assertStatus(422);
        $this->api($this->a, 'POST', "/api/v1/crm/deals/{$deal->id}/status", ['status' => 'banana'])->assertStatus(422);
        $this->assertSame(0, count($this->api($this->a, 'GET', '/api/v1/crm/deals?status=active')->json('data')));
        $this->api($this->a, 'GET', "/api/v1/crm/deals/{$deal->id}")->assertOk()->assertJsonPath('deal.name', 'Big');

        $dash = $this->api($this->a, 'GET', '/api/v1/crm/dashboard')->assertOk();
        $this->assertEquals(900, $dash->json('kpis.won_month_value'));
        $this->assertSame($pipeline->id, $dash->json('pipeline.id'));

        $this->api($this->staff('s@test.com', ['manage-deals']), 'POST', "/api/v1/crm/deals/{$deal->id}/status", ['status' => 'active'])->assertForbidden();
        $this->api($this->staff('s2@test.com'), 'GET', '/api/v1/crm/dashboard')->assertForbidden();
    }

    // ───────────── HRM ─────────────

    private function hire(string $email, array $extra = []): Employee
    {
        $this->actingAs($this->a)->post('/hrm/employees', $extra + [
            'name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => self::PASSWORD, 'employment_type' => 'full_time', 'basic_salary' => 26000, 'date_of_joining' => '2025-01-01',
        ])->assertSessionHasNoErrors();
        Auth::forgetGuards();

        return Employee::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
    }

    public function test_the_employee_profile_and_the_missing_profile_case(): void
    {
        $e = $this->hire('asha@test.com');

        $this->api($e->user, 'GET', '/api/v1/hrm/me')->assertOk()->assertJsonPath('data.code', $e->employee_code)->assertJsonPath('data.name', 'Asha');
        $this->api($this->a, 'GET', '/api/v1/hrm/me')->assertNotFound()->assertJsonPath('code', 'no_employee_profile');           // an owner without a profile
    }

    public function test_clocking_in_and_out_from_the_phone(): void
    {
        $e = $this->hire('asha@test.com');

        $this->api($e->user, 'GET', '/api/v1/hrm/attendance')->assertOk()->assertJsonPath('clocked_in', false);
        $this->api($e->user, 'POST', '/api/v1/hrm/attendance/clock-in')->assertOk()->assertJsonPath('clocked_in', true);
        $this->api($e->user, 'POST', '/api/v1/hrm/attendance/clock-in')->assertStatus(422);                                         // already in
        $out = $this->api($e->user, 'POST', '/api/v1/hrm/attendance/clock-out')->assertOk()->assertJsonPath('clocked_in', false);
        $this->assertSame(1, count($out->json('recent')));
        $this->assertSame('self', Attendance::where('employee_id', $e->id)->value('source'));

        // the company limits clocking to its office network
        $e2 = $this->hire('ben@test.com');
        IpRestriction::create(['ip_address' => '10.9.9.9', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $this->api($e2->user, 'POST', '/api/v1/hrm/attendance/clock-in')->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'office network'));

        $noPerm = $this->staff('x@test.com');
        $noPerm->syncRoles([]);                                                                      // no role, no permission
        $this->api($noPerm, 'GET', '/api/v1/hrm/attendance')->assertForbidden();
    }

    public function test_leave_from_the_phone(): void
    {
        $e = $this->hire('asha@test.com');
        $other = $this->hire('ben@test.com');
        $type = LeaveType::create(['name' => 'Casual', 'days_per_year' => 5, 'is_paid' => true, 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);

        $list = $this->api($e->user, 'GET', '/api/v1/hrm/leaves')->assertOk();
        $this->assertSame('Casual', $list->json('types.0.name'));
        $this->assertEquals(5, collect($list->json('balances'))->firstWhere('name', 'Casual')['remaining']);

        $this->api($e->user, 'POST', '/api/v1/hrm/leaves', ['leave_type_id' => $type->id, 'start_date' => '2026-03-05', 'end_date' => '2026-03-01'])->assertStatus(422)->assertJsonValidationErrors('end_date');
        $id = $this->api($e->user, 'POST', '/api/v1/hrm/leaves', ['leave_type_id' => $type->id, 'start_date' => '2026-03-02', 'end_date' => '2026-03-03', 'reason' => 'trip'])->assertCreated()->assertJsonPath('data.days', 2)->json('data.id');
        $this->api($e->user, 'POST', '/api/v1/hrm/leaves', ['leave_type_id' => $type->id, 'start_date' => '2026-03-03', 'end_date' => '2026-03-04'])->assertStatus(422);       // overlaps
        $this->assertEquals(3, collect($this->api($e->user, 'GET', '/api/v1/hrm/leaves')->json('balances'))->firstWhere('name', 'Casual')['remaining']);

        $this->api($other->user, 'DELETE', "/api/v1/hrm/leaves/{$id}")->assertForbidden();                                          // somebody else's
        $this->api($e->user, 'DELETE', "/api/v1/hrm/leaves/{$id}")->assertOk();
        $this->assertSame(0, LeaveApplication::count());

        $approved = app(\Workdo\Hrm\Services\LeaveService::class)->apply($e, $type, '2026-03-09', '2026-03-09', null, $this->a->id);
        app(\Workdo\Hrm\Services\LeaveService::class)->approve($approved, null, $this->a->id);
        $this->api($e->user, 'DELETE', "/api/v1/hrm/leaves/{$approved->id}")->assertStatus(422);                                    // decided: too late
    }

    private function payrollApproved(Employee ...$employees): Payroll
    {
        foreach ($employees as $e) {
            foreach (WorkCalendar::for($this->a->id)->workingDays('2026-03-01', '2026-03-31') as $day) {
                Attendance::create(['employee_id' => $e->id, 'date' => $day, 'status' => 'present', 'total_hours' => 8, 'source' => 'manual', 'created_by' => $this->a->id]);
            }
        }
        $payroll = app(PayrollService::class)->generate($this->a->id, '2026-03', $this->a->id);

        return $payroll;
    }

    public function test_payslips_are_only_visible_after_approval_and_only_the_own_ones(): void
    {
        $e = $this->hire('asha@test.com');
        $other = $this->hire('ben@test.com');
        $payroll = $this->payrollApproved($e, $other);
        $mine = Payslip::where('employee_id', $e->id)->firstOrFail();
        $theirs = Payslip::where('employee_id', $other->id)->firstOrFail();

        $this->assertSame([], $this->api($e->user, 'GET', '/api/v1/hrm/payslips')->assertOk()->json('data'));                       // still a draft
        $this->api($e->user, 'GET', "/api/v1/hrm/payslips/{$mine->id}")->assertForbidden();

        app(PayrollService::class)->approve($payroll, $this->a->id);

        $list = $this->api($e->user, 'GET', '/api/v1/hrm/payslips')->assertOk();
        $this->assertSame(['2026-03'], collect($list->json('data'))->pluck('month')->all());
        $this->assertEquals(26000, $list->json('data.0.net_pay'));
        $this->api($e->user, 'GET', "/api/v1/hrm/payslips/{$mine->id}")->assertOk()->assertJsonPath('data.lines.0.label', 'Basic salary');
        $this->api($e->user, 'GET', "/api/v1/hrm/payslips/{$theirs->id}")->assertForbidden();
        $this->api($e->user, 'GET', "/api/v1/hrm/payslips/{$theirs->id}/pdf")->assertForbidden();
    }

    public function test_the_payslip_pdf_is_a_real_pdf_for_the_api_and_the_website(): void
    {
        $e = $this->hire('asha@test.com');
        $other = $this->hire('ben@test.com');
        $payroll = $this->payrollApproved($e, $other);
        app(PayrollService::class)->approve($payroll, $this->a->id);
        $mine = Payslip::where('employee_id', $e->id)->firstOrFail();
        $theirs = Payslip::where('employee_id', $other->id)->firstOrFail();

        $api = $this->api($e->user, 'GET', "/api/v1/hrm/payslips/{$mine->id}/pdf")->assertOk();
        $this->assertSame('application/pdf', $api->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $api->getContent());
        $this->assertStringContainsString("payslip-{$e->employee_code}-2026-03.pdf", $api->headers->get('content-disposition'));

        // the website: HR reads all, the employee only their own, others are sent away
        Auth::forgetGuards();
        $this->actingAs($this->a)->get("/hrm/payslips/{$theirs->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($e->user)->get("/hrm/payslips/{$mine->id}/pdf")->assertOk();
        $this->actingAs($e->user)->get("/hrm/payslips/{$theirs->id}/pdf")->assertRedirect(route('dashboard'));
        $this->actingAs($this->company('b@test.com'))->get("/hrm/payslips/{$mine->id}/pdf")->assertRedirect(route('dashboard'));
    }

    public function test_announcements_and_acknowledgment_from_the_phone(): void
    {
        $e = $this->hire('asha@test.com');
        $this->actingAs($this->a)->post('/hrm/announcements', ['title' => 'Office closed', 'body' => 'Friday', 'start_date' => now()->subDay()->toDateString(), 'requires_acknowledgment' => true])->assertSessionHasNoErrors();
        Auth::forgetGuards();
        $id = Announcement::firstOrFail()->id;

        $this->assertFalse($this->api($e->user, 'GET', '/api/v1/hrm/announcements')->assertOk()->json('data.0.acknowledged'));
        $this->api($e->user, 'POST', "/api/v1/hrm/announcements/{$id}/acknowledge")->assertOk();
        $this->assertTrue($this->api($e->user, 'GET', '/api/v1/hrm/announcements')->json('data.0.acknowledged'));

        $other = $this->company('b@test.com');
        $this->api($other, 'POST', "/api/v1/hrm/announcements/{$id}/acknowledge")->assertForbidden();
    }
}
