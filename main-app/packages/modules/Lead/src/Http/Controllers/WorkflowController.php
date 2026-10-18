<?php

namespace Modules\Lead\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Lead\Models\DealStage;
use Modules\Lead\Models\Label;
use Modules\Lead\Models\LeadStage;
use Modules\Lead\Models\Pipeline;
use Modules\Lead\Models\Source;
use Modules\Lead\Models\Workflow;
use Modules\Lead\Models\WorkflowRun;
use Modules\Lead\Services\DefaultData;
use Modules\Lead\Services\LeadService;
use Modules\Lead\Services\WorkflowEngine;

/** CRM workflow rules ("when a deal is won, create a task and e-mail the team"): one page lists, builds and switches them on and off. */
class WorkflowController extends Controller
{
    public function __construct(private DefaultData $defaults, private LeadService $leads)
    {
    }

    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();
        if (!$user->can('manage-pipelines')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $this->defaults->ensure($tenant);

        return Inertia::render('Lead/Workflows/Index', [
            'workflows' => Workflow::where('created_by', $tenant)->orderBy('id')->get(),
            'runs' => WorkflowRun::with('workflow:id,name')->where('created_by', $tenant)->latest('id')->limit(25)->get(),
            'pipelines' => Pipeline::where('created_by', $tenant)->orderBy('id')->get(['id', 'name']),
            'leadStages' => LeadStage::where('created_by', $tenant)->orderBy('pipeline_id')->orderBy('order')->get(['id', 'name', 'pipeline_id']),
            'dealStages' => DealStage::where('created_by', $tenant)->orderBy('pipeline_id')->orderBy('order')->get(['id', 'name', 'pipeline_id']),
            'sources' => Source::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'labels' => Label::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'users' => \App\Models\User::whereIn('id', $this->leads->assignableUserIds($tenant))->orderBy('name')->get(['id', 'name']),
            'triggers' => Workflow::TRIGGERS,
            'fields' => WorkflowEngine::FIELDS,
            'can' => ['edit' => $user->can('edit-pipelines')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $data = $this->validated($request);
        Workflow::create($data + ['creator_id' => Auth::id(), 'created_by' => creatorId()]);

        return back()->with('success', __('Workflow created.'));
    }

    public function update(Request $request, int $workflow): RedirectResponse
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $row = Workflow::where('created_by', creatorId())->findOrFail($workflow);
        $row->update($this->validated($request));

        return back()->with('success', __('Workflow updated.'));
    }

    public function toggle(int $workflow): RedirectResponse
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        $row = Workflow::where('created_by', creatorId())->findOrFail($workflow);
        $row->update(['is_active' => !$row->is_active]);

        return back()->with('success', $row->is_active ? __('Workflow switched on.') : __('Workflow switched off.'));
    }

    public function destroy(int $workflow): RedirectResponse
    {
        if ($denied = $this->deny()) {
            return $denied;
        }

        Workflow::where('created_by', creatorId())->findOrFail($workflow)->delete();

        return back()->with('success', __('Workflow deleted.'));
    }

    private function deny(): ?RedirectResponse
    {
        return Auth::user()->can('edit-pipelines') ? null : back()->with('error', __('Permission denied'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $tenant = creatorId();
        $module = $request->input('module');

        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:120'],
            'module' => ['required', Rule::in(array_keys(Workflow::TRIGGERS))],
            'trigger' => ['required', Rule::in(Workflow::TRIGGERS[$module] ?? [])],
            'match' => ['required', Rule::in(['all', 'any'])],
            'is_active' => ['boolean'],
            'conditions' => ['nullable', 'array', 'max:10'],
            'conditions.*.field' => ['required', Rule::in(array_keys(WorkflowEngine::FIELDS[$module] ?? []))],
            'conditions.*.operator' => ['required', Rule::in(WorkflowEngine::OPERATORS)],
            'conditions.*.value' => ['nullable', 'max:190'],
            'actions' => ['required', 'array', 'min:1', 'max:10'],
            'actions.*.type' => ['required', Rule::in(WorkflowEngine::ACTIONS)],
        ]);

        $validator->after(function ($v) use ($request, $tenant, $module) {
            foreach ((array) $request->input('conditions', []) as $i => $c) {
                if (!in_array($c['operator'] ?? '', ['empty', 'not_empty'], true) && blank($c['value'] ?? null)) {
                    $v->errors()->add("conditions.{$i}.value", __('Enter a value for the condition.'));
                }
            }
            foreach ((array) $request->input('actions', []) as $i => $a) {
                $at = "actions.{$i}";
                match ($a['type'] ?? '') {
                    'create_task' => blank($a['name'] ?? '') ? $v->errors()->add("{$at}.name", __('Give the task a name.')) : null,
                    'send_email' => (blank($a['subject'] ?? '') || blank($a['body'] ?? '')) ? $v->errors()->add("{$at}.subject", __('The e-mail needs a subject and a message.')) : null,
                    'add_note' => blank($a['text'] ?? '') ? $v->errors()->add("{$at}.text", __('Write the note.')) : null,
                    'change_stage' => ($module === 'lead' ? LeadStage::class : DealStage::class)::where('created_by', $tenant)->whereKey((int) ($a['stage_id'] ?? 0))->exists() ? null : $v->errors()->add("{$at}.stage_id", __('Choose a stage.')),
                    'assign_user' => $this->leads->assignableUserIds($tenant)->contains((int) ($a['user_id'] ?? 0)) ? null : $v->errors()->add("{$at}.user_id", __('Choose a user.')),
                    default => null,
                };
                if (($a['type'] ?? '') === 'send_email' && ($a['to'] ?? '') === 'custom' && !filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    $v->errors()->add("{$at}.email", __('Enter a valid e-mail address.'));
                }
            }
        });

        $data = $validator->validate();

        // keep only the keys the engine knows, nothing else gets stored
        $data['conditions'] = array_values(array_map(fn ($c) => ['field' => $c['field'], 'operator' => $c['operator'], 'value' => $c['value'] ?? null], $data['conditions'] ?? []));
        $data['actions'] = array_values(array_map(fn ($a) => array_intersect_key($a, array_flip(['type', 'name', 'due_days', 'priority', 'to', 'email', 'subject', 'body', 'stage_id', 'user_id', 'text'])), (array) $request->input('actions')));
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }
}
