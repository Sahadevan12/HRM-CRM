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
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Label;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Models\Source;
use Workdo\Lead\Services\DealService;
use Workdo\Lead\Services\DefaultData;
use Workdo\Lead\Services\LeadService;

class DealController extends Controller
{
    public function __construct(private DealService $deals, private LeadService $leads, private DefaultData $defaults)
    {
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();
        if (!$user->can('manage-deals')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $this->defaults->ensure($tenant);

        $pipelines = Pipeline::where('created_by', $tenant)->orderBy('id')->get(['id', 'name']);
        $pipeline = $this->pipeline($request, $user, $pipelines);
        $view = $request->get('view') === 'list' ? 'list' : 'kanban';

        $stages = DealStage::where('pipeline_id', $pipeline->id)->orderBy('order')->get(['id', 'name', 'order']);
        $base = fn () => Deal::with(['users:id,name', 'clients:id,name'])->visibleTo($user)->where('deals.created_by', $tenant)->where('pipeline_id', $pipeline->id);

        $props = [
            'pipelines' => $pipelines,
            'pipeline' => $pipeline,
            'stages' => $stages,
            'view' => $view,
            'users' => User::whereIn('id', $this->leads->assignableUserIds($tenant))->orderBy('name')->get(['id', 'name']),
            'clients' => User::whereIn('id', $this->deals->clientIds($tenant))->orderBy('name')->get(['id', 'name']),
            'sourceOptions' => Source::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'labelOptions' => Label::where('created_by', $tenant)->where('pipeline_id', $pipeline->id)->orderBy('name')->get(['id', 'name', 'color']),
            'productOptions' => \Workdo\ProductService\Models\Product::where('created_by', $tenant)->orderBy('name')->limit(500)->get(['id', 'name', 'sku']),
            'filters' => $request->only(['search', 'stage', 'status']),
            'can' => ['create' => $user->can('create-deals'), 'edit' => $user->can('edit-deals'), 'delete' => $user->can('delete-deals'), 'move' => $user->can('move-deals'), 'status' => $user->can('change-deal-status')],
            'can_detail' => ['task' => $user->can('manage-deal-tasks'), 'call' => $user->can('manage-deal-calls'), 'email' => $user->can('manage-deal-emails'), 'discussion' => $user->can('manage-deal-discussions'), 'file' => $user->can('manage-deal-files')],
        ];

        if ($view === 'list') {
            $props['deals'] = $base()->with('stage:id,name')
                ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . $request->get('search') . '%'))
                ->when($request->filled('stage'), fn ($q) => $q->where('deal_stage_id', $request->get('stage')))
                ->when(in_array($request->get('status'), Deal::STATUSES, true), fn ($q) => $q->where('status', $request->get('status')))
                ->latest('id')->paginate((int) $request->get('per_page', 15))->withQueryString();
        } else {
            $props['deals'] = $base()->orderBy('order')->orderBy('id')->get();
        }

        return Inertia::render('Lead/Deals/Index', $props);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-deals')) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate($this->rules($tenant) + [
            'pipeline_id' => ['required', Rule::exists('pipelines', 'id')->where('created_by', $tenant)],
            'deal_stage_id' => ['nullable', Rule::exists('deal_stages', 'id')->where('created_by', $tenant)],
        ]);

        try {
            $this->deals->create($tenant, Auth::id(), collect($data)->except(['user_ids', 'client_ids'])->all(), $data['user_ids'] ?? [], $data['client_ids'] ?? []);
        } catch (LeadException $e) {
            return back()->withErrors(['deal_stage_id' => $e->getMessage()]);
        }

        return back()->with('success', __('The deal has been created.'));
    }

    public function update(Request $request, Deal $deal): RedirectResponse
    {
        if (!Auth::user()->can('edit-deals') || !$this->canSee($deal)) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate($this->rules(creatorId()) + ['is_active' => 'boolean']);

        $this->deals->update($deal, Auth::id(), collect($data)->except(['user_ids', 'client_ids'])->all(), $data['user_ids'] ?? [], $data['client_ids'] ?? []);

        return back()->with('success', __('The deal has been updated.'));
    }

    public function destroy(Deal $deal): RedirectResponse
    {
        if (!Auth::user()->can('delete-deals') || !$this->canSee($deal)) {
            return back()->with('error', __('Permission denied'));
        }

        // the files of the deal are on disk: remove them with it
        \Illuminate\Support\Facades\Storage::disk('local')->delete($deal->files()->get()->map(fn ($f) => $f->makeVisible('file_path')->file_path)->all());
        $deal->lead?->update(['is_converted' => false]); // the lead can be converted again
        $deal->delete();

        return back()->with('success', __('The deal has been deleted.'));
    }

    /** Drag and drop on the board. */
    public function move(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('move-deals')) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate([
            'deal_id' => 'required|integer',
            'deal_stage_id' => 'required|integer',
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|distinct',
        ]);

        $deal = Deal::where('created_by', creatorId())->findOrFail($data['deal_id']);
        if (!$this->canSee($deal)) {
            return back()->with('error', __('Permission denied'));
        }

        try {
            $this->deals->move($deal, (int) $data['deal_stage_id'], array_map('intval', $data['ids']), Auth::id());
        } catch (LeadException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('The deal has been moved.'));
    }

    /** Won / Lost / reopen. */
    public function status(Request $request, Deal $deal): RedirectResponse
    {
        if (!Auth::user()->can('change-deal-status') || !$this->canSee($deal)) {
            return back()->with('error', __('Permission denied'));
        }

        $status = $request->validate(['status' => ['required', Rule::in(Deal::STATUSES)]])['status'];

        try {
            $this->deals->setStatus($deal, $status, Auth::id());
        } catch (LeadException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('The deal status has been updated.'));
    }

    // ───────────── helpers ─────────────

    private function canSee(Deal $deal): bool
    {
        return $deal->created_by === creatorId() && Deal::whereKey($deal->id)->visibleTo(Auth::user())->exists();
    }

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
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0|max:9999999999',
            'phone' => 'nullable|string|max:50',
            'notes' => 'nullable|string|max:5000',
            'user_ids' => 'array|max:50',
            'user_ids.*' => ['integer', 'distinct', Rule::in($this->leads->assignableUserIds($tenant)->all())],
            'client_ids' => 'array|max:50',
            'client_ids.*' => ['integer', 'distinct', Rule::in($this->deals->clientIds($tenant)->all())],
        ];
    }
}
