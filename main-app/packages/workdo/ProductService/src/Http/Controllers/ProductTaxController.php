<?php

namespace Workdo\ProductService\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\ProductService\Events\CreateProductTax;
use Workdo\ProductService\Events\DestroyProductTax;
use Workdo\ProductService\Events\UpdateProductTax;
use Workdo\ProductService\Http\Requests\SaveProductTaxRequest;
use Workdo\ProductService\Models\ProductTax;

class ProductTaxController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'rate'];

    private const SEARCHABLE = ['name'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-product-taxes')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = ProductTax::where('created_by', creatorId());

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

        return Inertia::render('ProductService/ProductTaxes/Index', [
            'productTaxes' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
        ]);
    }

    public function store(SaveProductTaxRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-product-taxes')) {
            return back()->with('error', __('Permission denied'));
        }

        $productTax = ProductTax::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateProductTax::dispatch($request, $productTax);

        return back()->with('success', __('The product tax has been created successfully.'));
    }

    public function update(SaveProductTaxRequest $request, ProductTax $productTax): RedirectResponse
    {
        if (!Auth::user()->can('edit-product-taxes') || $productTax->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $productTax->update($request->validated());

        UpdateProductTax::dispatch($request, $productTax);

        return back()->with('success', __('The product tax details are updated successfully.'));
    }

    public function destroy(Request $request, ProductTax $productTax): RedirectResponse
    {
        if (!Auth::user()->can('delete-product-taxes') || $productTax->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyProductTax::dispatch($request, $productTax);
        $productTax->delete();

        return back()->with('success', __('The product tax has been deleted.'));
    }
}
