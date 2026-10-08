<?php

namespace Workdo\Lead\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Lead\Models\CrmPreference;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Services\CrmReportService;
use Workdo\Lead\Services\DefaultData;

/** The CRM dashboard (this month at a glance) and the reports page (any period). */
class CrmDashboardController extends Controller
{
    public function __construct(private CrmReportService $reports, private DefaultData $defaults)
    {
    }

    public function dashboard(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('view-crm-dashboard')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        [$pipelines, $pipeline] = $this->pipelines($request);

        return Inertia::render('Lead/Dashboard/Index', ['pipelines' => $pipelines, 'pipeline' => $pipeline] + $this->reports->dashboard(Auth::user(), $pipeline));
    }

    public function reports(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('view-crm-reports')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        [$pipelines, $pipeline] = $this->pipelines($request);
        $from = $this->date($request->get('from'), now()->startOfYear()->toDateString());
        $to = $this->date($request->get('to'), now()->toDateString());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // a period of years would produce hundreds of month rows: keep it to 5 years
        if (now()->parse($from)->diffInMonths(now()->parse($to)) > 60) {
            $from = now()->parse($to)->subMonths(60)->toDateString();
        }

        return Inertia::render('Lead/Reports/Index', [
            'pipelines' => $pipelines,
            'pipeline' => $pipeline,
            'from' => $from,
            'to' => $to,
            'leads' => $this->reports->leadReport(Auth::user(), $pipeline->id, $from, $to),
            'deals' => $this->reports->dealReport(Auth::user(), $pipeline->id, $from, $to),
        ]);
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: Pipeline} */
    private function pipelines(Request $request): array
    {
        $tenant = creatorId();
        $this->defaults->ensure($tenant);

        $pipelines = Pipeline::where('created_by', $tenant)->orderBy('id')->get(['id', 'name']);
        $chosen = $request->filled('pipeline') ? $pipelines->firstWhere('id', (int) $request->get('pipeline')) : null;

        if ($chosen) {
            CrmPreference::updateOrCreate(['user_id' => Auth::id()], ['default_pipeline_id' => $chosen->id]);
        }

        $saved = CrmPreference::where('user_id', Auth::id())->value('default_pipeline_id');

        return [$pipelines, $chosen ?? $pipelines->firstWhere('id', $saved) ?? $pipelines->first()];
    }

    private function date(?string $value, string $fallback): string
    {
        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : $fallback;
    }
}
