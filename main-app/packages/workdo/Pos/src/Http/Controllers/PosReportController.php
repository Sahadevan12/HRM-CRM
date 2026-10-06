<?php

namespace Workdo\Pos\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Workdo\SalesPurchase\Support\DocumentType;

/** Sales figures of the counter for a date range (invoice date), net of approved returns. */
class PosReportController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-pos-reports')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $tenant = creatorId();
        $from = $this->date($request->get('from'), now()->toDateString());
        $to = $this->date($request->get('to'), now()->toDateString());
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        // every POS invoice of the company in the range
        $sales = fn () => DB::table('pos_sales as s')->join('documents as d', 'd.id', '=', 's.document_id')
            ->where('s.created_by', $tenant)->whereBetween('d.doc_date', [$from, $to]);

        $summary = $sales()->selectRaw('COUNT(*) as sales, COALESCE(SUM(d.subtotal),0) as subtotal, COALESCE(SUM(d.discount_amount),0) as discount, COALESCE(SUM(d.tax_amount),0) as tax, COALESCE(SUM(d.total_amount),0) as total')->first();

        // approved/completed returns (dated in the range) of invoices that came from the POS
        $returns = (float) DB::table('documents as r')
            ->join('pos_sales as s', 's.document_id', '=', 'r.parent_id')
            ->where('r.created_by', $tenant)->where('r.type', DocumentType::SALES_RETURN)->whereIn('r.status', ['approved', 'completed'])
            ->whereBetween('r.doc_date', [$from, $to])->sum('r.total_amount');

        $total = round((float) $summary->total, 2);

        return Inertia::render('Pos/Reports/Index', [
            'params' => ['from' => $from, 'to' => $to],
            'summary' => [
                'sales' => (int) $summary->sales,
                'subtotal' => round((float) $summary->subtotal, 2),
                'discount' => round((float) $summary->discount, 2),
                'tax' => round((float) $summary->tax, 2),
                'total' => $total,
                'returns' => round($returns, 2),
                'net' => round($total - $returns, 2),
                'average' => $summary->sales ? round($total / $summary->sales, 2) : 0.0,
            ],
            'byMethod' => $sales()->groupBy('s.payment_method')->orderByDesc('total')->selectRaw('s.payment_method as label, COUNT(*) as sales, SUM(d.total_amount) as total')->get(),
            'byCashier' => $sales()->leftJoin('users as u', 'u.id', '=', 's.cashier_id')->groupBy('s.cashier_id', 'u.name')->orderByDesc('total')
                ->selectRaw('COALESCE(u.name, \'-\') as label, COUNT(*) as sales, SUM(d.total_amount) as total')->get(),
            'daily' => $sales()->groupBy('d.doc_date')->orderBy('d.doc_date')->selectRaw('d.doc_date as label, COUNT(*) as sales, SUM(d.total_amount) as total')->get(),
            'topProducts' => $sales()->join('document_items as i', 'i.document_id', '=', 'd.id')->groupBy('i.product_id', 'i.name')
                ->orderByDesc('amount')->limit(10)->selectRaw('i.name as label, SUM(i.quantity) as quantity, SUM(i.total_amount) as amount')->get(),
        ]);
    }

    /** A valid Y-m-d date or the fallback. */
    private function date(?string $value, string $fallback): string
    {
        return $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : $fallback;
    }
}
