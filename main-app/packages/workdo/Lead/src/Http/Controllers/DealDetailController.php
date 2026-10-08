<?php

namespace Workdo\Lead\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workdo\Lead\Events\DealCallAdded;
use Workdo\Lead\Events\DealDiscussionAdded;
use Workdo\Lead\Events\DealEmailAdded;
use Workdo\Lead\Events\DealFileUploaded;
use Workdo\Lead\Events\DealTaskAdded;
use Workdo\Lead\Models\Deal;
use Workdo\Lead\Models\DealCall;
use Workdo\Lead\Models\DealDiscussion;
use Workdo\Lead\Models\DealEmail;
use Workdo\Lead\Models\DealFile;
use Workdo\Lead\Models\DealTask;
use Workdo\Lead\Services\DealService;

/**
 * Everything the deal drawer needs, as JSON: the deal with its sources / labels / products, tasks, calls, e-mails, discussion, files and the
 * activity trail. Every action has its own permission and every mutation answers with the refreshed detail.
 */
class DealDetailController extends Controller
{
    /** kind => [model, permission, validation rules, event, activity text] */
    private const ITEMS = [
        'task' => [DealTask::class, 'manage-deal-tasks', DealTaskAdded::class],
        'call' => [DealCall::class, 'manage-deal-calls', DealCallAdded::class],
        'email' => [DealEmail::class, 'manage-deal-emails', DealEmailAdded::class],
        'discussion' => [DealDiscussion::class, 'manage-deal-discussions', DealDiscussionAdded::class],
    ];

    public function __construct(private DealService $deals)
    {
    }

    public function show(Deal $deal): JsonResponse
    {
        return $this->guard($deal, 'manage-deals') ?? $this->detail($deal);
    }

    /** Sources, labels and products of the deal. */
    public function sync(Request $request, Deal $deal): JsonResponse
    {
        if ($denied = $this->guard($deal, 'edit-deals')) {
            return $denied;
        }

        $tenant = creatorId();
        $data = $request->validate([
            'source_ids' => 'array|max:50',
            'source_ids.*' => ['integer', 'distinct', Rule::exists('sources', 'id')->where('created_by', $tenant)],
            // a label belongs to a pipeline: only the labels of the deal's own pipeline fit
            'label_ids' => 'array|max:50',
            'label_ids.*' => ['integer', 'distinct', Rule::exists('labels', 'id')->where('created_by', $tenant)->where('pipeline_id', $deal->pipeline_id)],
            'product_ids' => 'array|max:100',
            'product_ids.*' => ['integer', 'distinct', Rule::exists('products', 'id')->where('created_by', $tenant)],
        ]);

        foreach (['sources' => 'source_ids', 'labels' => 'label_ids', 'products' => 'product_ids'] as $relation => $key) {
            if (array_key_exists($key, $data)) {
                $changes = $deal->{$relation}()->sync($data[$key]);
                if ($changes['attached'] || $changes['detached']) {
                    $this->deals->log($deal, Auth::id(), $relation, __(':relation of the deal were changed', ['relation' => ucfirst($relation)]));
                }
            }
        }

        return $this->detail($deal);
    }

    public function add(Request $request, Deal $deal, string $kind): JsonResponse
    {
        [$model, $permission, $event] = self::ITEMS[$kind] ?? abort(404);

        if ($denied = $this->guard($deal, $permission)) {
            return $denied;
        }

        $data = $request->validate($this->rules($kind));
        $item = $model::create($data + ['deal_id' => $deal->id, 'creator_id' => Auth::id(), 'created_by' => creatorId()]);

        $this->deals->log($deal, Auth::id(), $kind, $this->remark($kind, $item));
        $event::dispatch($deal, $item);

        return $this->detail($deal);
    }

    public function toggleTask(Deal $deal, int $task): JsonResponse
    {
        if ($denied = $this->guard($deal, 'manage-deal-tasks')) {
            return $denied;
        }

        $row = DealTask::where('deal_id', $deal->id)->findOrFail($task);
        $row->update(['status' => $row->status === 'completed' ? 'on_going' : 'completed']);
        $this->deals->log($deal, Auth::id(), 'task', $row->status === 'completed' ? __('Task completed: :name', ['name' => $row->name]) : __('Task reopened: :name', ['name' => $row->name]));

        return $this->detail($deal);
    }

    /** The author may remove their own entry, people who may delete deals any entry. */
    public function remove(Deal $deal, string $kind, int $id): JsonResponse
    {
        $model = $kind === 'file' ? DealFile::class : (self::ITEMS[$kind][0] ?? abort(404));
        $permission = $kind === 'file' ? 'manage-deal-files' : self::ITEMS[$kind][1];

        if ($denied = $this->guard($deal, $permission)) {
            return $denied;
        }

        $item = $model::where('deal_id', $deal->id)->findOrFail($id);
        if ($item->creator_id !== Auth::id() && !Auth::user()->can('delete-deals')) {
            return response()->json(['message' => __('Permission denied')], 403);
        }

        if ($item instanceof DealFile) {
            Storage::disk('local')->delete($item->file_path);
        }
        $item->delete();

        return $this->detail($deal);
    }

    // ───────────── files ─────────────

    public function upload(Request $request, Deal $deal): JsonResponse
    {
        if ($denied = $this->guard($deal, 'manage-deal-files')) {
            return $denied;
        }

        $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt|max:5120']);
        $file = $request->file('file');

        $item = DealFile::create([
            'deal_id' => $deal->id, 'file_name' => $file->getClientOriginalName(), 'file_path' => $file->store('deal-files/' . creatorId(), 'local'),
            'file_size' => $file->getSize(), 'creator_id' => Auth::id(), 'created_by' => creatorId(),
        ]);

        $this->deals->log($deal, Auth::id(), 'file', __('File uploaded: :name', ['name' => $item->file_name]));
        DealFileUploaded::dispatch($deal, $item);

        return $this->detail($deal);
    }

    public function download(Deal $deal, int $file): StreamedResponse|JsonResponse
    {
        if ($denied = $this->guard($deal, 'manage-deals')) {
            return $denied;
        }

        $item = DealFile::where('deal_id', $deal->id)->findOrFail($file);
        abort_unless(Storage::disk('local')->exists($item->file_path), 404);

        return Storage::disk('local')->download($item->file_path, $item->file_name);
    }

    // ───────────── helpers ─────────────

    /** 403 unless the deal is of this company, visible to the user and the user holds the permission. */
    private function guard(Deal $deal, string $permission): ?JsonResponse
    {
        $ok = $deal->created_by === creatorId() && Auth::user()->can($permission) && Deal::whereKey($deal->id)->visibleTo(Auth::user())->exists();

        return $ok ? null : response()->json(['message' => __('Permission denied')], 403);
    }

    private function detail(Deal $deal): JsonResponse
    {
        $deal->load([
            'users:id,name', 'clients:id,name', 'sources:id,name', 'labels:id,name,color', 'products:id,name,sku', 'stage:id,name', 'pipeline:id,name',
            'tasks.creator:id,name', 'calls.creator:id,name', 'emails.creator:id,name', 'discussions.creator:id,name', 'files.creator:id,name',
            'activities' => fn ($q) => $q->with('user:id,name')->limit(100),
        ]);

        return response()->json(['deal' => $deal, 'me' => Auth::id()]);
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
