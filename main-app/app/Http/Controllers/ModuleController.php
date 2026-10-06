<?php

namespace App\Http\Controllers;

use App\Classes\Module;
use App\Models\AddOn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Superadmin: add-on modules installed on the platform. */
class ModuleController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-add-ons')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $modules = new Module();
        $modules->sync(); // pick up modules dropped into packages/workdo

        return Inertia::render('AddOns/Index', [
            'addOns' => AddOn::orderBy('priority')->get()
                ->map(fn (AddOn $a) => $a->toArray() + ['version' => $modules->json($a->module)['version'] ?? null]),
        ]);
    }

    public function toggle(Request $request, string $module): RedirectResponse
    {
        if (!Auth::user()->can('edit-add-ons')) {
            return back()->with('error', __('Permission denied'));
        }

        $enabled = $request->validate(['is_enable' => 'required|boolean'])['is_enable'];

        if (!(new Module())->setEnabled($module, $enabled)) {
            return back()->with('error', __('Add-on not found.'));
        }

        return back()->with('success', $enabled ? __('Add-on enabled.') : __('Add-on disabled.'));
    }
}
