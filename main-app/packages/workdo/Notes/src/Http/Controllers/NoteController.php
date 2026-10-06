<?php

namespace Workdo\Notes\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Notes\Events\CreateNote;
use Workdo\Notes\Events\DestroyNote;
use Workdo\Notes\Events\UpdateNote;
use Workdo\Notes\Http\Requests\SaveNoteRequest;
use Workdo\Notes\Models\Note;

class NoteController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'title', 'priority', 'budget', 'due_on'];

    private const SEARCHABLE = ['title', 'body'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-notes')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Note::where('created_by', creatorId());

        if ($request->filled('search') && self::SEARCHABLE) {
            $term = '%' . $request->get('search') . '%';
            $query->where(function ($q) use ($term) {
                foreach (self::SEARCHABLE as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        $sortField = in_array($request->get('sort'), self::SORTABLE, true) ? $request->get('sort') : 'created_at';
        $sortDirection = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        return Inertia::render('Notes/Notes/Index', [
            'notes' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
        ]);
    }

    public function store(SaveNoteRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-notes')) {
            return back()->with('error', __('Permission denied'));
        }

        $note = Note::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateNote::dispatch($request, $note);

        return back()->with('success', __('The note has been created successfully.'));
    }

    public function update(SaveNoteRequest $request, Note $note): RedirectResponse
    {
        if (!Auth::user()->can('edit-notes') || $note->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $note->update($request->validated());

        UpdateNote::dispatch($request, $note);

        return back()->with('success', __('The note details are updated successfully.'));
    }

    public function destroy(Request $request, Note $note): RedirectResponse
    {
        if (!Auth::user()->can('delete-notes') || $note->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyNote::dispatch($request, $note);
        $note->delete();

        return back()->with('success', __('The note has been deleted.'));
    }
}
