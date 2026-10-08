<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Workdo\Lead\Events\DealStatusChanged;
use Workdo\Lead\Events\LeadConverted;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealActivityLog;
use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Label;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Models\Source;
use Workdo\ProductService\Models\Product;

class CrmDealsTest extends TestCase
{
    private User $a;

    private Pipeline $sales;

    /** @var array<string, int> deal stage name => id */
    private array $ds;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->a = $this->company('a@test.com');
        [$this->sales, $this->ds] = $this->seedCrm($this->a);
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

    private function seedCrm(User $t): array
    {
        $this->actingAs($t)->get('/crm/setup')->assertOk();
        $pipeline = Pipeline::where('created_by', $t->id)->firstOrFail();

        return [$pipeline, DealStage::where('pipeline_id', $pipeline->id)->pluck('id', 'name')->all()];
    }

    private function staff(string $email, array $permissions): User
    {
        $u = User::create(['name' => ucfirst($email), 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'staff', 'email_verified_at' => now(), 'creator_id' => $this->a->id, 'created_by' => $this->a->id]);
        $u->assignRole('staff');
        $u->givePermissionTo($permissions);

        return $u;
    }

    private function client(User $t, string $email = 'client@test.com'): User
    {
        $c = User::create(['name' => 'Client ' . $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'client', 'email_verified_at' => now(), 'creator_id' => $t->id, 'created_by' => $t->id]);
        $c->assignRole('client');

        return $c;
    }

    private function deal(array $extra = [], ?User $as = null): Deal
    {
        $this->actingAs($as ?? $this->a)->post('/crm/deals', $extra + ['name' => 'Big deal', 'price' => 1000, 'pipeline_id' => $this->sales->id])->assertSessionHasNoErrors();

        return Deal::latest('id')->firstOrFail();
    }

    private function lead(array $extra = []): Lead
    {
        return Lead::create($extra + [
            'subject' => 'Website', 'name' => 'Anita', 'email' => 'anita@x.com', 'phone' => '555', 'notes' => 'hot', 'pipeline_id' => $this->sales->id,
            'lead_stage_id' => LeadStage::where('pipeline_id', $this->sales->id)->orderBy('order')->value('id'), 'creator_id' => $this->a->id, 'created_by' => $this->a->id,
        ]);
    }

    private function ids(int $stageId): array
    {
        return Deal::where('deal_stage_id', $stageId)->orderBy('order')->pluck('id')->all();
    }

    // ───────────── deals: CRUD, board ─────────────

    public function test_a_deal_lands_in_the_first_stage_with_staff_clients_and_a_log(): void
    {
        $sam = $this->staff('sam@test.com', ['manage-deals']);
        $client = $this->client($this->a);

        $deal = $this->deal(['user_ids' => [$sam->id], 'client_ids' => [$client->id], 'phone' => '123']);
        $second = $this->deal(['name' => 'Second']);

        $this->assertSame($this->ds['Initial Contact'], $deal->deal_stage_id);
        $this->assertSame([1, 2], [$deal->order, $second->order]);
        $this->assertSame('active', $deal->status);
        $this->assertSame([$sam->id], $deal->users()->pluck('users.id')->all());
        $this->assertSame([$client->id], $deal->clients()->pluck('users.id')->all());
        $this->assertSame('created', DealActivityLog::where('deal_id', $deal->id)->value('type'));
    }

    public function test_deal_validation_and_foreign_references(): void
    {
        $b = $this->company('b@test.com');
        [$bPipeline, $bStages] = $this->seedCrm($b);
        $bClient = $this->client($b, 'bc@test.com');
        $walkIn = $this->client($this->a, 'walk@test.com');
        setSetting('walkInCustomerId', $walkIn->id, $this->a->id, false);

        $ok = ['name' => 'N', 'price' => 5, 'pipeline_id' => $this->sales->id];
        $post = fn (array $d) => $this->actingAs($this->a)->post('/crm/deals', $d);

        $post(['price' => 5, 'pipeline_id' => $this->sales->id])->assertSessionHasErrors('name');
        $post(['name' => 'N', 'pipeline_id' => $this->sales->id])->assertSessionHasErrors('price');
        $post(array_merge($ok, ['price' => -1]))->assertSessionHasErrors('price');
        $post(array_merge($ok, ['pipeline_id' => $bPipeline->id]))->assertSessionHasErrors('pipeline_id');
        $post(array_merge($ok, ['deal_stage_id' => $bStages['Meeting']]))->assertSessionHasErrors('deal_stage_id');
        $post(array_merge($ok, ['client_ids' => [$bClient->id]]))->assertSessionHasErrors('client_ids.0');
        $post(array_merge($ok, ['client_ids' => [$walkIn->id]]))->assertSessionHasErrors('client_ids.0');   // the POS walk-in customer is internal
        $post(array_merge($ok, ['client_ids' => [$this->a->id]]))->assertSessionHasErrors('client_ids.0');   // only users of type client
        $this->assertSame(0, Deal::count());
    }

    public function test_update_logs_changes_and_never_changes_the_stage(): void
    {
        $client = $this->client($this->a);
        $deal = $this->deal();

        $this->actingAs($this->a)->put("/crm/deals/{$deal->id}", ['name' => 'Renamed', 'price' => 2500.5, 'client_ids' => [$client->id], 'deal_stage_id' => $this->ds['Meeting']])->assertSessionHas('success');

        $deal->refresh();
        $this->assertSame('Renamed', $deal->name);
        $this->assertEquals(2500.5, $deal->price);
        $this->assertSame($this->ds['Initial Contact'], $deal->deal_stage_id);
        $this->assertEqualsCanonicalizing(['created', 'clients', 'updated'], DealActivityLog::where('deal_id', $deal->id)->pluck('type')->all());
    }

    public function test_moving_deals_rewrites_the_column_and_logs(): void
    {
        $a = $this->deal(['name' => 'A']);
        $b = $this->deal(['name' => 'B']);
        $c = $this->deal(['name' => 'C', 'deal_stage_id' => $this->ds['Meeting']]);

        $this->actingAs($this->a)->post('/crm/deals/move', ['deal_id' => $a->id, 'deal_stage_id' => $this->ds['Meeting'], 'ids' => [$c->id, $a->id]])->assertSessionHas('success');

        $this->assertSame([$c->id, $a->id], $this->ids($this->ds['Meeting']));
        $this->assertSame([$b->id], $this->ids($this->ds['Initial Contact']));
        $this->assertSame('Moved from Initial Contact to Meeting', DealActivityLog::where('deal_id', $a->id)->where('type', 'moved')->value('remark'));
    }

    public function test_bad_moves_change_nothing(): void
    {
        $b = $this->company('b@test.com');
        [$bPipeline, $bStages] = $this->seedCrm($b);
        $foreign = Deal::create(['name' => 'B', 'pipeline_id' => $bPipeline->id, 'deal_stage_id' => $bStages['Meeting'], 'creator_id' => $b->id, 'created_by' => $b->id]);
        $mine = $this->deal();
        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);
        $partnerStage = DealStage::where('pipeline_id', Pipeline::where('name', 'Partners')->value('id'))->value('id');

        $move = fn (array $d) => $this->actingAs($this->a)->post('/crm/deals/move', $d);
        $move(['deal_id' => $mine->id, 'deal_stage_id' => $partnerStage, 'ids' => [$mine->id]])->assertSessionHas('error');
        $move(['deal_id' => $mine->id, 'deal_stage_id' => $bStages['Meeting'], 'ids' => [$mine->id]])->assertSessionHas('error');
        $move(['deal_id' => $mine->id, 'deal_stage_id' => $this->ds['Meeting'], 'ids' => [$foreign->id]])->assertSessionHas('error');
        $move(['deal_id' => $mine->id, 'deal_stage_id' => $this->ds['Meeting'], 'ids' => [$mine->id, $foreign->id]])->assertSessionHas('error');
        $move(['deal_id' => $foreign->id, 'deal_stage_id' => $this->ds['Meeting'], 'ids' => [$foreign->id]])->assertNotFound();

        $this->assertSame($this->ds['Initial Contact'], $mine->refresh()->deal_stage_id);
    }

    // ───────────── won / lost ─────────────

    public function test_won_lost_and_reopen_with_log_and_event(): void
    {
        Event::fake([DealStatusChanged::class]);
        $deal = $this->deal();

        $this->actingAs($this->a)->post("/crm/deals/{$deal->id}/status", ['status' => 'won'])->assertSessionHas('success');
        $this->assertSame('won', $deal->refresh()->status);
        Event::assertDispatched(DealStatusChanged::class, fn ($e) => $e->deal->id === $deal->id && $e->from === 'active' && $e->to === 'won');
        $this->assertSame('Status changed from active to won', DealActivityLog::where('deal_id', $deal->id)->where('type', 'status')->value('remark'));

        $this->actingAs($this->a)->post("/crm/deals/{$deal->id}/status", ['status' => 'won'])->assertSessionHas('error');        // already won
        $this->actingAs($this->a)->post("/crm/deals/{$deal->id}/status", ['status' => 'banana'])->assertSessionHasErrors('status');

        // a decided deal stays where it is until it is reopened
        $this->actingAs($this->a)->post('/crm/deals/move', ['deal_id' => $deal->id, 'deal_stage_id' => $this->ds['Meeting'], 'ids' => [$deal->id]])->assertSessionHas('error');
        $this->actingAs($this->a)->post("/crm/deals/{$deal->id}/status", ['status' => 'active'])->assertSessionHas('success');
        $this->actingAs($this->a)->post('/crm/deals/move', ['deal_id' => $deal->id, 'deal_stage_id' => $this->ds['Meeting'], 'ids' => [$deal->id]])->assertSessionHas('success');

        $this->actingAs($this->a)->post("/crm/deals/{$deal->id}/status", ['status' => 'lost'])->assertSessionHas('success');
        $this->assertSame('lost', $deal->refresh()->status);
    }

    // ───────────── visibility and permissions ─────────────

    public function test_deal_visibility_and_permissions(): void
    {
        $sam = $this->staff('sam@test.com', ['manage-deals', 'create-deals', 'edit-deals', 'move-deals', 'change-deal-status']);
        $kim = $this->staff('kim@test.com', ['manage-deals']);

        $owners = $this->deal(['name' => 'Owner only']);
        $assigned = $this->deal(['name' => 'Assigned to Sam', 'user_ids' => [$sam->id]]);
        $own = $this->deal(['name' => 'Created by Sam'], $sam);

        $names = fn (User $u) => collect($this->actingAs($u)->get('/crm/deals')->assertOk()->viewData('page')['props']['deals'])->pluck('name')->sort()->values()->all();
        $this->assertSame(['Assigned to Sam', 'Created by Sam'], $names($sam));
        $this->assertSame([], $names($kim));
        $this->assertCount(3, $this->actingAs($this->a)->get('/crm/deals')->viewData('page')['props']['deals']);

        // what they cannot see they cannot change
        $this->actingAs($sam)->put("/crm/deals/{$owners->id}", ['name' => 'Hacked', 'price' => 1])->assertSessionHas('error');
        $this->actingAs($sam)->post("/crm/deals/{$owners->id}/status", ['status' => 'won'])->assertSessionHas('error');
        $this->actingAs($sam)->post('/crm/deals/move', ['deal_id' => $owners->id, 'deal_stage_id' => $this->ds['Meeting'], 'ids' => [$owners->id]])->assertSessionHas('error');
        $this->assertSame('Owner only', $owners->refresh()->name);

        // and permissions are per action
        $this->actingAs($kim)->post('/crm/deals', ['name' => 'x', 'price' => 1, 'pipeline_id' => $this->sales->id])->assertSessionHas('error');
        $this->actingAs($sam)->delete("/crm/deals/{$own->id}")->assertSessionHas('error');
        $this->actingAs($this->staff('none@test.com', []))->get('/crm/deals')->assertRedirect(route('dashboard'));
        $this->assertNotNull($assigned);
    }

    public function test_other_companies_cannot_touch_a_deal(): void
    {
        $deal = $this->deal();
        $b = $this->company('b@test.com');
        $this->seedCrm($b);

        $this->actingAs($b)->put("/crm/deals/{$deal->id}", ['name' => 'Hacked', 'price' => 1])->assertSessionHas('error');
        $this->actingAs($b)->delete("/crm/deals/{$deal->id}")->assertSessionHas('error');
        $this->actingAs($b)->post("/crm/deals/{$deal->id}/status", ['status' => 'won'])->assertSessionHas('error');
        $this->actingAs($b)->getJson("/crm/deals/{$deal->id}/detail")->assertForbidden();
        $this->assertCount(0, $this->actingAs($b)->get('/crm/deals')->viewData('page')['props']['deals']);
        $this->assertSame('Big deal', $deal->refresh()->name);
    }

    public function test_deal_list_view_filters_by_status_stage_and_search(): void
    {
        $this->deal(['name' => 'Alpha']);
        $beta = $this->deal(['name' => 'Beta', 'deal_stage_id' => $this->ds['Proposal']]);
        $beta->update(['status' => 'won']);

        $names = fn (string $q) => collect($this->actingAs($this->a)->get('/crm/deals?view=list&pipeline=' . $this->sales->id . $q)->viewData('page')['props']['deals']['data'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Alpha', 'Beta'], $names(''));
        $this->assertSame(['Beta'], $names('&status=won'));
        $this->assertSame(['Alpha'], $names('&search=Alp'));
        $this->assertSame(['Beta'], $names('&stage=' . $this->ds['Proposal']));
    }

    public function test_deal_detail_drawer_works_with_deal_labels_and_clients(): void
    {
        $client = $this->client($this->a);
        $deal = $this->deal(['client_ids' => [$client->id]]);
        $label = Label::where('created_by', $this->a->id)->firstOrFail();
        $source = Source::where('created_by', $this->a->id)->firstOrFail();

        $r = $this->actingAs($this->a)->putJson("/crm/deals/{$deal->id}/sync", ['label_ids' => [$label->id], 'source_ids' => [$source->id]]);
        $r->assertOk()->assertJsonPath('deal.labels.0.name', $label->name);
        $this->actingAs($this->a)->postJson("/crm/deals/{$deal->id}/items/discussion", ['comment' => 'Hello'])->assertOk()->assertJsonPath('deal.discussions.0.comment', 'Hello');
        $this->actingAs($this->a)->getJson("/crm/deals/{$deal->id}/detail")->assertOk()->assertJsonPath('deal.clients.0.id', $client->id)->assertJsonPath('deal.stage.name', 'Initial Contact');

        // sources / labels / stages in use cannot be deleted from the setup
        $this->actingAs($this->a)->delete("/crm/setup/labels/{$label->id}")->assertSessionHas('error');
        $this->actingAs($this->a)->delete("/crm/setup/sources/{$source->id}")->assertSessionHas('error');
        $this->actingAs($this->a)->delete('/crm/setup/deal-stages/' . $this->ds['Initial Contact'])->assertSessionHas('error');
        $this->actingAs($this->a)->delete("/crm/setup/pipelines/{$this->sales->id}")->assertSessionHas('error');
    }

    public function test_deleting_a_deal_removes_its_files_and_frees_the_lead(): void
    {
        $lead = $this->lead();
        $deal = $this->deal(['name' => 'D']);
        $deal->update(['lead_id' => $lead->id]);
        $lead->update(['is_converted' => true]);
        $this->actingAs($this->a)->postJson("/crm/deals/{$deal->id}/files", ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])->assertOk();
        $path = $deal->files()->first()->file_path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->a)->delete("/crm/deals/{$deal->id}")->assertSessionHas('success');

        Storage::disk('local')->assertMissing($path);
        $this->assertFalse($lead->refresh()->is_converted);
    }

    // ───────────── convert ─────────────

    private function convert(Lead $lead, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->a)->post("/crm/leads/{$lead->id}/convert", $extra + ['price' => 750, 'pipeline_id' => $this->sales->id, 'client_mode' => 'none', 'copy' => []]);
    }

    private function loadedLead(): Lead
    {
        $lead = $this->lead();
        $sam = $this->staff('sam@test.com', ['manage-deals']);
        $lead->users()->attach($sam->id);
        $lead->sources()->attach(Source::where('created_by', $this->a->id)->value('id'));
        $lead->labels()->attach(Label::where('created_by', $this->a->id)->value('id'));
        $lead->products()->attach(Product::create(['name' => 'Widget', 'sku' => 'W1', 'type' => 'product', 'sale_price' => 10, 'purchase_price' => 5, 'creator_id' => $this->a->id, 'created_by' => $this->a->id])->id);

        $this->actingAs($this->a)->postJson("/crm/leads/{$lead->id}/items/task", ['name' => 'Quote', 'due_date' => '2026-12-01', 'priority' => 'high'])->assertOk();
        $this->actingAs($this->a)->postJson("/crm/leads/{$lead->id}/items/call", ['subject' => 'Intro', 'call_type' => 'outbound', 'duration_minutes' => 5])->assertOk();
        $this->actingAs($this->a)->postJson("/crm/leads/{$lead->id}/items/email", ['to' => 'a@x.com', 'subject' => 'Hi'])->assertOk();
        $this->actingAs($this->a)->postJson("/crm/leads/{$lead->id}/items/discussion", ['comment' => 'Note'])->assertOk();
        $this->actingAs($this->a)->postJson("/crm/leads/{$lead->id}/files", ['file' => UploadedFile::fake()->create('offer.pdf', 30, 'application/pdf')])->assertOk();

        return $lead->refresh();
    }

    public function test_converting_copies_the_lead_and_everything_ticked(): void
    {
        Event::fake([LeadConverted::class]);
        $lead = $this->loadedLead();

        $this->convert($lead, ['copy' => ['products', 'sources', 'labels', 'tasks', 'calls', 'emails', 'discussions', 'files']])->assertSessionHas('success');

        $deal = Deal::firstOrFail();
        $this->assertSame('Website', $deal->name);
        $this->assertSame('555', $deal->phone);
        $this->assertSame('hot', $deal->notes);
        $this->assertEquals(750, $deal->price);
        $this->assertSame($lead->id, $deal->lead_id);
        $this->assertSame($this->ds['Initial Contact'], $deal->deal_stage_id);
        $this->assertSame($lead->users()->pluck('users.id')->all(), $deal->users()->pluck('users.id')->all());
        $this->assertSame([1, 1, 1], [$deal->sources()->count(), $deal->labels()->count(), $deal->products()->count()]);
        $this->assertSame([1, 1, 1, 1, 1], [$deal->tasks()->count(), $deal->calls()->count(), $deal->emails()->count(), $deal->discussions()->count(), $deal->files()->count()]);

        // the file is a real, separate copy
        $copy = $deal->files()->first();
        $original = $lead->files()->first();
        $this->assertNotSame($original->file_path, $copy->file_path);
        Storage::disk('local')->assertExists($copy->file_path);
        Storage::disk('local')->assertExists($original->file_path);
        $this->assertSame('offer.pdf', $copy->file_name);

        $this->assertTrue($lead->refresh()->is_converted);
        $this->assertSame('converted', DealActivityLog::where('deal_id', $deal->id)->latest('id')->value('type'));
        $this->assertSame(1, \Workdo\Lead\Models\LeadActivityLog::where('lead_id', $lead->id)->where('type', 'converted')->count());
        Event::assertDispatched(LeadConverted::class);
    }

    public function test_only_the_ticked_children_are_copied(): void
    {
        $lead = $this->loadedLead();

        $this->convert($lead, ['copy' => ['tasks']])->assertSessionHas('success');

        $deal = Deal::firstOrFail();
        $this->assertSame(1, $deal->tasks()->count());
        $this->assertSame(0, $deal->calls()->count() + $deal->emails()->count() + $deal->discussions()->count() + $deal->files()->count() + $deal->products()->count() + $deal->sources()->count() + $deal->labels()->count());
    }

    public function test_labels_follow_only_into_the_same_pipeline(): void
    {
        $this->actingAs($this->a)->post('/crm/setup/pipelines', ['name' => 'Partners']);
        $partners = Pipeline::where('name', 'Partners')->firstOrFail();
        $lead = $this->loadedLead();

        $this->convert($lead, ['pipeline_id' => $partners->id, 'copy' => ['labels', 'tasks']])->assertSessionHas('success');

        $deal = Deal::firstOrFail();
        $this->assertSame($partners->id, $deal->pipeline_id);
        $this->assertSame(0, $deal->labels()->count());
        $this->assertSame(1, $deal->tasks()->count());
    }

    public function test_a_converted_lead_is_frozen_and_cannot_be_converted_twice(): void
    {
        $lead = $this->lead();
        $this->convert($lead)->assertSessionHas('success');

        $this->convert($lead)->assertSessionHasErrors('convert');
        $this->assertSame(1, Deal::count());

        $stage = LeadStage::where('pipeline_id', $this->sales->id)->orderBy('order')->skip(1)->value('id');
        $this->actingAs($this->a)->post('/crm/leads/move', ['lead_id' => $lead->id, 'lead_stage_id' => $stage, 'ids' => [$lead->id]])->assertSessionHas('error');
    }

    public function test_client_choices_when_converting(): void
    {
        $existing = $this->client($this->a);
        $b = $this->company('b@test.com');
        $foreign = $this->client($b, 'bc@test.com');

        $this->convert($this->lead(), ['client_mode' => 'existing', 'client_id' => $foreign->id])->assertSessionHasErrors('convert');
        $this->convert($this->lead(), ['client_mode' => 'existing'])->assertSessionHasErrors('client_id');
        $this->assertSame(0, Deal::count());

        $this->convert($this->lead(), ['client_mode' => 'existing', 'client_id' => $existing->id])->assertSessionHas('success');
        $this->assertSame([$existing->id], Deal::firstOrFail()->clients()->pluck('users.id')->all());
    }

    public function test_a_new_client_is_created_without_a_login(): void
    {
        $this->convert($this->lead(), ['client_mode' => 'new', 'client_name' => 'Acme Ltd', 'client_email' => 'acme@x.com'])->assertSessionHas('success');

        $client = User::where('email', 'acme@x.com')->firstOrFail();
        $this->assertSame('client', $client->type);
        $this->assertSame($this->a->id, $client->created_by);
        $this->assertTrue($client->hasRole('client'));
        $this->assertSame(0, (int) $client->is_enable_login);
        $this->assertSame([$client->id], Deal::firstOrFail()->clients()->pluck('users.id')->all());

        // validation: name and e-mail are needed, an existing address is refused (and nothing is half created)
        $this->convert($this->lead(), ['client_mode' => 'new'])->assertSessionHasErrors(['client_name', 'client_email']);
        $this->convert($this->lead(), ['client_mode' => 'new', 'client_name' => 'Dup', 'client_email' => 'acme@x.com'])->assertSessionHasErrors('convert');
        $this->assertSame(1, Deal::count());
        $this->assertSame(1, User::where('type', 'client')->where('created_by', $this->a->id)->count());
    }

    public function test_the_plan_seat_limit_stops_a_new_client(): void
    {
        $this->client($this->a, 'seat@test.com');   // one user already uses the only seat
        $this->a->update(['total_user' => 1]);

        $this->convert($this->lead(), ['client_mode' => 'new', 'client_name' => 'X', 'client_email' => 'x@x.com'])->assertSessionHasErrors('convert');

        $this->assertSame(0, Deal::count());
        $this->assertNull(User::where('email', 'x@x.com')->first());
    }

    public function test_convert_validation_permission_and_isolation(): void
    {
        $lead = $this->lead();
        $b = $this->company('b@test.com');
        [$bPipeline] = $this->seedCrm($b);

        $this->convert($lead, ['price' => ''])->assertSessionHasErrors('price');
        $this->convert($lead, ['price' => -5])->assertSessionHasErrors('price');
        $this->convert($lead, ['pipeline_id' => $bPipeline->id])->assertSessionHasErrors('pipeline_id');
        $this->convert($lead, ['client_mode' => 'weird'])->assertSessionHasErrors('client_mode');
        $this->convert($lead, ['copy' => ['everything']])->assertSessionHasErrors('copy.0');

        $this->convert($lead, [], $b)->assertSessionHas('error');                                       // another company
        $nobody = $this->staff('n@test.com', ['manage-leads']);
        $lead->users()->attach($nobody->id);
        $this->convert($lead, [], $nobody)->assertSessionHas('error');                                  // no convert-leads
        $this->assertSame(0, Deal::count());
        $this->assertFalse($lead->refresh()->is_converted);
    }
}
