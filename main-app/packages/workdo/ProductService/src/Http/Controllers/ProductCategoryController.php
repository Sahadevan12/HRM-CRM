<?php

namespace Workdo\ProductService\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\ProductService\Events\CreateProductCategory;
use Workdo\ProductService\Events\DestroyProductCategory;
use Workdo\ProductService\Events\UpdateProductCategory;
use Workdo\ProductService\Http\Requests\SaveProductCategoryRequest;
use Workdo\ProductService\Models\ProductCategory;

class ProductCategoryController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'color'];

    private const SEARCHABLE = ['name', 'color'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-product-categories')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = ProductCategory::where('created_by', creatorId());

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

        return Inertia::render('ProductService/ProductCategories/Index', [
            'productCategories' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
        ]);
    }

    public function store(SaveProductCategoryRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-product-categories')) {
            return back()->with('error', __('Permission denied'));
        }

        $productCategory = ProductCategory::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateProductCategory::dispatch($request, $productCategory);

        return back()->with('success', __('The product category has been created successfully.'));
    }

    public function update(SaveProductCategoryRequest $request, ProductCategory $productCategory): RedirectResponse
    {
        if (!Auth::user()->can('edit-product-categories') || $productCategory->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $productCategory->update($request->validated());

        UpdateProductCategory::dispatch($request, $productCategory);

        return back()->with('success', __('The product category details are updated successfully.'));
    }

    public function destroy(Request $request, ProductCategory $productCategory): RedirectResponse
    {
        if (!Auth::user()->can('delete-product-categories') || $productCategory->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyProductCategory::dispatch($request, $productCategory);
        $productCategory->delete();

        return back()->with('success', __('The product category has been deleted.'));
    }
}
