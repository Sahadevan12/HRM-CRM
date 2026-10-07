<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Workdo\Lead\Events\LeadCallAdded;
use Workdo\Lead\Events\LeadFileUploaded;
use Workdo\Lead\Models\Label;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadActivityLog;
use Workdo\Lead\Models\LeadCall;
use Workdo\Lead\Models\LeadFile;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Models\Source;
use Workdo\ProductService\Models\Product;

class CrmLeadDetailTest extends TestCase
{
    private User $a;

    private Pipeline $sales;

    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->a = $this->company('a@test.com');
        $this->sales = $this->seed_($this->a);
        $this->lead = $this->makeLead($this->a, $this->sales);
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

    private function seed_(User $t): Pipeline
    {
        $this->actingAs($t)->get('/crm/setup')->assertOk();

        return Pipeline::where('created_by', $t->id)->firstOrFail();
    }

    private function makeLead(User $t, Pipeline $p): Lead
    {
        return Lead::create([
            'subject' => 'Deal', 'name' => 'Anita', 'pipeline_id' => $p->id, 'lead_stage_id' => LeadStage::where('pipeline_id', $p->id)->orderBy('order')->value('id'),
            'creator_id' => $t->id, 'created_by' => $t->id,
        ]);
    }

    private function staff(string $email, array $permissions): User
    {
        $u = User::create(['name' => ucfirst($email), 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $u->assignRole('staff');
        $u->givePermissionTo($permissions);
        $this->lead->users()->attach($u->id); // staff only see leads they work on

        return $u;
    }

    private function product(User $t, string $sku = 'P1'): Product
    {
        return Product::create(['name' => 'Widget ' . $sku, 'sku' => $sku, 'type' => 'product', 'sale_price' => 10, 'purchase_price' => 5, 'creator_id' => $t->id, 'created_by' => $t->id]);
    }

    private function url(string $suffix = ''): string
    {
        return "/crm/leads/{$this->lead->id}/" . ltrim($suffix, '/');
    }

    private function api(User $as, string $method, string $suffix, array $data = [])
    {
        return $this->actingAs($as)->json($method, $this->url($suffix), $data);
    }

    // ───────────── detail ─────────────

    public function test_the_detail_holds_everything_the_drawer_shows(): void
    {
        $res = $this->api($this->a, 'GET', 'detail')->assertOk();

        $res->assertJsonPath('lead.id', $this->lead->id)->assertJsonPath('lead.stage.name', 'New')->assertJsonPath('lead.pipeline.name', 'Sales')
            ->assertJsonStructure(['lead' => ['sources', 'labels', 'products', 'tasks', 'calls', 'emails', 'discussions', 'files', 'activities', 'users'], 'me']);
        $this->assertArrayNotHasKey('file_path', LeadFile::make(['file_path' => 'x'])->toArray());
    }

    public function test_detail_is_locked_to_company_visibility_and_permission(): void
    {
        $b = $this->company('b@test.com');
        $this->seed_($b);
        $lonely = User::create(['name' => 'L', 'email' => 'l@test.com', 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $lonely->assignRole('staff');
        $lonely->givePermissionTo(['manage-leads', 'edit-leads', 'manage-lead-calls']);
        $noPerm = $this->staff('np@test.com', []);

        $this->api($b, 'GET', 'detail')->assertForbidden();                                  // other company
        $this->api($lonely, 'GET', 'detail')->assertForbidden();                             // not assigned, not allowed to see all
        $this->api($lonely, 'POST', 'items/call', ['subject' => 's', 'call_type' => 'inbound', 'duration_minutes' => 1])->assertForbidden();
        $this->api($noPerm, 'GET', 'detail')->assertForbidden();                             // assigned, but no CRM permission
        $this->api($b, 'PUT', 'sync', ['source_ids' => []])->assertForbidden();
    }

    // ───────────── sources / labels / products ─────────────

    public function test_sources_labels_and_products_are_assigned_and_logged(): void
    {
        $source = Source::where('created_by', $this->a->id)->firstOrFail();
        $label = Label::where('created_by', $this->a->id)->firstOrFail();
        $product = $this->product($this->a);

        $this->api($this->a, 'PUT', 'sync', ['source_ids' => [$source->id], 'label_ids' => [$label->id], 'product_ids' => [$product->id]])->assertOk()
            ->assertJsonPath('lead.sources.0.name', $source->name)->assertJsonPath('lead.labels.0.color', $label->color)->assertJsonPath('lead.products.0.sku', 'P1');

        $this->assertEqualsCanonicalizing(['sources', 'labels', 'products'], LeadActivityLog::where('lead_id', $this->lead->id)->pluck('type')->all());

        // same data again: nothing new in the trail
        $this->api($this->a, 'PUT', 'sync', ['source_ids' => [$source->id], 'label_ids' => [$label->id], 'product_ids' => [$product->id]])->assertOk();
        $this->assertSame(3, LeadActivityLog::where('lead_id', $this->lead->id)->count());

        // a key that is left out is not touched, an empty list clears
        $this->api($this->a, 'PUT', 'sync', ['label_ids' => []])->assertOk()->assertJsonCount(0, 'lead.labels')->assertJsonCount(1, 'lead.sources');
    }

    public function test_foreign_or_misplaced_references_are_rejected(): void
    {
        $b = $this->company('b@test.com');
        $bPipeline = $this->seed_($b);
        $bSource = Source::where('created_by', $b->id)->firstOrFail();
        $bLabel = Label::where('created_by', $b->id)->firstOrFail();
        $bProduct = $this->product($b, 'B1');

        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);
        $partners = Pipeline::where('name', 'Partners')->firstOrFail();
        $this->actingAs($this->a)->post('/crm/setup/labels', ['name' => 'VIP', 'color' => '#112233', 'pipeline_id' => $partners->id]);
        $otherPipelineLabel = Label::where('name', 'VIP')->firstOrFail();

        $this->api($this->a, 'PUT', 'sync', ['source_ids' => [$bSource->id]])->assertJsonValidationErrors('source_ids.0');
        $this->api($this->a, 'PUT', 'sync', ['label_ids' => [$bLabel->id]])->assertJsonValidationErrors('label_ids.0');
        $this->api($this->a, 'PUT', 'sync', ['label_ids' => [$otherPipelineLabel->id]])->assertJsonValidationErrors('label_ids.0');   // a label of another pipeline
        $this->api($this->a, 'PUT', 'sync', ['product_ids' => [$bProduct->id]])->assertJsonValidationErrors('product_ids.0');

        $this->assertSame(0, $this->lead->sources()->count() + $this->lead->labels()->count() + $this->lead->products()->count());
        $this->assertNotNull($bPipeline);
    }

    public function test_sources_and_labels_in_use_cannot_be_deleted(): void
    {
        $source = Source::where('created_by', $this->a->id)->firstOrFail();
        $label = Label::where('created_by', $this->a->id)->firstOrFail();
        $this->api($this->a, 'PUT', 'sync', ['source_ids' => [$source->id], 'label_ids' => [$label->id]]);

        $this->actingAs($this->a)->delete("/crm/setup/sources/{$source->id}")->assertSessionHas('error');
        $this->actingAs($this->a)->delete("/crm/setup/labels/{$label->id}")->assertSessionHas('error');

        $this->api($this->a, 'PUT', 'sync', ['source_ids' => [], 'label_ids' => []]);
        $this->actingAs($this->a)->delete("/crm/setup/sources/{$source->id}")->assertSessionHas('success');
        $this->actingAs($this->a)->delete("/crm/setup/labels/{$label->id}")->assertSessionHas('success');
    }

    // ───────────── tasks, calls, e-mails, discussion ─────────────

    public function test_tasks_calls_emails_and_comments_are_added_logged_and_validated(): void
    {
        Event::fake([LeadCallAdded::class]);

        $this->api($this->a, 'POST', 'items/task', ['name' => 'Send quote', 'due_date' => '2026-12-01', 'priority' => 'high'])->assertOk()->assertJsonPath('lead.tasks.0.status', 'on_going');
        $this->api($this->a, 'POST', 'items/call', ['subject' => 'Intro', 'call_type' => 'outbound', 'duration_minutes' => 12, 'result' => 'Interested'])->assertOk()->assertJsonPath('lead.calls.0.duration_minutes', 12);
        $this->api($this->a, 'POST', 'items/email', ['to' => 'anita@x.com', 'subject' => 'Quote', 'description' => 'attached'])->assertOk()->assertJsonPath('lead.emails.0.to', 'anita@x.com');
        $this->api($this->a, 'POST', 'items/discussion', ['comment' => 'Called twice'])->assertOk()->assertJsonPath('lead.discussions.0.comment', 'Called twice');

        Event::assertDispatched(LeadCallAdded::class, fn ($e) => $e->lead->id === $this->lead->id && $e->item instanceof LeadCall);
        $this->assertEqualsCanonicalizing(['task', 'call', 'email', 'discussion'], LeadActivityLog::where('lead_id', $this->lead->id)->pluck('type')->all());

        $this->api($this->a, 'POST', 'items/task', ['name' => '', 'due_date' => 'soon', 'priority' => 'urgent'])->assertJsonValidationErrors(['name', 'due_date', 'priority']);
        $this->api($this->a, 'POST', 'items/call', ['subject' => 'x', 'call_type' => 'sideways', 'duration_minutes' => -3])->assertJsonValidationErrors(['call_type', 'duration_minutes']);
        $this->api($this->a, 'POST', 'items/email', ['to' => 'nope', 'subject' => 'x'])->assertJsonValidationErrors('to');
        $this->api($this->a, 'POST', 'items/discussion', ['comment' => ''])->assertJsonValidationErrors('comment');
        $this->api($this->a, 'POST', 'items/bogus', [])->assertNotFound();
    }

    public function test_a_task_can_be_completed_and_reopened(): void
    {
        $this->api($this->a, 'POST', 'items/task', ['name' => 'T', 'due_date' => '2026-12-01', 'priority' => 'low']);
        $id = $this->lead->tasks()->value('id');

        $this->api($this->a, 'POST', "tasks/{$id}/toggle")->assertJsonPath('lead.tasks.0.status', 'completed');
        $this->api($this->a, 'POST', "tasks/{$id}/toggle")->assertJsonPath('lead.tasks.0.status', 'on_going');
        $this->assertSame(3, LeadActivityLog::where('lead_id', $this->lead->id)->where('type', 'task')->count());
    }

    public function test_every_action_needs_its_own_permission(): void
    {
        $caller = $this->staff('c@test.com', ['manage-leads', 'manage-lead-calls']);

        $this->api($caller, 'POST', 'items/call', ['subject' => 's', 'call_type' => 'inbound', 'duration_minutes' => 1])->assertOk();
        $this->api($caller, 'POST', 'items/task', ['name' => 'T', 'due_date' => '2026-12-01', 'priority' => 'low'])->assertForbidden();
        $this->api($caller, 'POST', 'items/discussion', ['comment' => 'x'])->assertForbidden();
        $this->api($caller, 'POST', 'files', ['file' => UploadedFile::fake()->create('a.pdf', 10)])->assertForbidden();
        $this->api($caller, 'PUT', 'sync', ['source_ids' => []])->assertForbidden();                    // needs edit-leads
    }

    public function test_authors_remove_their_own_entries_and_deleters_any(): void
    {
        $author = $this->staff('author@test.com', ['manage-leads', 'manage-lead-discussions']);
        $other = $this->staff('other@test.com', ['manage-leads', 'manage-lead-discussions']);
        $boss = $this->staff('boss@test.com', ['manage-leads', 'manage-lead-discussions', 'delete-leads']);

        $this->api($author, 'POST', 'items/discussion', ['comment' => 'mine']);
        $id = $this->lead->discussions()->value('id');

        $this->api($other, 'DELETE', "items/discussion/{$id}")->assertForbidden();
        $this->assertSame(1, $this->lead->discussions()->count());

        $this->api($author, 'DELETE', "items/discussion/{$id}")->assertOk()->assertJsonCount(0, 'lead.discussions');

        $this->api($author, 'POST', 'items/discussion', ['comment' => 'again']);
        $this->api($boss, 'DELETE', 'items/discussion/' . $this->lead->discussions()->value('id'))->assertOk();
        $this->assertSame(0, $this->lead->discussions()->count());
    }

    public function test_an_entry_of_another_lead_cannot_be_reached_through_this_one(): void
    {
        $other = $this->makeLead($this->a, $this->sales);
        $other->discussions()->create(['comment' => 'private', 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $id = $other->discussions()->value('id');

        $this->api($this->a, 'DELETE', "items/discussion/{$id}")->assertNotFound();
        $this->assertSame(1, $other->discussions()->count());
    }

    // ───────────── files ─────────────

    public function test_files_are_stored_privately_downloaded_and_deleted(): void
    {
        $this->api($this->a, 'POST', 'files', ['file' => UploadedFile::fake()->create('offer.pdf', 120, 'application/pdf')])->assertOk()->assertJsonPath('lead.files.0.file_name', 'offer.pdf');
        $file = LeadFile::firstOrFail();
        Storage::disk('local')->assertExists($file->file_path);
        $this->assertStringNotContainsString('file_path', json_encode($this->api($this->a, 'GET', 'detail')->json('lead.files.0')));

        $this->actingAs($this->a)->get($this->url("files/{$file->id}"))->assertOk()->assertDownload('offer.pdf');

        $b = $this->company('b@test.com');
        $this->seed_($b);
        $this->actingAs($b)->get($this->url("files/{$file->id}"))->assertForbidden();

        $this->api($this->a, 'DELETE', "items/file/{$file->id}")->assertOk()->assertJsonCount(0, 'lead.files');
        Storage::disk('local')->assertMissing($file->file_path);
    }

    public function test_file_rules_and_the_upload_event(): void
    {
        Event::fake([LeadFileUploaded::class]);

        $this->api($this->a, 'POST', 'files', ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertJsonValidationErrors('file');
        $this->api($this->a, 'POST', 'files', ['file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')])->assertJsonValidationErrors('file');
        $this->api($this->a, 'POST', 'files', [])->assertJsonValidationErrors('file');
        $this->assertSame(0, LeadFile::count());

        $this->api($this->a, 'POST', 'files', ['file' => UploadedFile::fake()->create('ok.docx', 10)])->assertOk();
        Event::assertDispatched(LeadFileUploaded::class);
    }

    public function test_the_board_page_carries_the_drawer_options(): void
    {
        $this->product($this->a);

        $this->actingAs($this->a)->get('/crm/leads')->assertOk()->assertInertia(fn ($p) => $p->component('Lead/Leads/Index', false)
            ->has('sourceOptions', 6)->has('labelOptions', 3)->has('productOptions', 1)->where('can_detail.file', true));
    }
}
