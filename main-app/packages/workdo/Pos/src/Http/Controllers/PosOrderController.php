<?php

namespace Workdo\Pos\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\Pos\Models\PosSale;

class PosOrderController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-pos-orders')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = PosSale::with(['document:id,number,doc_date,total_amount,paid_amount,party_id', 'document.party:id,name', 'cashier:id,name'])
            ->where('pos_sales.created_by', creatorId())
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $q->whereHas('document', fn ($d) => $d->where('number', 'like', $term)->orWhereHas('party', fn ($p) => $p->where('name', 'like', $term)));
            })
            ->when(in_array($request->get('method'), PosSale::METHODS, true), fn ($q) => $q->where('payment_method', $request->get('method')))
            ->when($request->filled('from'), fn ($q) => $q->whereHas('document', fn ($d) => $d->whereDate('doc_date', '>=', $request->get('from'))))
            ->when($request->filled('to'), fn ($q) => $q->whereHas('document', fn ($d) => $d->whereDate('doc_date', '<=', $request->get('to'))));

        $total = (clone $query)->join('documents', 'documents.id', '=', 'pos_sales.document_id')->sum('documents.total_amount');

        return Inertia::render('Pos/Orders/Index', [
            'sales' => $query->latest('id')->paginate((int) $request->get('per_page', 15))->withQueryString(),
            'total' => round((float) $total, 2),
            'methods' => PosSale::METHODS,
            'filters' => $request->only(['search', 'method', 'from', 'to']),
        ]);
    }
}
