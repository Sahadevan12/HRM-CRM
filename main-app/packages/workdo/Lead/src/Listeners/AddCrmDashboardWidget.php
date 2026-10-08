<?php

namespace Workdo\Lead\Listeners;

use App\Events\CollectDashboardWidgets;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\Lead;

/** The CRM card of the company dashboard (only for users who may open the CRM dashboard; they see what they may see). */
class AddCrmDashboardWidget
{
    public function handle(CollectDashboardWidgets $event): void
    {
        $tenant = creatorId();

        if (!Module_is_active('Lead', $tenant) || !$event->user->can('view-crm-dashboard')) {
            return;
        }

        $leads = fn () => Lead::visibleTo($event->user)->where('leads.created_by', $tenant);
        $deals = fn () => Deal::visibleTo($event->user)->where('deals.created_by', $tenant);
        $open = $deals()->where('deals.status', 'active');

        $event->add([
            'key' => 'crm', 'title' => 'CRM', 'href' => route('crm.dashboard'), 'order' => 30,
            'stats' => [
                ['label' => 'Open leads', 'value' => $leads()->where('is_active', true)->where('is_converted', false)->count(), 'format' => 'number'],
                ['label' => 'Open deals', 'value' => (clone $open)->count(), 'format' => 'number', 'hint' => null],
                ['label' => 'Open pipeline value', 'value' => round((float) (clone $open)->sum('deals.price'), 2), 'format' => 'money'],
                ['label' => 'Won this month', 'value' => round((float) $deals()->where('deals.status', 'won')->where('deals.closed_at', '>=', now()->startOfMonth())->sum('deals.price'), 2), 'format' => 'money'],
            ],
        ]);
    }
}
