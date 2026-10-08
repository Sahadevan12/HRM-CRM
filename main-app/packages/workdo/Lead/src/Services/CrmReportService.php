<?php

namespace Workdo\Lead\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;

/**
 * Numbers for the CRM dashboard and reports. Everything is limited to the company and to what the user may see
 * (the owner / `view-all-*` see all, other staff only the leads and deals they created or work on).
 *
 * Leads are counted by the day they were created, deals by the day they were won / lost (`closed_at`); the open pipeline
 * (active deals per stage) is always a snapshot of today.
 */
class CrmReportService
{
    /** @return Builder<Lead> */
    private function leads(User $user, ?int $pipelineId = null): Builder
    {
        return Lead::visibleTo($user)->where('leads.created_by', creatorId())->when($pipelineId, fn ($q) => $q->where('leads.pipeline_id', $pipelineId));
    }

    /** @return Builder<Deal> */
    private function deals(User $user, ?int $pipelineId = null): Builder
    {
        return Deal::visibleTo($user)->where('deals.created_by', creatorId())->when($pipelineId, fn ($q) => $q->where('deals.pipeline_id', $pipelineId));
    }

    private function bounds(string $from, string $to): array
    {
        return [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
    }

    /** @return array<int, string> every month (YYYY-MM) touched by the range */
    private function months(string $from, string $to): array
    {
        $months = [];
        for ($m = Carbon::parse($from)->startOfMonth(); $m->lte(Carbon::parse($to)); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        return $months;
    }

    /**
     * @return array{totals: array<string, mixed>, byStage: Collection, bySource: Collection, byUser: Collection, byMonth: array<int, array<string, mixed>>}
     */
    public function leadReport(User $user, int $pipelineId, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $base = fn () => $this->leads($user, $pipelineId)->whereBetween('leads.created_at', [$start, $end]);

        $total = $base()->count();
        $converted = $base()->where('is_converted', true)->count();

        $perStage = $base()->selectRaw('lead_stage_id, COUNT(*) as c')->groupBy('lead_stage_id')->pluck('c', 'lead_stage_id');
        $byStage = LeadStage::where('pipeline_id', $pipelineId)->orderBy('order')->get(['id', 'name'])->map(fn ($s) => ['name' => $s->name, 'value' => (int) ($perStage[$s->id] ?? 0)]);

        $bySource = $base()->join('lead_sources', 'lead_sources.lead_id', '=', 'leads.id')->join('sources', 'sources.id', '=', 'lead_sources.source_id')
            ->selectRaw('sources.name as name, COUNT(*) as value')->groupBy('sources.name')->orderByDesc('value')->get()
            ->map(fn ($r) => ['name' => $r->name, 'value' => (int) $r->value]);
        $withoutSource = $base()->whereDoesntHave('sources')->count();
        if ($withoutSource) {
            $bySource->push(['name' => __('No source'), 'value' => $withoutSource]);
        }

        $byUser = $base()->join('user_leads', 'user_leads.lead_id', '=', 'leads.id')->join('users', 'users.id', '=', 'user_leads.user_id')
            ->selectRaw('users.name as name, COUNT(*) as value')->groupBy('users.id', 'users.name')->orderByDesc('value')->get()
            ->map(fn ($r) => ['name' => $r->name, 'value' => (int) $r->value]);
        $unassigned = $base()->whereDoesntHave('users')->count();
        if ($unassigned) {
            $byUser->push(['name' => __('Unassigned'), 'value' => $unassigned]);
        }

        $perMonth = $base()->pluck('leads.created_at')->groupBy(fn ($d) => Carbon::parse($d)->format('Y-m'))->map->count();
        $byMonth = array_map(fn ($m) => ['month' => $m, 'leads' => (int) ($perMonth[$m] ?? 0)], $this->months($from, $to));

        return [
            'totals' => ['leads' => $total, 'converted' => $converted, 'conversion_rate' => $total ? round($converted / $total * 100, 1) : 0.0],
            'byStage' => $byStage, 'bySource' => $bySource->values(), 'byUser' => $byUser->values(), 'byMonth' => $byMonth,
        ];
    }

    /**
     * @return array{totals: array<string, mixed>, byStage: Collection, byUser: Collection, byMonth: array<int, array<string, mixed>>}
     */
    public function dealReport(User $user, int $pipelineId, string $from, string $to): array
    {
        [$start, $end] = $this->bounds($from, $to);
        $closed = fn (string $status) => $this->deals($user, $pipelineId)->where('deals.status', $status)->whereBetween('deals.closed_at', [$start, $end]);
        $open = fn () => $this->deals($user, $pipelineId)->where('deals.status', 'active');

        $won = $closed('won');
        $lost = $closed('lost');
        $wonCount = $won->count();
        $lostCount = $lost->count();

        $perStage = $open()->selectRaw('deal_stage_id, COUNT(*) as c, COALESCE(SUM(price), 0) as v')->groupBy('deal_stage_id')->get()->keyBy('deal_stage_id');
        $byStage = DealStage::where('pipeline_id', $pipelineId)->orderBy('order')->get(['id', 'name'])
            ->map(fn ($s) => ['name' => $s->name, 'count' => (int) ($perStage[$s->id]->c ?? 0), 'value' => round((float) ($perStage[$s->id]->v ?? 0), 2)]);

        $byUser = $closed('won')->join('user_deals', 'user_deals.deal_id', '=', 'deals.id')->join('users', 'users.id', '=', 'user_deals.user_id')
            ->selectRaw('users.name as name, COUNT(*) as count, COALESCE(SUM(deals.price), 0) as value')->groupBy('users.id', 'users.name')->orderByDesc('value')->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->count, 'value' => round((float) $r->value, 2)]);

        $perMonth = fn (string $status) => $closed($status)->get(['deals.closed_at', 'deals.price'])->groupBy(fn ($d) => $d->closed_at->format('Y-m'))->map(fn ($g) => round((float) $g->sum('price'), 2));
        $wonMonths = $perMonth('won');
        $lostMonths = $perMonth('lost');
        $byMonth = array_map(fn ($m) => ['month' => $m, 'won' => (float) ($wonMonths[$m] ?? 0), 'lost' => (float) ($lostMonths[$m] ?? 0)], $this->months($from, $to));

        return [
            'totals' => [
                'won_count' => $wonCount, 'won_value' => round((float) $won->sum('deals.price'), 2),
                'lost_count' => $lostCount, 'lost_value' => round((float) $lost->sum('deals.price'), 2),
                'open_count' => $open()->count(), 'open_value' => round((float) $open()->sum('deals.price'), 2),
                'win_rate' => ($wonCount + $lostCount) ? round($wonCount / ($wonCount + $lostCount) * 100, 1) : 0.0,
            ],
            'byStage' => $byStage, 'byUser' => $byUser->values(), 'byMonth' => $byMonth,
        ];
    }

    /**
     * The dashboard: headline numbers of the current month plus the default pipeline.
     *
     * @return array<string, mixed>
     */
    public function dashboard(User $user, Pipeline $pipeline): array
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $leads = $this->leadReport($user, $pipeline->id, now()->subMonths(5)->startOfMonth()->toDateString(), $to);
        $month = $this->leadReport($user, $pipeline->id, $from, $to);
        $deals = $this->dealReport($user, $pipeline->id, $from, $to);

        $allLeads = $this->leads($user);
        $myTasks = $this->openTasks($user);

        return [
            'kpis' => [
                'leads_total' => (clone $allLeads)->count(),
                'leads_active' => (clone $allLeads)->where('is_active', true)->where('is_converted', false)->count(),
                'leads_new_month' => $month['totals']['leads'],
                'leads_converted_month' => $month['totals']['converted'],
                'open_deals' => $deals['totals']['open_count'],
                'open_value' => $deals['totals']['open_value'],
                'won_month_value' => $deals['totals']['won_value'],
                'won_month_count' => $deals['totals']['won_count'],
                'lost_month_value' => $deals['totals']['lost_value'],
                'win_rate' => $deals['totals']['win_rate'],
                'tasks_due' => $myTasks['due'],
                'tasks_overdue' => $myTasks['overdue'],
            ],
            // the leads of the pipeline per stage, whenever they were created
            'leadsByStage' => $this->leadReport($user, $pipeline->id, '2000-01-01', $to)['byStage'],
            'dealsByStage' => $deals['byStage'],
            'leadsByMonth' => $leads['byMonth'],
            'bySource' => $leads['bySource'],
            'activity' => $this->recentActivity($user),
        ];
    }

    /** Tasks that are still open and due today / overdue, on the leads and deals the user can see. */
    private function openTasks(User $user): array
    {
        $today = now()->toDateString();
        $leadTasks = fn () => \Illuminate\Support\Facades\DB::table('lead_tasks')->where('status', 'on_going')->whereIn('lead_id', $this->leads($user)->select('leads.id'));
        $dealTasks = fn () => \Illuminate\Support\Facades\DB::table('deal_tasks')->where('status', 'on_going')->whereIn('deal_id', $this->deals($user)->select('deals.id'));

        return [
            'due' => $leadTasks()->whereDate('due_date', $today)->count() + $dealTasks()->whereDate('due_date', $today)->count(),
            'overdue' => $leadTasks()->whereDate('due_date', '<', $today)->count() + $dealTasks()->whereDate('due_date', '<', $today)->count(),
        ];
    }

    /** @return array<int, array{kind: string, id: int, title: string, remark: string, who: ?string, at: string}> */
    private function recentActivity(User $user): array
    {
        $leadLogs = \Workdo\Lead\Models\LeadActivityLog::with(['user:id,name', 'lead:id,subject'])->whereIn('lead_id', $this->leads($user)->select('leads.id'))->latest('id')->limit(8)->get()
            ->map(fn ($l) => ['kind' => 'lead', 'id' => $l->lead_id, 'title' => $l->lead->subject, 'remark' => $l->remark, 'who' => $l->user?->name, 'at' => $l->created_at->toIso8601String()]);
        $dealLogs = \Workdo\Lead\Models\DealActivityLog::with(['user:id,name', 'deal:id,name'])->whereIn('deal_id', $this->deals($user)->select('deals.id'))->latest('id')->limit(8)->get()
            ->map(fn ($l) => ['kind' => 'deal', 'id' => $l->deal_id, 'title' => $l->deal->name, 'remark' => $l->remark, 'who' => $l->user?->name, 'at' => $l->created_at->toIso8601String()]);

        return $leadLogs->concat($dealLogs)->sortByDesc('at')->take(8)->values()->all();
    }
}
