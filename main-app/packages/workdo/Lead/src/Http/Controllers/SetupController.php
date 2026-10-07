<?php

namespace Workdo\Lead\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Lead\Models\DealStage;
use Workdo\Lead\Models\Label;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadStage;
use Workdo\Lead\Models\Pipeline;
use Workdo\Lead\Models\Source;
use Workdo\Lead\Services\DefaultData;

/**
 * CRM system setup: pipelines, lead stages, deal stages, labels and sources. One controller and one tabbed React page serve all five
 * (`kind` in the URL); stages can be re-ordered by drag and drop.
 */
class SetupController extends Controller
{
    private const KINDS = [
        'pipelines' => Pipeline::class,
        'lead-stages' => LeadStage::class,
        'deal-stages' => DealStage::class,
        'labels' => Label::class,
        'sources' => Source::class,
    ];

    private const STAGES = ['lead-stages', 'deal-stages'];

    public function __construct(private DefaultData $defaults)
    {
    }

    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();
        $visible = collect(array_keys(self::KINDS))->filter(fn ($k) => $user->can("manage-{$k}"))->values();

        if ($visible->isEmpty()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $this->defaults->ensure($tenant);

        $all = fn (string $model, array $cols) => $model::where('created_by', $tenant)->orderBy('id')->get($cols);

        return Inertia::render('Lead/SystemSetup/Index', [
            'pipelines' => $all(Pipeline::class, ['id', 'name']),
            'leadStages' => LeadStage::where('created_by', $tenant)->orderBy('pipeline_id')->orderBy('order')->get(['id', 'name', 'pipeline_id', 'order']),
            'dealStages' => DealStage::where('created_by', $tenant)->orderBy('pipeline_id')->orderBy('order')->get(['id', 'name', 'pipeline_id', 'order']),
            'labels' => $all(Label::class, ['id', 'name', 'color', 'pipeline_id']),
            'sources' => $all(Source::class, ['id', 'name']),
            'tabs' => $visible,
            'can' => collect(array_keys(self::KINDS))->mapWithKeys(fn ($k) => [$k => [
                'create' => $user->can("create-{$k}"), 'edit' => $user->can("edit-{$k}"), 'delete' => $user->can("delete-{$k}"),
            ]]),
        ]);
    }

    public function store(Request $request, string $kind): RedirectResponse
    {
        $model = $this->model($kind);
        if (!Auth::user()->can("create-{$kind}")) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate($this->rules($kind, $tenant));

        DB::transaction(function () use ($kind, $model, $data, $tenant) {
            if (in_array($kind, self::STAGES, true)) {
                $data['order'] = (int) $model::where('pipeline_id', $data['pipeline_id'])->max('order') + 1; // always at the end
            }

            $row = $model::create($data + ['creator_id' => Auth::id(), 'created_by' => $tenant]);

            if ($row instanceof Pipeline) {
                $this->defaults->stages($row); // a new pipeline starts with the standard stages
            }
        });

        return back()->with('success', __('Saved.'));
    }

    public function update(Request $request, string $kind, int $id): RedirectResponse
    {
        $model = $this->model($kind);
        $row = $model::where('created_by', creatorId())->findOrFail($id);

        if (!Auth::user()->can("edit-{$kind}")) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate($this->rules($kind, creatorId(), $row));
        unset($data['pipeline_id']); // a stage / label never moves to another pipeline

        $row->update($data);

        return back()->with('success', __('Saved.'));
    }

    public function destroy(string $kind, int $id): RedirectResponse
    {
        $model = $this->model($kind);
        $row = $model::where('created_by', creatorId())->findOrFail($id);

        if (!Auth::user()->can("delete-{$kind}")) {
            return back()->with('error', __('Permission denied'));
        }
        if ($row instanceof Pipeline && Pipeline::where('created_by', creatorId())->count() <= 1) {
            return back()->with('error', __('The last pipeline cannot be deleted.'));
        }
        if ($reason = $this->inUse($row)) {
            return back()->with('error', $reason);
        }

        $row->delete();

        if ($row instanceof LeadStage || $row instanceof DealStage) {
            $this->renumber($row::class, $row->pipeline_id);
        }

        return back()->with('success', __('Deleted.'));
    }

    /** Drag and drop: the full list of stage ids of one pipeline in its new order. */
    public function reorder(Request $request, string $kind): RedirectResponse
    {
        $model = $this->model($kind);
        abort_unless(in_array($kind, self::STAGES, true), 404);

        if (!Auth::user()->can("edit-{$kind}")) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $data = $request->validate([
            'pipeline_id' => ['required', Rule::exists('pipelines', 'id')->where('created_by', $tenant)],
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|distinct',
        ]);

        $existing = $model::where('created_by', $tenant)->where('pipeline_id', $data['pipeline_id'])->pluck('id')->all();
        $given = array_map('intval', $data['ids']);

        // the list must be exactly the stages of that pipeline: nothing missing, nothing foreign
        if (count($given) !== count($existing) || array_diff($given, $existing)) {
            return back()->with('error', __('The stage list is out of date. Reload the page.'));
        }

        DB::transaction(function () use ($model, $given) {
            foreach ($given as $i => $id) {
                $model::whereKey($id)->update(['order' => $i + 1]);
            }
        });

        return back()->with('success', __('The order has been saved.'));
    }

    // ───────────── helpers ─────────────

    /** @return class-string<Model> */
    private function model(string $kind): string
    {
        return self::KINDS[$kind] ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function rules(string $kind, int $tenant, ?Model $row = null): array
    {
        $unique = fn (string $table) => Rule::unique($table, 'name')->where('created_by', $tenant)->ignore($row?->getKey());
        $pipeline = ['required', Rule::exists('pipelines', 'id')->where('created_by', $tenant)];

        return match ($kind) {
            'pipelines' => ['name' => ['required', 'string', 'max:100', $unique('pipelines')]],
            'sources' => ['name' => ['required', 'string', 'max:100', $unique('sources')]],
            'lead-stages', 'deal-stages' => ['name' => 'required|string|max:100', 'pipeline_id' => $pipeline],
            'labels' => ['name' => 'required|string|max:50', 'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'pipeline_id' => $pipeline],
        };
    }

    /** A stage or pipeline that is still used cannot be deleted (deals / sources / labels are added with the later CRM parts). */
    private function inUse(Model $row): ?string
    {
        if ($row instanceof LeadStage && Lead::where('lead_stage_id', $row->id)->exists()) {
            return __('This stage still has leads. Move them to another stage first.');
        }
        if ($row instanceof Pipeline && Lead::where('pipeline_id', $row->id)->exists()) {
            return __('This pipeline still has leads and cannot be deleted.');
        }

        return null;
    }

    private function renumber(string $model, int $pipelineId): void
    {
        $model::where('pipeline_id', $pipelineId)->orderBy('order')->orderBy('id')->pluck('id')->each(fn ($id, $i) => $model::whereKey($id)->update(['order' => $i + 1]));
    }
}
