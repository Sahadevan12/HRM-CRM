<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-roles')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = Role::with('permissions:id,name')
            ->withCount('users')
            ->where('created_by', creatorId());

        if ($request->filled('search')) {
            $query->where('label', 'like', '%' . $request->get('search') . '%');
        }

        return Inertia::render('Roles/Index', [
            'roles' => $query->orderBy('label')->paginate((int) $request->get('per_page', 10))->withQueryString(),
            // Permission matrix grouped by module; a company can only hand out what its own role holds.
            'permissionGroups' => $this->grantablePermissions()->groupBy('module')->map->values(),
            'filters' => $request->only(['search', 'per_page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-roles')) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'permissions' => 'array',
            'permissions.*' => 'string',
        ]);

        // name is globally unique (spatie) => suffix with tenant id
        $role = Role::create([
            'name' => Str::slug($validated['label']) . '-' . creatorId(),
            'guard_name' => 'web',
            'label' => $validated['label'],
            'description' => $validated['description'] ?? null,
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);
        $role->syncPermissions($this->onlyGrantable($validated['permissions'] ?? []));

        return back()->with('success', __('The role has been created successfully.'));
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        if (!Auth::user()->can('edit-roles') || $role->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'label' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'permissions' => 'array',
            'permissions.*' => 'string',
        ]);

        $role->update(['label' => $validated['label'], 'description' => $validated['description'] ?? null]);
        $role->syncPermissions($this->onlyGrantable($validated['permissions'] ?? []));

        return back()->with('success', __('The role details are updated successfully.'));
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (!Auth::user()->can('delete-roles') || $role->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        if ($role->users()->exists()) {
            return back()->with('error', __('This role is assigned to users and cannot be deleted.'));
        }

        $role->delete();

        return back()->with('success', __('The role has been deleted.'));
    }

    private function grantablePermissions()
    {
        $user = Auth::user();

        $permissions = $user->isSuperadmin()
            ? Permission::query()
            : Role::findByName('company')->permissions()->getQuery();

        return $permissions->orderBy('module')->orderBy('id')->get(['id', 'name', 'module', 'label']);
    }

    private function onlyGrantable(array $names): array
    {
        return $this->grantablePermissions()->pluck('name')->intersect($names)->values()->all();
    }
}
