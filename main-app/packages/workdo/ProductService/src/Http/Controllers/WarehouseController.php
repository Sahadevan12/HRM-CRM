<?php

namespace Workdo\ProductService\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\ProductService\Events\CreateWarehouse;
use Workdo\ProductService\Events\DestroyWarehouse;
use Workdo\ProductService\Events\UpdateWarehouse;
use Workdo\ProductService\Http\Requests\SaveWarehouseRequest;
use Workdo\ProductService\Models\Warehouse;

class WarehouseController extends Controller
{
    private const SORTABLE = ['id', 'created_at', 'name', 'city', 'phone', 'email'];

    private const SEARCHABLE = ['name', 'address', 'city', 'phone', 'email'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-warehouses')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Warehouse::where('created_by', creatorId());

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

        return Inertia::render('ProductService/Warehouses/Index', [
            'warehouses' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'filters' => $request->only(['search', 'sort', 'direction', 'per_page']),
        ]);
    }

    public function store(SaveWarehouseRequest $request): RedirectResponse
    {
        if (!Auth::user()->can('create-warehouses')) {
            return back()->with('error', __('Permission denied'));
        }

        $warehouse = Warehouse::create($request->validated() + [
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);

        CreateWarehouse::dispatch($request, $warehouse);

        return back()->with('success', __('The warehouse has been created successfully.'));
    }

    public function update(SaveWarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        if (!Auth::user()->can('edit-warehouses') || $warehouse->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $warehouse->update($request->validated());

        UpdateWarehouse::dispatch($request, $warehouse);

        return back()->with('success', __('The warehouse details are updated successfully.'));
    }

    public function destroy(Request $request, Warehouse $warehouse): RedirectResponse
    {
        if (!Auth::user()->can('delete-warehouses') || $warehouse->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        // never silently drop stock: the warehouse must be empty and have no transfer history
        if ($warehouse->stocks()->where('quantity', '>', 0)->exists()) {
            return back()->with('error', __('This warehouse still holds stock. Move or clear it first.'));
        }
        if (\Workdo\ProductService\Models\StockTransfer::where('from_warehouse_id', $warehouse->id)->orWhere('to_warehouse_id', $warehouse->id)->exists()) {
            return back()->with('error', __('This warehouse has stock transfers and cannot be deleted. Disable it instead.'));
        }

        DestroyWarehouse::dispatch($request, $warehouse);
        $warehouse->delete();

        return back()->with('success', __('The warehouse has been deleted.'));
    }
}
