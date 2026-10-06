<?php

namespace Workdo\ProductService\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\ProductService\Events\CreateProductUnit;
use Workdo\ProductService\Events\DestroyProductUnit;
use Workdo\ProductService\Events\UpdateProductUnit;
use Workdo\ProductService\Http\Requests\SaveProductUnitRequest;
use Workdo\ProductService\Models\ProductUnit;

class ProductUnitController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name'];

    private const SEARCHABLE = ['name'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-product-units')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = ProductUnit::where('created_by', creatorId());

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

        return Inertia::render('ProductService/ProductUnits/Index', [
            'productUnits' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
        ]);
    }

    public function store(SaveProductUnitRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-product-units')) {
            return back()->with('error', __('Permission denied'));
        }

        $productUnit = ProductUnit::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateProductUnit::dispatch($request, $productUnit);

        return back()->with('success', __('The product unit has been created successfully.'));
    }

    public function update(SaveProductUnitRequest $request, ProductUnit $productUnit): RedirectResponse
    {
        if (!Auth::user()->can('edit-product-units') || $productUnit->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $productUnit->update($request->validated());

        UpdateProductUnit::dispatch($request, $productUnit);

        return back()->with('success', __('The product unit details are updated successfully.'));
    }

    public function destroy(Request $request, ProductUnit $productUnit): RedirectResponse
    {
        if (!Auth::user()->can('delete-product-units') || $productUnit->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyProductUnit::dispatch($request, $productUnit);
        $productUnit->delete();

        return back()->with('success', __('The product unit has been deleted.'));
    }
}
