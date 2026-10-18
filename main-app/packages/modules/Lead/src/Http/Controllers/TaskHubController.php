<?php

namespace Modules\Lead\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Lead\Models\Deal;
use Modules\Lead\Models\DealTask;
use Modules\Lead\Models\Lead;
use Modules\Lead\Models\LeadTask;

/** One list of every open follow-up task on the leads and deals the user may see: overdue, today, upcoming (a task always has a due date). */
class TaskHubController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();
        $tenant = creatorId();
        $canLead = $user->can('manage-lead-tasks');
        $canDeal = $user->can('manage-deal-tasks');

        if (!$canLead && !$canDeal) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $rows = collect();

        if ($canLead) {
            LeadTask::with('lead:id,subject,name')
                ->where('status', '!=', 'completed')
                ->whereHas('lead', fn ($q) => $q->visibleTo($user)->where('leads.created_by', $tenant))
                ->orderBy('due_date')->limit(300)->get()
                ->each(fn (LeadTask $t) => $rows->push($this->row('lead', $t, $t->lead->id, $t->lead->subject ?: $t->lead->name)));
        }

        if ($canDeal) {
            DealTask::with('deal:id,name')
                ->where('status', '!=', 'completed')
                ->whereHas('deal', fn ($q) => $q->visibleTo($user)->where('deals.created_by', $tenant))
                ->orderBy('due_date')->limit(300)->get()
                ->each(fn (DealTask $t) => $rows->push($this->row('deal', $t, $t->deal->id, $t->deal->name)));
        }

        $today = now()->toDateString();
        $sorted = $rows->sortBy(fn ($r) => ($r['due_date'] ?? '9999-12-31').($r['due_time'] ?? '99:99'))->values();

        return Inertia::render('Lead/Tasks/Index', [
            'today' => $today,
            'overdue' => $sorted->filter(fn ($r) => $r['due_date'] && $r['due_date'] < $today)->values(),
            'dueToday' => $sorted->filter(fn ($r) => $r['due_date'] === $today)->values(),
            'upcoming' => $sorted->filter(fn ($r) => $r['due_date'] && $r['due_date'] > $today)->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(string $kind, LeadTask|DealTask $task, int $parentId, ?string $title): array
    {
        return [
            'key' => "{$kind}-{$task->id}", 'kind' => $kind, 'id' => $task->id, 'parent_id' => $parentId, 'title' => $title,
            'name' => $task->name, 'priority' => $task->priority, 'due_date' => $task->due_date?->toDateString(), 'due_time' => $task->due_time,
            'toggle' => route($kind === 'lead' ? 'crm.leads.tasks.toggle' : 'crm.deals.tasks.toggle', [$kind => $parentId, 'task' => $task->id]),
        ];
    }
}
