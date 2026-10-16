<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Plan;
use App\Models\User;
use App\Services\MailTemplates;
use App\Services\PlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Workdo\Hrm\Models\Attendance;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\LeaveType;
use Workdo\Hrm\Services\LeaveService;
use Workdo\Hrm\Services\PayrollService;
use Workdo\Hrm\Services\WorkCalendar;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Services\DealService;
use Workdo\Lead\Services\LeadService;

class MailTemplatesTest extends TestCase
{
    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
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

    private function staff(string $email, array $permissions = []): User
    {
        $u = User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $u->assignRole('staff');
        $u->givePermissionTo($permissions);

        return $u;
    }

    private function mails(): MailTemplates
    {
        return app(MailTemplates::class);
    }

    private function sent(): \Illuminate\Support\Collection
    {
        return collect(Mail::sent(TemplatedMail::class));
    }

    private function override(User $t, string $key, array $data): void
    {
        DB::table('email_template_overrides')->insert(['created_by' => $t->id, 'key' => $key, 'created_at' => now(), 'updated_at' => now()] + $data);
    }

    // ───────────── the registry and the rendering ─────────────

    public function test_every_module_registers_its_templates(): void
    {
        $keys = array_keys($this->mails()->definitions());

        $this->assertEqualsCanonicalizing(['hrm_leave_status', 'hrm_payslip_ready', 'crm_lead_assigned', 'crm_lead_moved', 'crm_deal_won', 'crm_web_lead_received'], $keys);

        foreach ($this->mails()->definitions() as $d) {
            // every placeholder used in the default wording is offered as a variable (otherwise it would be an empty hole)
            $this->assertSame([], $this->mails()->unknownVariables($d['key'], $d['subject'], $d['body']), $d['key']);
            $this->assertNotEmpty($d['sample']);
        }
    }

    public function test_values_and_templates_are_escaped_and_the_subject_is_one_clean_line(): void
    {
        $mail = $this->mails()->render('crm_lead_assigned', $this->a->id, ['name' => '<script>alert(1)</script>', 'lead' => "Deal\r\nBcc: evil@x.com", 'by' => 'Meena']);

        $this->assertStringNotContainsString('<script>', $mail['html']);
        $this->assertStringContainsString('&lt;script&gt;', $mail['html']);
        $this->assertStringContainsString('<br', $mail['html']);                     // line breaks survive
        $this->assertStringNotContainsString("\n", $mail['subject']);
        $this->assertStringNotContainsString("\r", $mail['subject']);
        $this->assertSame('New lead for you: Deal Bcc: evil@x.com', $mail['subject']);
        $this->assertStringContainsString($this->a->name, $mail['html']);             // {{company}} is always known
    }

    public function test_a_company_can_reword_a_template_and_other_companies_keep_the_default(): void
    {
        $b = $this->company('b@test.com');
        $this->override($this->a, 'crm_lead_assigned', ['subject' => 'Hot lead: {{lead}}', 'body' => "<b>Hi</b> {{name}}\nbye", 'is_enabled' => true]);

        $mine = $this->mails()->render('crm_lead_assigned', $this->a->id, ['name' => 'Ravi', 'lead' => 'X', 'by' => 'Y']);
        $theirs = $this->mails()->render('crm_lead_assigned', $b->id, ['name' => 'Ravi', 'lead' => 'X', 'by' => 'Y']);

        $this->assertSame('Hot lead: X', $mine['subject']);
        $this->assertStringContainsString('&lt;b&gt;Hi&lt;/b&gt; Ravi<br', str_replace(["\r", "\n"], '', $mine['html']));    // tags in the template are text, not markup
        $this->assertSame('New lead for you: X', $theirs['subject']);
    }

    public function test_a_switched_off_or_unknown_template_sends_nothing(): void
    {
        $this->override($this->a, 'crm_deal_won', ['subject' => 's', 'body' => 'b', 'is_enabled' => false]);

        $this->assertNull($this->mails()->render('crm_deal_won', $this->a->id, []));
        $this->assertFalse($this->mails()->send('crm_deal_won', $this->a->id, $this->a, ['name' => 'x']));
        $this->assertFalse($this->mails()->send('nope', $this->a->id, $this->a, []));
        $this->assertFalse($this->mails()->send('crm_lead_moved', $this->a->id, 'not-an-address', []));
        $this->assertFalse($this->mails()->send('crm_lead_moved', $this->a->id, null, []));
        $this->assertCount(0, $this->sent());
    }

    public function test_a_broken_mail_server_never_throws(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->assertFalse($this->mails()->send('crm_lead_moved', $this->a->id, $this->a, ['name' => 'x']));
    }

    // ───────────── the settings page ─────────────

    public function test_the_page_lists_the_templates_with_company_changes(): void
    {
        $this->override($this->a, 'crm_deal_won', ['subject' => 'Won!', 'body' => 'b {{deal}}', 'is_enabled' => false]);

        $templates = collect($this->actingAs($this->a)->get('/email-templates')->assertOk()->assertInertia(fn ($p) => $p->component('EmailTemplates/Index'))->viewData('page')['props']['templates']);

        $this->assertCount(6, $templates);
        $won = $templates->firstWhere('key', 'crm_deal_won');
        $this->assertSame(['Won!', false, true], [$won['subject'], $won['is_enabled'], $won['customized']]);
        $this->assertFalse($templates->firstWhere('key', 'crm_lead_moved')['customized']);
        $this->assertSame(['CRM', 'CRM', 'CRM', 'CRM', 'HRM', 'HRM'], $templates->pluck('module')->all());          // grouped by module
    }

    public function test_saving_validates_the_placeholders_and_resetting_goes_back(): void
    {
        $url = '/email-templates/crm_lead_moved';

        $this->actingAs($this->a)->put($url, ['subject' => 'Moved', 'body' => 'Hi {{name}} {{bogus}}', 'is_enabled' => true])->assertSessionHasErrors('body');
        $this->actingAs($this->a)->put($url, ['subject' => '', 'body' => 'x', 'is_enabled' => true])->assertSessionHasErrors('subject');
        $this->actingAs($this->a)->put($url, ['subject' => str_repeat('x', 201), 'body' => 'x', 'is_enabled' => true])->assertSessionHasErrors('subject');
        $this->assertSame(0, DB::table('email_template_overrides')->count());

        $this->actingAs($this->a)->put($url, ['subject' => 'Moved: {{lead}}', 'body' => 'Hi {{name}}, {{company}}', 'is_enabled' => true])->assertSessionHas('success');
        $this->actingAs($this->a)->put($url, ['subject' => 'Moved again: {{lead}}', 'body' => 'Hi {{name}}', 'is_enabled' => false])->assertSessionHas('success');
        $this->assertSame(1, DB::table('email_template_overrides')->where('created_by', $this->a->id)->count());     // updated, not duplicated
        $this->assertSame('Moved again: {{lead}}', DB::table('email_template_overrides')->value('subject'));

        $this->actingAs($this->a)->delete($url)->assertSessionHas('success');
        $this->assertSame(0, DB::table('email_template_overrides')->count());

        $this->actingAs($this->a)->put('/email-templates/unknown_key', ['subject' => 's', 'body' => 'b', 'is_enabled' => true])->assertNotFound();
        $this->actingAs($this->a)->delete('/email-templates/unknown_key')->assertNotFound();
    }

    public function test_the_test_mail_goes_to_the_logged_in_user_with_sample_data(): void
    {
        $this->actingAs($this->a)->post('/email-templates/hrm_leave_status/test')->assertSessionHas('success');

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('a@test.com') && str_contains($m->mailSubject, 'Casual Leave leave was approved'));

        $this->actingAs($this->a)->put('/email-templates/hrm_leave_status', ['subject' => 's', 'body' => 'b', 'is_enabled' => false]);
        $this->actingAs($this->a)->post('/email-templates/hrm_leave_status/test')->assertSessionHas('error');
        $this->assertCount(1, $this->sent());
    }

    public function test_staff_cannot_touch_the_templates_and_companies_are_separate(): void
    {
        $staff = $this->staff('s@test.com');
        $b = $this->company('b@test.com');

        $this->actingAs($staff)->get('/email-templates')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->put('/email-templates/crm_lead_moved', ['subject' => 's', 'body' => 'b', 'is_enabled' => true])->assertSessionHas('error');
        $this->actingAs($staff)->post('/email-templates/crm_lead_moved/test')->assertSessionHas('error');
        $this->assertSame(0, DB::table('email_template_overrides')->count());

        $this->actingAs($this->a)->put('/email-templates/crm_lead_moved', ['subject' => 'Mine', 'body' => 'b', 'is_enabled' => true]);
        $other = collect($this->actingAs($b)->get('/email-templates')->viewData('page')['props']['templates'])->firstWhere('key', 'crm_lead_moved');
        $this->assertFalse($other['customized']);
        $this->actingAs($b)->delete('/email-templates/crm_lead_moved');                                            // cannot reset A's template
        $this->assertSame(1, DB::table('email_template_overrides')->where('created_by', $this->a->id)->count());
    }

    // ───────────── the mails the modules send ─────────────

    private function hire(string $email): Employee
    {
        $this->actingAs($this->a)->post('/hrm/employees', [
            'name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password' => 'secret-pass-1', 'employment_type' => 'full_time', 'basic_salary' => 26000, 'date_of_joining' => '2025-01-01',
        ])->assertSessionHasNoErrors();

        return Employee::whereHas('user', fn ($q) => $q->where('email', $email))->firstOrFail();
    }

    public function test_the_employee_hears_about_the_leave_decision(): void
    {
        $e = $this->hire('asha@test.com');
        $type = LeaveType::create(['name' => 'Casual', 'days_per_year' => 12, 'is_paid' => true, 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $service = app(LeaveService::class);

        $approved = $service->apply($e, $type, '2026-03-02', '2026-03-03', null, $this->a->id);
        $this->assertCount(0, $this->sent());                                                  // applying sends nothing
        $service->approve($approved, 'Enjoy', $this->a->id);

        $rejected = $service->apply($e, $type, '2026-03-09', '2026-03-09', null, $this->a->id);
        $service->reject($rejected, 'Busy week', $this->a->id);

        $subjects = $this->sent()->map(fn (TemplatedMail $m) => $m->mailSubject)->all();
        $this->assertSame(['Your Casual leave was approved', 'Your Casual leave was rejected'], $subjects);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('asha@test.com') && str_contains($m->htmlBody, 'Enjoy') && str_contains($m->htmlBody, '2 working day'));
    }

    public function test_a_failing_or_switched_off_mail_does_not_stop_the_leave_decision(): void
    {
        $e = $this->hire('asha@test.com');
        $type = LeaveType::create(['name' => 'Casual', 'days_per_year' => 12, 'is_paid' => true, 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $service = app(LeaveService::class);

        $this->override($this->a, 'hrm_leave_status', ['subject' => 's', 'body' => 'b', 'is_enabled' => false]);
        $leave = $service->apply($e, $type, '2026-03-02', '2026-03-02', null, $this->a->id);
        $service->approve($leave, null, $this->a->id);
        $this->assertSame('approved', $leave->refresh()->status);
        $this->assertCount(0, $this->sent());

        DB::table('email_template_overrides')->delete();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $second = $service->apply($e, $type, '2026-03-03', '2026-03-03', null, $this->a->id);
        $service->approve($second, null, $this->a->id);
        $this->assertSame('approved', $second->refresh()->status);                              // the mail error did not undo it
    }

    public function test_every_employee_is_told_when_a_payroll_is_approved(): void
    {
        $one = $this->hire('one@test.com');
        $two = $this->hire('two@test.com');
        foreach ([$one, $two] as $e) {
            foreach (WorkCalendar::for($this->a->id)->workingDays('2026-03-01', '2026-03-31') as $day) {
                Attendance::create(['employee_id' => $e->id, 'date' => $day, 'status' => 'present', 'total_hours' => 8, 'source' => 'manual', 'created_by' => $this->a->id]);
            }
        }
        $payroll = app(PayrollService::class)->generate($this->a->id, '2026-03', $this->a->id);
        $this->assertCount(0, $this->sent());                                                   // a draft tells nobody

        app(PayrollService::class)->approve($payroll, $this->a->id);

        $this->assertCount(2, $this->sent());
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('one@test.com') && $m->mailSubject === 'Your payslip for 2026-03 is ready' && !str_contains($m->htmlBody, '26,000'));
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('two@test.com'));
    }

    private function crm(): array
    {
        $this->actingAs($this->a)->get('/crm/setup')->assertOk();
        $pipeline = Pipeline::where('created_by', $this->a->id)->firstOrFail();

        return [$pipeline, LeadStage::where('pipeline_id', $pipeline->id)->orderBy('order')->pluck('id', 'name')->all()];
    }

    public function test_assigned_staff_hear_about_a_lead_but_not_the_person_who_did_it(): void
    {
        [$pipeline, $stages] = $this->crm();
        $ravi = $this->staff('ravi@test.com');
        $leads = app(LeadService::class);

        $lead = $leads->create($this->a->id, $this->a->id, ['subject' => 'Website', 'name' => 'Anita', 'pipeline_id' => $pipeline->id], [$ravi->id, $this->a->id]);
        $this->assertCount(0, $this->sent());                                                   // creating assigns nobody afterwards

        $leads->update($lead, $this->a->id, ['subject' => 'Website', 'name' => 'Anita'], [$ravi->id, $this->a->id, $this->staff('kim@test.com')->id]);

        $this->assertCount(1, $this->sent());                                                   // only the NEW person (not the actor, not Ravi again)
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('kim@test.com') && $m->mailSubject === 'New lead for you: Website' && str_contains($m->htmlBody, 'Kim'));
    }

    public function test_moving_a_lead_tells_the_others_working_on_it(): void
    {
        [$pipeline, $stages] = $this->crm();
        $ravi = $this->staff('ravi@test.com');
        $leads = app(LeadService::class);
        $lead = $leads->create($this->a->id, $this->a->id, ['subject' => 'Website', 'name' => 'Anita', 'pipeline_id' => $pipeline->id], [$ravi->id, $this->a->id]);

        $leads->move($lead, $stages['Contacted'], [$lead->id], $this->a->id);                 // the owner moves it
        $leads->move($lead->refresh(), $stages['Contacted'], [$lead->id], $this->a->id);      // same stage again: nothing

        $this->assertCount(1, $this->sent());
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('ravi@test.com') && $m->mailSubject === 'Lead "Website" moved to Contacted' && str_contains($m->htmlBody, 'from New to Contacted'));
    }

    public function test_winning_a_deal_tells_the_others_working_on_it(): void
    {
        [$pipeline] = $this->crm();
        $ravi = $this->staff('ravi@test.com');
        $deals = app(DealService::class);
        $deal = $deals->create($this->a->id, $this->a->id, ['name' => 'Hospital ERP', 'price' => 52000, 'pipeline_id' => $pipeline->id], [$ravi->id, $this->a->id], []);

        $deals->setStatus($deal, 'lost', $this->a->id);
        $this->assertCount(0, $this->sent());                                                   // only a win is good news
        $deals->setStatus($deal, 'won', $this->a->id);

        $this->assertCount(1, $this->sent());
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('ravi@test.com') && $m->mailSubject === 'Deal won: Hospital ERP' && str_contains($m->htmlBody, '52,000.00'));
    }

    public function test_the_owner_hears_about_a_website_enquiry(): void
    {
        $this->crm();
        $this->actingAs($this->a)->post('/crm/setup/automation/token');
        $token = tenantSettings($this->a->id)['crmWebToLeadToken'];

        $this->postJson("/crm/web-to-lead/{$token}", ['name' => 'Visitor <b>', 'email' => 'v@site.com', 'message' => 'Call me'])->assertOk();

        $this->assertCount(1, $this->sent());
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $m) => $m->hasTo('a@test.com'));
        $mail = $this->sent()->first();
        $this->assertStringContainsString('Call me', $mail->htmlBody);
        $this->assertStringNotContainsString('<b>', $mail->htmlBody);                            // the visitor's markup is neutralised
        $this->assertSame(1, Lead::where('created_by', $this->a->id)->count());
    }
}
