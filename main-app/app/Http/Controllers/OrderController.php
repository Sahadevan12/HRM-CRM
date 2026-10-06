<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class OrderController extends Controller
{
    /** Superadmin sees every order, a company only its own. */
    public function index(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();

        if (!$user->can('manage-orders')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $orders = Order::with('user:id,name,email')
            ->when(!$user->isSuperadmin(), fn ($q) => $q->where('user_id', companyOf($user)->id))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($s) use ($request) {
                $term = '%' . $request->get('search') . '%';
                $s->where('order_number', 'like', $term)->orWhere('plan_name', 'like', $term);
            }))
            ->latest()
            ->paginate((int) $request->get('per_page', 10))
            ->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $request->only('search'),
            'showCompany' => $user->isSuperadmin(),
        ]);
    }
}
