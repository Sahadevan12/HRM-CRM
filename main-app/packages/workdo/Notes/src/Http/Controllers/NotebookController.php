<?php

namespace Workdo\Notes\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Notes\Events\CreateNotebook;
use Workdo\Notes\Events\DestroyNotebook;
use Workdo\Notes\Events\UpdateNotebook;
use Workdo\Notes\Http\Requests\SaveNotebookRequest;
use Workdo\Notes\Models\Notebook;

class NotebookController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name'];

    private const SEARCHABLE = ['name', 'description'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-notebooks')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Notebook::where('created_by', creatorId());

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

        return Inertia::render('Notes/Notebooks/Index', [
            'notebooks' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
        ]);
    }

    public function store(SaveNotebookRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-notebooks')) {
            return back()->with('error', __('Permission denied'));
        }

        $notebook = Notebook::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateNotebook::dispatch($request, $notebook);

        return back()->with('success', __('The notebook has been created successfully.'));
    }

    public function update(SaveNotebookRequest $request, Notebook $notebook): RedirectResponse
    {
        if (!Auth::user()->can('edit-notebooks') || $notebook->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $notebook->update($request->validated());

        UpdateNotebook::dispatch($request, $notebook);

        return back()->with('success', __('The notebook details are updated successfully.'));
    }

    public function destroy(Request $request, Notebook $notebook): RedirectResponse
    {
        if (!Auth::user()->can('delete-notebooks') || $notebook->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyNotebook::dispatch($request, $notebook);
        $notebook->delete();

        return back()->with('success', __('The notebook has been deleted.'));
    }
}
