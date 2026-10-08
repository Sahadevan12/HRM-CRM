<?php

namespace Workdo\Lead\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Lead\Exceptions\LeadException;
use Workdo\Lead\Models\CrmPreference;
use Workdo\Lead\Models\Label;
use Workdo\Lead\Models\Source;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Services\DefaultData;
use Workdo\Lead\Services\LeadConversion;
use Workdo\Lead\Services\LeadService;

class LeadController extends Controller
{
    public function __construct(private LeadService $leads, private DefaultData $defaults, private LeadConversion $conversion)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();
        if (!$user->can('manage-leads')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $this->defaults->ensure($tenant);

        $pipelines = Pipeline::where('created_by', $tenant)->orderBy('id')->get(['id', 'name']);
        $pipeline = $this->pipeline($request, $user, $pipelines);
        $view = $request->get('view') === 'list' ? 'list' : 'kanban';

        $stages = LeadStage::where('pipeline_id', $pipeline->id)->orderBy('order')->get(['id', 'name', 'order']);
        $base = fn () => Lead::with('users:id,name')->visibleTo($user)->where('leads.created_by', $tenant)->where('pipeline_id', $pipeline->id);

        $props = [
            'pipelines' => $pipelines,
            'pipeline' => $pipeline,
            'stages' => $stages,
            'view' => $view,
            'users' => User::whereIn('id', $this->leads->assignableUserIds($tenant))->orderBy('name')->get(['id', 'name']),
            'sourceOptions' => Source::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'labelOptions' => Label::where('created_by', $tenant)->where('pipeline_id', $pipeline->id)->orderBy('name')->get(['id', 'name', 'color']),
            'productOptions' => \Workdo\ProductService\Models\Product::where('created_by', $tenant)->orderBy('name')->limit(500)->get(['id', 'name', 'sku']),
            'clients' => User::whereIn('id', app(\Workdo\Lead\Services\DealService::class)->clientIds($tenant))->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['search', 'stage']),
            'can_detail' => ['task' => $user->can('manage-lead-tasks'), 'call' => $user->can('manage-lead-calls'), 'email' => $user->can('manage-lead-emails'), 'discussion' => $user->can('manage-lead-discussions'), 'file' => $user->can('manage-lead-files')],
            'can' => ['create' => $user->can('create-leads'), 'edit' => $user->can('edit-leads'), 'delete' => $user->can('delete-leads'), 'move' => $user->can('move-leads'), 'convert' => $user->can('convert-leads')],
        ];

        if ($view === 'list') {
            $props['leads'] = $base()->with('stage:id,name')
                ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('subject', 'like', '%' . $request->get('search') . '%')
                    ->orWhere('name', 'like', '%' . $request->get('search') . '%')->orWhere('email', 'like', '%' . $request->get('search') . '%')))
                ->when($request->filled('stage'), fn ($q) => $q->where('lead_stage_id', $request->get('stage')))
                ->latest('id')->paginate((int) $request->get('per_page', 15))->withQueryString();
        } else {
            $props['leads'] = $base()->orderBy('order')->orderBy('id')->get();
        }

        return Inertia::render('Lead/Leads/Index', $props);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-leads')) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate($this->rules($tenant) + [
            'pipeline_id' => ['required', Rule::exists('pipelines', 'id')->where('created_by', $tenant)],
            'lead_stage_id' => ['nullable', Rule::exists('lead_stages', 'id')->where('created_by', $tenant)],
        ]);

        try {
            $this->leads->create($tenant, Auth::id(), collect($data)->except('user_ids')->all(), $data['user_ids'] ?? []);
        } catch (LeadException $e) {
            return back()->withErrors(['lead_stage_id' => $e->getMessage()]);
        }

        return back()->with('success', __('The lead has been created.'));
    }

    public function update(Request $request, Lead $lead): RedirectResponse
    {
        if (!Auth::user()->can('edit-leads') || !$this->canSee($lead)) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate($this->rules(creatorId()) + ['is_active' => 'boolean']);

        $this->leads->update($lead, Auth::id(), collect($data)->except('user_ids')->all(), $data['user_ids'] ?? []);

        return back()->with('success', __('The lead has been updated.'));
    }

    public function destroy(Lead $lead): RedirectResponse
    {
        if (!Auth::user()->can('delete-leads') || !$this->canSee($lead)) {
            return back()->with('error', __('Permission denied'));
        }

        $lead->delete();

        return back()->with('success', __('The lead has been deleted.'));
    }

    /** Lead -> deal (client, price and what to copy are chosen in the dialog). */
    public function convert(Request $request, Lead $lead): RedirectResponse
    {
        if (!Auth::user()->can('convert-leads') || !$this->canSee($lead)) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate([
            'price' => 'required|numeric|min:0|max:9999999999',
            'pipeline_id' => ['required', Rule::exists('pipelines', 'id')->where('created_by', $tenant)],
            'client_mode' => ['required', Rule::in(['none', 'existing', 'new'])],
            'client_id' => ['required_if:client_mode,existing', 'nullable', 'integer'],
            'client_name' => ['required_if:client_mode,new', 'nullable', 'string', 'max:255'],
            'client_email' => ['required_if:client_mode,new', 'nullable', 'email', 'max:255'],
            'copy' => 'array',
            'copy.*' => [Rule::in(LeadConversion::COPY)],
        ]);

        try {
            $this->conversion->convert($lead, $data, Auth::id());
        } catch (LeadException $e) {
            return back()->withErrors(['convert' => $e->getMessage()]);
        }

        return back()->with('success', __('The lead has been converted to a deal.'));
    }

    /** Drag and drop on the board. */
    public function move(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('move-leads')) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate([
            'lead_id' => 'required|integer',
            'lead_stage_id' => 'required|integer',
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|distinct',
        ]);

        $lead = Lead::where('created_by', creatorId())->findOrFail($data['lead_id']);
        if (!$this->canSee($lead)) {
            return back()->with('error', __('Permission denied'));
        }

        try {
            $this->leads->move($lead, (int) $data['lead_stage_id'], array_map('intval', $data['ids']), Auth::id());
        } catch (LeadException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('The lead has been moved.'));
    }

    // ───────────── helpers ─────────────

    private function canSee(Lead $lead): bool
    {
        return $lead->created_by === creatorId() && Lead::whereKey($lead->id)->visibleTo(Auth::user())->exists();
    }

    /** The pipeline asked for, else the one the user looked at last, else the first. An explicit choice is remembered. */
    private function pipeline(Request $request, User $user, $pipelines): Pipeline
    {
        $chosen = $request->filled('pipeline') ? $pipelines->firstWhere('id', (int) $request->get('pipeline')) : null;

        if ($chosen) {
            CrmPreference::updateOrCreate(['user_id' => $user->id], ['default_pipeline_id' => $chosen->id]);

            return $chosen;
        }

        $saved = CrmPreference::where('user_id', $user->id)->value('default_pipeline_id');

        return $pipelines->firstWhere('id', $saved) ?? $pipelines->first();
    }

    /** @return array<string, mixed> */
    private function rules(int $tenant): array
    {
        return [
            'subject' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:5000',
            'follow_up_date' => 'nullable|date',
            'user_ids' => 'array|max:50',
            'user_ids.*' => ['integer', 'distinct', Rule::in($this->leads->assignableUserIds($tenant)->all())],
        ];
    }
}
