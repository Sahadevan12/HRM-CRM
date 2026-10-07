<?php

namespace Workdo\Hrm\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\EmployeeDocument;

/** Employee files (contracts, ID proofs...) live on the PRIVATE disk and are only served through this controller. */
class EmployeeDocumentController extends Controller
{
    public function store(Request $request, Employee $employee): RedirectResponse
    {
        if (!Auth::user()->can('manage-employee-documents') || $employee->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $data = $request->validate([
            'document_type_id' => ['nullable', Rule::exists('employee_document_types', 'id')->where('created_by', creatorId())],
            'title' => 'required|string|max:255',
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:5120',
            'expires_on' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $file = $request->file('file');

        $employee->documents()->create([
            'document_type_id' => $data['document_type_id'] ?? null,
            'title' => $data['title'],
            'file_path' => $file->store('employee-documents/' . creatorId(), 'local'),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'expires_on' => $data['expires_on'] ?? null,
            'notes' => $data['notes'] ?? null,
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        return back()->with('success', __('The document has been uploaded.'));
    }

    public function download(Employee $employee, EmployeeDocument $document): StreamedResponse|RedirectResponse
    {
        if (!Auth::user()->can('manage-employee-documents') || !$this->belongs($employee, $document)) {
            return back()->with('error', __('Permission denied'));
        }

        abort_unless(Storage::disk('local')->exists($document->file_path), 404);

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    public function destroy(Employee $employee, EmployeeDocument $document): RedirectResponse
    {
        if (!Auth::user()->can('manage-employee-documents') || !$this->belongs($employee, $document)) {
            return back()->with('error', __('Permission denied'));
        }

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        return back()->with('success', __('The document has been deleted.'));
    }

    /** The document must belong to this employee AND to the current company. */
    private function belongs(Employee $employee, EmployeeDocument $document): bool
    {
        return $employee->created_by === creatorId() && $document->employee_id === $employee->id && $document->created_by === creatorId();
    }
}
