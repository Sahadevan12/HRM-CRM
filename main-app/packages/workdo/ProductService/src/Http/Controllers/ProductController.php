<?php

namespace Workdo\ProductService\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\ProductService\Events\CreateProduct;
use Workdo\ProductService\Events\DestroyProduct;
use Workdo\ProductService\Events\UpdateProduct;
use Workdo\ProductService\Http\Requests\SaveProductRequest;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\ProductCategory;
use Workdo\ProductService\Models\ProductTax;
use Workdo\ProductService\Models\ProductUnit;
use Workdo\ProductService\Models\Warehouse;
use Workdo\ProductService\Services\StockService;

class ProductController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'sku', 'sale_price', 'purchase_price'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-products')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();

        $query = Product::with(['category:id,name', 'unit:id,name'])
            ->withSum('stocks as total_stock', 'quantity')
            ->where('created_by', $tenant)
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->where(fn ($s) => $s->where('name', 'like', $term)->orWhere('sku', 'like', $term));
            })
            ->when(in_array($request->get('type'), Product::TYPES, true), fn ($q) => $q->where('type', $request->get('type')))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->get('category')));

        $sortField = in_array($request->get('sort'), self::SORTABLE, true) ? $request->get('sort') : 'created_at';
        $sortDirection = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        return Inertia::render('ProductService/Products/Index', [
            'products' => $query->orderBy($sortField, $sortDirection)->paginate((int) $request->get('per_page', 10))->withQueryString(),
            'categories' => ProductCategory::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'units' => ProductUnit::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'taxes' => ProductTax::where('created_by', $tenant)->orderBy('name')->get(['id', 'name', 'rate']),
            'warehouses' => Warehouse::where('created_by', $tenant)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['search', 'type', 'category', 'sort', 'direction', 'per_page']),
        ]);
    }

    public function store(SaveProductRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-products')) {
            return back()->with('error', __('Permission denied'));
        }

        $product = Product::create($request->validated() + ['creator_id' => Auth::id(), 'created_by' => creatorId()]);

        CreateProduct::dispatch($request, $product);

        return back()->with('success', __('The product has been created successfully.'));
    }

    public function update(SaveProductRequest $request, Product $product): RedirectResponse
    {
        if (!Auth::user()->can('edit-products') || $product->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $product->update($request->validated());

        UpdateProduct::dispatch($request, $product);

        return back()->with('success', __('The product details are updated successfully.'));
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        if (!Auth::user()->can('delete-products') || $product->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        DestroyProduct::dispatch($request, $product);
        $product->delete(); // stock rows and transfers cascade

        return back()->with('success', __('The product has been deleted.'));
    }

    /** Stock of one product per active warehouse (for the stock dialog). */
    public function stock(Product $product): JsonResponse
    {
        if (!Auth::user()->can('manage-product-stock') || $product->created_by !== creatorId()) {
            return response()->json(['error' => __('Permission denied')], 403);
        }

        $quantities = $product->stocks()->pluck('quantity', 'warehouse_id');

        return response()->json(
            Warehouse::where('created_by', creatorId())->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($w) => ['warehouse_id' => $w->id, 'name' => $w->name, 'quantity' => (float) ($quantities[$w->id] ?? 0)])
                ->values()
        );
    }

    /** Manual stock count: set the quantity of a product in one warehouse. */
    public function updateStock(Request $request, Product $product, StockService $stock): RedirectResponse
    {
        if (!Auth::user()->can('manage-product-stock') || $product->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }
        if ($product->isService()) {
            return back()->with('error', __('Services do not have stock.'));
        }

        $validated = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('created_by', creatorId())],
            'quantity' => 'required|numeric|min:0|max:999999999',
        ]);

        $stock->set($product, (int) $validated['warehouse_id'], (float) $validated['quantity']);

        return back()->with('success', __('Stock updated successfully.'));
    }
}
