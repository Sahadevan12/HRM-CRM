<?php

namespace Workdo\Pos\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Pos\Http\Requests\CheckoutRequest;
use Workdo\Pos\Models\PosSale;
use Workdo\Pos\Services\PosService;
use Workdo\Pos\Support\WalkInCustomer;
use Workdo\ProductService\Exceptions\InsufficientStockException;
use Workdo\ProductService\Models\Product;
use Workdo\ProductService\Models\ProductCategory;
use Workdo\ProductService\Models\ProductTax;
use Workdo\ProductService\Models\Warehouse;
use Workdo\SalesPurchase\Exceptions\DocumentStateException;

class PosController extends Controller
{
    private const PRODUCT_LIMIT = 60;

    public function terminal(): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-pos')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $walkIn = WalkInCustomer::id($tenant);

        return Inertia::render('Pos/Terminal', [
            'warehouses' => Warehouse::where('created_by', $tenant)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => User::where('created_by', $tenant)->where('type', 'client')->where('id', '!=', $walkIn)->orderBy('name')->get(['id', 'name']),
            'taxes' => ProductTax::where('created_by', $tenant)->get(['id', 'name', 'rate']),
            'categories' => ProductCategory::where('created_by', $tenant)->orderBy('name')->get(['id', 'name']),
            'methods' => PosSale::METHODS,
            'canSell' => Auth::user()->can('create-pos'),
        ]);
    }

    /** Product lookup for the terminal: free text, category, or an exact SKU / barcode. Stock is the chosen warehouse's. */
    public function products(Request $request): JsonResponse
    {
        if (!Auth::user()->can('manage-pos')) {
            return response()->json(['error' => __('Permission denied')], 403);
        }

        $tenant = creatorId();
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('created_by', $tenant)],
            'q' => 'nullable|string|max:100',
            'sku' => 'nullable|string|max:100',
            'category' => 'nullable|integer',
        ]);

        $query = Product::where('created_by', $tenant)->where('is_active', true)
            ->withSum(['stocks as stock' => fn ($q) => $q->where('warehouse_id', $data['warehouse_id'])], 'quantity')
            ->when($data['sku'] ?? null, fn ($q, $sku) => $q->where('sku', $sku))
            ->when($data['q'] ?? null, function ($q, $term) {
                $like = '%' . $term . '%';
                $q->where(fn ($s) => $s->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->when($data['category'] ?? null, fn ($q, $category) => $q->where('category_id', $category))
            ->orderBy('name')->limit(self::PRODUCT_LIMIT);

        return response()->json(
            $query->get()
                // services are always sellable, goods only while the warehouse has some
                ->filter(fn (Product $p) => $p->isService() || (float) $p->stock > 0)
                ->map(fn (Product $p) => [
                    'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'type' => $p->type, 'category_id' => $p->category_id,
                    'sale_price' => $p->sale_price, 'tax_ids' => $p->tax_ids ?? [], 'stock' => $p->isService() ? null : (float) $p->stock,
                ])->values()
        );
    }

    public function checkout(CheckoutRequest $request, PosService $pos): RedirectResponse
    {
        if (!Auth::user()->can('create-pos')) {
            return back()->with('error', __('Permission denied'));
        }

        try {
            $sale = $pos->checkout(creatorId(), Auth::id(), $request->validated());
        } catch (InsufficientStockException|DocumentStateException $e) {
            return back()->with('error', $e->getMessage()); // nothing was saved: the whole sale was rolled back
        }

        return redirect()->route('pos.receipts.show', $sale->id)->with('success', __('The sale has been completed.'));
    }

    public function receipt(PosSale $sale): Response|RedirectResponse
    {
        $user = Auth::user();

        if (!($user->can('manage-pos') || $user->can('manage-pos-orders')) || $sale->created_by !== creatorId()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        return Inertia::render('Pos/Receipt', [
            'sale' => $sale->load('document.items.taxes', 'document.party:id,name', 'document.warehouse:id,name', 'cashier:id,name'),
            'companyName' => companyOf($user)->name,
        ]);
    }
}
