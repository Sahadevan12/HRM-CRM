<?php

namespace Workdo\Lead\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Workdo\Lead\Exceptions\LeadException;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Label;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Models\Source;

/** Sample CRM data for demos (`php artisan crm:demo <company e-mail>`): leads in every stage, open / won / lost deals spread over six months. */
class DemoData
{
    private const LEADS = [
        ['Website redesign', 'Anita Rao', 'anita@acme.test'], ['Mobile app quote', 'Karthik S', 'karthik@globex.test'], ['Annual maintenance', 'Meera N', 'meera@initech.test'],
        ['Cloud migration', 'John Miller', 'john@umbrella.test'], ['ERP rollout', 'Priya Shah', 'priya@hooli.test'], ['Training package', 'Ravi K', 'ravi@stark.test'],
        ['Branding project', 'Sara Lee', 'sara@wayne.test'], ['Support contract', 'Tom Baker', 'tom@wonka.test'], ['Data analytics', 'Lakshmi V', 'lakshmi@tyrell.test'],
        ['Security audit', 'David Chen', 'david@cyberdyne.test'], ['POS installation', 'Fatima Z', 'fatima@soylent.test'], ['SEO retainer', 'Peter Pan', 'peter@pied.test'],
    ];

    /** [name, price, status, months ago it was decided] */
    private const DEALS = [
        ['Retail chain POS', 18000, 'won', 5], ['Hospital ERP', 52000, 'won', 3], ['School portal', 9000, 'won', 1], ['Logistics tracking', 24000, 'lost', 4],
        ['Bank onboarding app', 61000, 'lost', 2], ['Hotel booking engine', 33000, 'active', 0], ['Warehouse system', 27000, 'active', 0], ['Clinic CRM', 8000, 'active', 0],
    ];

    public function seed(int $tenant, int $actorId, bool $force = false): array
    {
        if (!$force && (Lead::where('created_by', $tenant)->exists() || Deal::where('created_by', $tenant)->exists())) {
            throw new LeadException(__('This company already has leads or deals. Use --force to add the demo data anyway.'));
        }

        app(DefaultData::class)->ensure($tenant);
        $pipeline = Pipeline::where('created_by', $tenant)->orderBy('id')->firstOrFail();
        $leadStages = LeadStage::where('pipeline_id', $pipeline->id)->orderBy('order')->pluck('id')->all();
        $dealStages = DealStage::where('pipeline_id', $pipeline->id)->orderBy('order')->pluck('id')->all();
        $sources = Source::where('created_by', $tenant)->pluck('id')->all();
        $labels = Label::where('pipeline_id', $pipeline->id)->pluck('id')->all();
        $staff = User::where(fn ($q) => $q->where('id', $tenant)->orWhere('created_by', $tenant))->whereIn('type', ['company', 'staff'])->pluck('id')->all();

        $leads = app(LeadService::class);
        $deals = app(DealService::class);

        return DB::transaction(function () use ($tenant, $actorId, $pipeline, $leadStages, $dealStages, $sources, $labels, $staff, $leads, $deals) {
            foreach (self::LEADS as $i => [$subject, $name, $email]) {
                $lead = $leads->create($tenant, $actorId, [
                    'subject' => $subject, 'name' => $name, 'email' => $email, 'phone' => '98' . str_pad((string) (10000000 + $i * 137), 8, '0'), 'pipeline_id' => $pipeline->id,
                    'lead_stage_id' => $leadStages[$i % count($leadStages)], 'follow_up_date' => now()->addDays($i - 3)->toDateString(),
                ], [$staff[$i % count($staff)]]);

                $lead->created_at = now()->subDays($i * 12)->subHours($i);
                $lead->save();
                if ($sources) {
                    $lead->sources()->sync([$sources[$i % count($sources)]]);
                }
                if ($labels) {
                    $lead->labels()->sync([$labels[$i % count($labels)]]);
                }
                if ($i % 3 === 0) {
                    $lead->tasks()->create(['name' => 'Call back', 'due_date' => now()->addDays($i - 2)->toDateString(), 'priority' => 'medium', 'status' => 'on_going', 'creator_id' => $actorId, 'created_by' => $tenant]);
                }
            }

            foreach (self::DEALS as $i => [$name, $price, $status, $monthsAgo]) {
                $deal = $deals->create($tenant, $actorId, ['name' => $name, 'price' => $price, 'pipeline_id' => $pipeline->id, 'deal_stage_id' => $dealStages[$i % count($dealStages)]], [$staff[$i % count($staff)]], []);

                if ($status !== 'active') {
                    $deals->setStatus($deal, $status, $actorId);
                    $deal->refresh();
                    $deal->closed_at = now()->subMonths($monthsAgo)->subDays($i);
                    $deal->save();
                }
            }

            return ['leads' => count(self::LEADS), 'deals' => count(self::DEALS)];
        });
    }
}
