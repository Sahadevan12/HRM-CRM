<?php

namespace Workdo\Lead\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Workdo\Lead\Exceptions\LeadException;
use Workdo\Lead\Models\CrmPreference;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Services\CrmReportService;
use Workdo\Lead\Services\DealService;
use Workdo\Lead\Services\LeadService;

/**
 * Mobile API of the CRM: lists, create / move leads, deal status, dashboard. The detail of a lead or deal (and its tasks, calls, e-mails,
 * comments and files) is served by the same JSON controllers as the web drawer. Same permissions, same "only what I may see" rule.
 */
class CrmApiController extends Controller
{
    public function __construct(private LeadService $leads, private DealService $deals, private CrmReportService $reports)
    {
    }

    /** Pipelines with their lead and deal stages, in order. */
    public function pipelines(): JsonResponse
    {
        if ($denied = $this->need('manage-leads', 'manage-deals')) {
            return $denied;
        }

        $pipelines = Pipeline::where('created_by', creatorId())->orderBy('id')->get(['id', 'name']);
        $stages = fn (string $model) => $model::whereIn('pipeline_id', $pipelines->pluck('id'))->orderBy('order')->get(['id', 'name', 'order', 'pipeline_id'])->groupBy('pipeline_id');
        $lead = $stages(LeadStage::class);
        $deal = $stages(DealStage::class);

        return response()->json(['data' => $pipelines->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'lead_stages' => $lead[$p->id] ?? [], 'deal_stages' => $deal[$p->id] ?? [],
        ])->values()]);
    }

    public function leads(Request $request): JsonResponse
    {
        if ($denied = $this->need('manage-leads')) {
            return $denied;
        }

        $request->validate(['per_page' => 'nullable|integer|min:1|max:50', 'pipeline' => 'nullable|integer', 'stage' => 'nullable|integer', 'search' => 'nullable|string|max:100']);

        $page = Lead::with(['users:id,name', 'stage:id,name'])->visibleTo(Auth::user())->where('leads.created_by', creatorId())
            ->when($request->filled('pipeline'), fn ($q) => $q->where('pipeline_id', $request->get('pipeline')))
            ->when($request->filled('stage'), fn ($q) => $q->where('lead_stage_id', $request->get('stage')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('subject', 'like', '%' . $request->get('search') . '%')
                ->orWhere('name', 'like', '%' . $request->get('search') . '%')->orWhere('email', 'like', '%' . $request->get('search') . '%')))
            ->latest('id')->paginate((int) $request->get('per_page', 20));

        return response()->json($page);
    }

    public function storeLead(Request $request): JsonResponse
    {
        if ($denied = $this->need('create-leads')) {
            return $denied;
        }

        $tenant = creatorId();
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:5000',
            'follow_up_date' => 'nullable|date',
            'pipeline_id' => ['required', Rule::exists('pipelines', 'id')->where('created_by', $tenant)],
            'lead_stage_id' => ['nullable', Rule::exists('lead_stages', 'id')->where('created_by', $tenant)],
            'user_ids' => 'array|max:50',
            'user_ids.*' => ['integer', 'distinct', Rule::in($this->leads->assignableUserIds($tenant)->all())],
        ]);

        try {
            $lead = $this->leads->create($tenant, Auth::id(), collect($data)->except('user_ids')->all(), $data['user_ids'] ?? []);
        } catch (LeadException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['lead_stage_id' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => $lead->load('users:id,name', 'stage:id,name')], 201);
    }

    /** Move a lead to another stage of its pipeline (it lands at the bottom of that column). */
    public function moveLead(Request $request, Lead $lead): JsonResponse
    {
        if ($denied = $this->need('move-leads') ?? $this->seeable($lead)) {
            return $denied;
        }

        $stage = $request->validate(['lead_stage_id' => 'required|integer'])['lead_stage_id'];
        $column = Lead::where('created_by', creatorId())->where('lead_stage_id', $stage)->where('id', '!=', $lead->id)->orderBy('order')->pluck('id')->all();

        try {
            $moved = $this->leads->move($lead, (int) $stage, [...$column, $lead->id], Auth::id());
        } catch (LeadException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $moved->load('users:id,name', 'stage:id,name')]);
    }

    public function deals(Request $request): JsonResponse
    {
        if ($denied = $this->need('manage-deals')) {
            return $denied;
        }

        $request->validate(['per_page' => 'nullable|integer|min:1|max:50', 'pipeline' => 'nullable|integer', 'status' => ['nullable', Rule::in(Deal::STATUSES)], 'search' => 'nullable|string|max:100']);

        $page = Deal::with(['users:id,name', 'clients:id,name', 'stage:id,name'])->visibleTo(Auth::user())->where('deals.created_by', creatorId())
            ->when($request->filled('pipeline'), fn ($q) => $q->where('pipeline_id', $request->get('pipeline')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->get('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . $request->get('search') . '%'))
            ->latest('id')->paginate((int) $request->get('per_page', 20));

        return response()->json($page);
    }

    /** Won / lost / active again. */
    public function dealStatus(Request $request, Deal $deal): JsonResponse
    {
        if ($denied = $this->need('change-deal-status')) {
            return $denied;
        }
        if ($deal->created_by !== creatorId() || !Deal::whereKey($deal->id)->visibleTo(Auth::user())->exists()) {
            return response()->json(['message' => __('Permission denied')], 403);
        }

        $status = $request->validate(['status' => ['required', Rule::in(Deal::STATUSES)]])['status'];

        try {
            $this->deals->setStatus($deal, $status, Auth::id());
        } catch (LeadException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $deal->refresh()->load('stage:id,name')]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        if ($denied = $this->need('view-crm-dashboard')) {
            return $denied;
        }

        $pipelines = Pipeline::where('created_by', creatorId())->orderBy('id')->get();
        $saved = CrmPreference::where('user_id', Auth::id())->value('default_pipeline_id');
        $pipeline = ($request->filled('pipeline') ? $pipelines->firstWhere('id', (int) $request->get('pipeline')) : null) ?? $pipelines->firstWhere('id', $saved) ?? $pipelines->first();

        if (!$pipeline) {
            return response()->json(['message' => __('There is no pipeline yet. Open CRM Setup on the website once.')], 404);
        }

        return response()->json(['pipeline' => ['id' => $pipeline->id, 'name' => $pipeline->name]] + $this->reports->dashboard(Auth::user(), $pipeline));
    }

    // ───────────── helpers ─────────────

    /** 403 unless the user holds at least one of the permissions. */
    private function need(string ...$permissions): ?JsonResponse
    {
        foreach ($permissions as $permission) {
            if (Auth::user()->can($permission)) {
                return null;
            }
        }

        return response()->json(['message' => __('Permission denied')], 403);
    }

    private function seeable(Lead $lead): ?JsonResponse
    {
        $ok = $lead->created_by === creatorId() && Lead::whereKey($lead->id)->visibleTo(Auth::user())->exists();

        return $ok ? null : response()->json(['message' => __('Permission denied')], 403);
    }
}
