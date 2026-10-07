<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workdo\Hrm\Models\Acknowledgment;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\HrmDocument;

/** Company documents (policies, handbooks): private disk, served only through this controller, readable by every employee. */
class HrmDocumentController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $user = Auth::user();
        $manage = $user->can('manage-hrm-documents');

        if (!$manage && !$user->can('view-hrm-documents')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $employee = Employee::where('user_id', $user->id)->where('created_by', $tenant)->first();
        $done = $employee ? Acknowledgment::where('kind', 'document')->where('employee_id', $employee->id)->pluck('ref_id') : collect();
        $audience = Employee::where('created_by', $tenant)->where('status', 'active')->count();

        $documents = HrmDocument::where('created_by', $tenant)->latest('id')->get()->each(function (HrmDocument $d) use ($done, $manage, $audience) {
            $d->setAttribute('acknowledged', $done->contains($d->id));
            if ($manage && $d->requires_acknowledgment) {
                $d->setAttribute('ack_count', Acknowledgment::where('kind', 'document')->where('ref_id', $d->id)->count());
                $d->setAttribute('audience', $audience);
            }
        });

        return Inertia::render('Hrm/Documents/Index', [
            'documents' => $documents,
            'hasProfile' => (bool) $employee,
            'can' => ['create' => $user->can('create-hrm-documents'), 'delete' => $user->can('delete-hrm-documents')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-hrm-documents')) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:5120',
            'requires_acknowledgment' => 'boolean',
        ]);

        $file = $request->file('file');

        HrmDocument::create([
            'title' => $data['title'], 'description' => $data['description'] ?? null,
            'file_path' => $file->store('hrm-documents/' . creatorId(), 'local'), 'file_name' => $file->getClientOriginalName(), 'file_size' => $file->getSize(),
            'requires_acknowledgment' => $data['requires_acknowledgment'] ?? false, 'creator_id' => Auth::id(), 'created_by' => creatorId(),
        ]);

        return back()->with('success', __('The document has been uploaded.'));
    }

    public function download(HrmDocument $document): StreamedResponse|RedirectResponse
    {
        $user = Auth::user();

        if ($document->created_by !== creatorId() || !($user->can('manage-hrm-documents') || $user->can('view-hrm-documents'))) {
            return back()->with('error', __('Permission denied'));
        }

        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    public function destroy(HrmDocument $document): RedirectResponse
    {
        if (!Auth::user()->can('delete-hrm-documents') || $document->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        Storage::disk('local')->delete($document->file_path);
        Acknowledgment::where('kind', 'document')->where('ref_id', $document->id)->delete();
        $document->delete();

        return back()->with('success', __('The document has been deleted.'));
    }

    public function acknowledge(HrmDocument $document): RedirectResponse
    {
        $employee = Employee::where('user_id', Auth::id())->where('created_by', creatorId())->first();

        if (!$employee || $document->created_by !== creatorId() || !Auth::user()->can('view-hrm-documents')) {
            return back()->with('error', __('Permission denied'));
        }

        Acknowledgment::firstOrCreate(
            ['kind' => 'document', 'ref_id' => $document->id, 'employee_id' => $employee->id],
            ['acknowledged_at' => now(), 'created_by' => creatorId()],
        );

        return back()->with('success', __('Thank you, it is noted.'));
    }
}
