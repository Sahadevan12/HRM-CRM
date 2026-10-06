<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private const SUB_USER_TYPES = ['staff', 'client', 'vendor'];

    public function index(Request $request): Response|RedirectResponse
    {
        if (!Auth::user()->can('manage-users')) {
            return redirect()->route('dashboard')->with('error', __('Permission denied'));
        }

        $query = User::with('roles:id,name,label')
            ->where('created_by', creatorId())
            ->where('id', '!=', Auth::id());

        if ($request->filled('search')) {
            $search = $request->get('search');
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        if ($request->filled('type') && in_array($request->type, self::SUB_USER_TYPES, true)) {
            $query->where('type', $request->type);
        }

        $sortField = in_array($request->get('sort'), ['name', 'email', 'created_at'], true) ? $request->get('sort') : 'created_at';
        $sortDirection = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        return Inertia::render('Users/Index', [
            'users' => $query->orderBy($sortField, $sortDirection)
                ->paginate((int) $request->get('per_page', 10))
                ->withQueryString(),
            'roles' => $this->assignableRoles(),
            'filters' => $request->only(['search', 'type', 'sort', 'direction', 'per_page']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (!Auth::user()->can('create-users')) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users,email',
            'mobile_no' => 'nullable|string|max:20',
            'password' => ['required', Password::defaults()],
            'type' => ['required', Rule::in(self::SUB_USER_TYPES)],
            'role' => ['required', 'string'],
        ]);

        $role = $this->findAssignableRole($validated['role']);
        if (!$role) {
            return back()->with('error', __('Invalid role'));
        }

        $limit = $this->canCreateUser();
        if (!$limit['can_create']) {
            return back()->with('error', $limit['message']);
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'mobile_no' => $validated['mobile_no'] ?? null,
            'password' => $validated['password'],
            'type' => $validated['type'],
            'email_verified_at' => now(),
            'creator_id' => Auth::id(),
            'created_by' => creatorId(),
        ]);
        $user->assignRole($role);

        return back()->with('success', __('The user has been created successfully.'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if (!Auth::user()->can('edit-users') || $user->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'mobile_no' => 'nullable|string|max:20',
            'type' => ['required', Rule::in(self::SUB_USER_TYPES)],
            'role' => ['required', 'string'],
            'is_enable_login' => 'boolean',
        ]);

        $role = $this->findAssignableRole($validated['role']);
        if (!$role) {
            return back()->with('error', __('Invalid role'));
        }

        $user->update(collect($validated)->except('role')->all());
        $user->syncRoles([$role]);

        return back()->with('success', __('The user details are updated successfully.'));
    }

    public function changePassword(Request $request, User $user): RedirectResponse
    {
        if (!Auth::user()->can('change-password-users') || $user->created_by !== creatorId()) {
            return back()->with('error', __('Permission denied'));
        }

        $validated = $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);
        $user->update(['password' => $validated['password']]);

        return back()->with('success', __('Password successfully updated.'));
    }

    public function destroy(User $user): RedirectResponse
    {
        if (!Auth::user()->can('delete-users') || $user->created_by !== creatorId() || $user->id === Auth::id()) {
            return back()->with('error', __('Permission denied'));
        }

        $user->delete();

        return back()->with('success', __('The user has been deleted.'));
    }

    /** Roles a company may hand out: its own custom roles + system sub-user roles. */
    private function assignableRoles()
    {
        return Role::where(function ($q) {
            $q->where('created_by', creatorId())
                ->orWhere(fn ($s) => $s->whereNull('created_by')->whereIn('name', self::SUB_USER_TYPES));
        })->orderBy('label')->get(['id', 'name', 'label', 'guard_name']);
    }

    private function findAssignableRole(string $name): ?Role
    {
        return $this->assignableRoles()->firstWhere('name', $name);
    }

    /** Plan user-limit check (total_user: -1 = unlimited, 0 = none). */
    private function canCreateUser(): array
    {
        $company = Auth::user()->isCompany() || Auth::user()->isSuperadmin() ? Auth::user() : Auth::user()->createdBy;
        $limit = (int) ($company->total_user ?? 0);

        if ($limit === -1) {
            return ['can_create' => true, 'message' => ''];
        }

        $current = User::where('created_by', $company->id)->count();

        return $current < $limit
            ? ['can_create' => true, 'message' => '']
            : ['can_create' => false, 'message' => __('User limit reached for your plan.')];
    }
}
