<?php

namespace App\Http\Controllers;

use App\Events\CollectDashboardWidgets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $event = new CollectDashboardWidgets($request->user());
        event($event);

        return Inertia::render('Dashboard', ['widgets' => collect($event->widgets)->sortBy('order')->values()->all()]);
    }
}
