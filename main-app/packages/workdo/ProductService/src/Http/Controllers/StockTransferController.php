<?php

namespace Workdo\ProductService\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\ProductService\Events\CreateStockTransfer;
use Workdo\ProductService\Events\DestroyStockTransfer;
use Workdo\ProductService\Exceptions\InsufficientStockException;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\StockTransfer;
use Workdo\ProductService\Models\Warehouse;
use Workdo\ProductService\Services\StockService;

class StockTransferController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-transfers')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();

        return Inertia::render('ProductService/StockTransfers/Index', [
            'transfers' => StockTransfer::with(['product:id,name,sku', 'fromWarehouse:id,name', 'toWarehouse:id,name'])
                ->where('created_by', $tenant)
                ->when($request->filled('search'), fn ($q) => $q->whereHas('product', function ($p) use ($request) {
                    $term = '%' . $request->get('search') . '%';
                    $p->where('name', 'like', $term)->orWhere('sku', 'like', $term);
                }))
                ->latest('transfer_date')->latest('id')
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'products' => Product::where('created_by', $tenant)->where('type', 'product')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'warehouses' => Warehouse::where('created_by', $tenant)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        if (!Auth::user()->can('create-transfers')) {
            return back()->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $owned = fn (string $table) => Rule::exists($table, 'id')->where('created_by', $tenant);

        $validated = $request->validate([
            'product_id' => ['required', $owned('products')],
            'from_warehouse_id' => ['required', $owned('warehouses')],
            'to_warehouse_id' => ['required', 'different:from_warehouse_id', $owned('warehouses')],
            'quantity' => 'required|numeric|gt:0|max:999999999',
            'transfer_date' => 'required|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        if ($product->isService()) {
            return back()->withErrors(['product_id' => __('Services do not have stock.')]);
        }

        try {
            $transfer = DB::transaction(function () use ($stock, $product, $validated, $tenant) {
                $stock->transfer($product, (int) $validated['from_warehouse_id'], (int) $validated['to_warehouse_id'], (float) $validated['quantity']);

                return StockTransfer::create($validated + ['creator_id' => Auth::id(), 'created_by' => $tenant]);
            });
        } catch (InsufficientStockException $e) {
            return back()->withErrors(['quantity' => $e->getMessage()]);
        }

        CreateStockTransfer::dispatch($request, $transfer);

        return back()->with('success', __('The transfer has been completed.'));
    }

    /** Deleting a transfer moves the stock back (only possible while the destination still holds it). */
    public function destroy(Request $request, StockTransfer $transfer, StockService $stock): RedirectResponse
    {
        if (!Auth::user()->can('delete-transfers') || $transfer->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        try {
            DB::transaction(function () use ($stock, $transfer) {
                $stock->transfer($transfer->product, $transfer->to_warehouse_id, $transfer->from_warehouse_id, $transfer->quantity);
                $transfer->delete();
            });
        } catch (InsufficientStockException $e) {
            return back()->with('error', __('The transfer cannot be reversed: ') . $e->getMessage());
        }

        DestroyStockTransfer::dispatch($request, $transfer);

        return back()->with('success', __('The transfer has been reversed and deleted.'));
    }
}
