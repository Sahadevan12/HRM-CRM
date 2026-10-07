<?php

namespace Workdo\Lead\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workdo\Lead\Events\LeadCallAdded;
use Workdo\Lead\Events\LeadDiscussionAdded;
use Workdo\Lead\Events\LeadEmailAdded;
use Workdo\Lead\Events\LeadFileUploaded;
use Workdo\Lead\Events\LeadTaskAdded;
use Workdo\Lead\Models\Lead;
use Workdo\Lead\Models\LeadCall;
use Workdo\Lead\Models\LeadDiscussion;
use Workdo\Lead\Models\LeadEmail;
use Workdo\Lead\Models\LeadFile;
use Workdo\Lead\Models\LeadTask;
use Workdo\Lead\Services\LeadService;

/**
 * Everything the lead drawer needs, as JSON: the lead with its sources / labels / products, tasks, calls, e-mails, discussion, files and the
 * activity trail. Every action has its own permission and every mutation answers with the refreshed detail.
 */
class LeadDetailController extends Controller
{
    /** kind => [model, permission, validation rules, event, activity text] */
    private const ITEMS = [
        'task' => [LeadTask::class, 'manage-lead-tasks', LeadTaskAdded::class],
        'call' => [LeadCall::class, 'manage-lead-calls', LeadCallAdded::class],
        'email' => [LeadEmail::class, 'manage-lead-emails', LeadEmailAdded::class],
        'discussion' => [LeadDiscussion::class, 'manage-lead-discussions', LeadDiscussionAdded::class],
    ];

    public function __construct(private LeadService $leads)
    {
    }

    public function show(Lead $lead): JsonResponse
    {
        return $this->guard($lead, 'manage-leads') ?? $this->detail($lead);
    }

    /** Sources, labels and products of the lead. */
    public function sync(Request $request, Lead $lead): JsonResponse
    {
        if ($denied = $this->guard($lead, 'edit-leads')) {
            return $denied;
        }

        $tenant = creatorId();
        $data = $request->validate([
            'source_ids' => 'array|max:50',
            'source_ids.*' => ['integer', 'distinct', Rule::exists('sources', 'id')->where('created_by', $tenant)],
            // a label belongs to a pipeline: only the labels of the lead's own pipeline fit
            'label_ids' => 'array|max:50',
            'label_ids.*' => ['integer', 'distinct', Rule::exists('labels', 'id')->where('created_by', $tenant)->where('pipeline_id', $lead->pipeline_id)],
            'product_ids' => 'array|max:100',
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('created_by', $tenant)],
        ]);

        foreach (['sources' => 'source_ids', 'labels' => 'label_ids', 'products' => 'product_ids'] as $relation => $key) {
            if (array_key_exists($key, $data)) {
                $changes = $lead->{$relation}()->sync($data[$key]);
                if ($changes['attached'] || $changes['detached']) {
                    $this->leads->log($lead, Auth::id(), $relation, __(':relation of the lead were changed', ['relation' => ucfirst($relation)]));
                }
            }
        }

        return $this->detail($lead);
    }

    public function add(Request $request, Lead $lead, string $kind): JsonResponse
    {
        [$model, $permission, $event] = self::ITEMS[$kind] ?? abort(404);

        if ($denied = $this->guard($lead, $permission)) {
            return $denied;
        }

        $data = $request->validate($this->rules($kind));
        $item = $model::create($data + ['lead_id' => $lead->id, 'creator_id' => Auth::id(), 'created_by' => creatorId()]);

        $this->leads->log($lead, Auth::id(), $kind, $this->remark($kind, $item));
        $event::dispatch($lead, $item);

        return $this->detail($lead);
    }

    public function toggleTask(Lead $lead, int $task): JsonResponse
    {
        if ($denied = $this->guard($lead, 'manage-lead-tasks')) {
            return $denied;
        }

        $row = LeadTask::where('lead_id', $lead->id)->findOrFail($task);
        $row->update(['status' => $row->status === 'completed' ? 'on_going' : 'completed']);
        $this->leads->log($lead, Auth::id(), 'task', $row->status === 'completed' ? __('Task completed: :name', ['name' => $row->name]) : __('Task reopened: :name', ['name' => $row->name]));

        return $this->detail($lead);
    }

    /** The author may remove their own entry, people who may delete leads any entry. */
    public function remove(Lead $lead, string $kind, int $id): JsonResponse
    {
        $model = $kind === 'file' ? LeadFile::class : (self::ITEMS[$kind][0] ?? abort(404));
        $permission = $kind === 'file' ? 'manage-lead-files' : self::ITEMS[$kind][1];

        if ($denied = $this->guard($lead, $permission)) {
            return $denied;
        }

        $item = $model::where('lead_id', $lead->id)->findOrFail($id);
        if ($item->creator_id !== Auth::id() && !Auth::user()->can('delete-leads')) {
            return response()->json(['message' => __('Permission denied')], 403);
        }

        if ($item instanceof LeadFile) {
            Storage::disk('local')->delete($item->file_path);
        }
        $item->delete();

        return $this->detail($lead);
    }

    // ───────────── files ─────────────

    public function upload(Request $request, Lead $lead): JsonResponse
    {
        if ($denied = $this->guard($lead, 'manage-lead-files')) {
            return $denied;
        }

        $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt|max:5120']);
        $file = $request->file('file');

        $item = LeadFile::create([
            'lead_id' => $lead->id, 'file_name' => $file->getClientOriginalName(), 'file_path' => $file->store('lead-files/' . creatorId(), 'local'),
            'file_size' => $file->getSize(), 'creator_id' => Auth::id(), 'created_by' => creatorId(),
        ]);

        $this->leads->log($lead, Auth::id(), 'file', __('File uploaded: :name', ['name' => $item->file_name]));
        LeadFileUploaded::dispatch($lead, $item);

        return $this->detail($lead);
    }

    public function download(Lead $lead, int $file): StreamedResponse|JsonResponse
    {
        if ($denied = $this->guard($lead, 'manage-leads')) {
            return $denied;
        }

        $item = LeadFile::where('lead_id', $lead->id)->findOrFail($file);
        abort_unless(Storage::disk('local')->exists($item->file_path), 404);

        return Storage::disk('local')->download($item->file_path, $item->file_name);
    }

    // ───────────── helpers ─────────────

    /** 403 unless the lead is of this company, visible to the user and the user holds the permission. */
    private function guard(Lead $lead, string $permission): ?JsonResponse
    {
        $ok = $lead->created_by === creatorId() && Auth::user()->can($permission) && Lead::whereKey($lead->id)->visibleTo(Auth::user())->exists();

        return $ok ? null : response()->json(['message' => __('Permission denied')], 403);
    }

    private function detail(Lead $lead): JsonResponse
    {
        $lead->load([
            'users:id,name', 'sources:id,name', 'labels:id,name,color', 'products:id,name,sku', 'stage:id,name', 'pipeline:id,name',
            'tasks.creator:id,name', 'calls.creator:id,name', 'emails.creator:id,name', 'discussions.creator:id,name', 'files.creator:id,name',
            'activities' => fn ($q) => $q->with('user:id,name')->limit(100),
        ]);

        return response()->json(['lead' => $lead, 'me' => Auth::id()]);
    }

    /** @return array<string, mixed> */
    private function rules(string $kind): array
    {
        return match ($kind) {
            'task' => ['name' => 'required|string|max:255', 'due_date' => 'required|date', 'due_time' => 'nullable|date_format:H:i', 'priority' => ['required', Rule::in(['low', 'medium', 'high'])]],
            'call' => ['subject' => 'required|string|max:255', 'call_type' => ['required', Rule::in(['inbound', 'outbound'])], 'duration_minutes' => 'required|integer|min:0|max:1440', 'description' => 'nullable|string|max:5000', 'result' => 'nullable|string|max:5000'],
            'email' => ['to' => 'required|email|max:255', 'subject' => 'required|string|max:255', 'description' => 'nullable|string|max:10000'],
            'discussion' => ['comment' => 'required|string|max:5000'],
        };
    }

    private function remark(string $kind, $item): string
    {
        return match ($kind) {
            'task' => __('Task added: :name', ['name' => $item->name]),
            'call' => __('Call logged: :subject', ['subject' => $item->subject]),
            'email' => __('E-mail noted: :subject', ['subject' => $item->subject]),
            'discussion' => __('Comment added'),
        };
    }
}
