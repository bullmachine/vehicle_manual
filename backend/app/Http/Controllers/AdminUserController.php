<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(User::query()->with('role')->orderBy('name')->paginate(30));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'username' => ['nullable', 'string', 'max:80', 'unique:users,username'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
        ]);
        $this->guardRoleChange($request, null, (int) $validated['role_id']);
        $user = User::create($validated + ['is_active' => true]);
        $this->audit->record('created', 'admin_users', User::class, $user->id, [], Arr::except($validated, ['password', 'password_confirmation']));

        return response()->json(['data' => $user->load('role')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::query()->findOrFail($id);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'username' => ['sometimes', 'nullable', 'string', 'max:80', Rule::unique('users', 'username')->ignore($user->id)],
            'password' => ['sometimes', 'string', 'min:12', 'confirmed'],
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $this->guardRoleChange($request, $user, isset($validated['role_id']) ? (int) $validated['role_id'] : null, $validated['is_active'] ?? null);
        $old = $user->only(['name', 'email', 'username', 'role_id', 'is_active']);
        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }
        $user->update($validated);
        $logged = Arr::except($validated, ['password', 'password_confirmation']) + (isset($validated['password']) ? ['password' => 'changed'] : []);
        $this->audit->record('updated', 'admin_users', User::class, $user->id, $old, $logged);

        return response()->json(['data' => $user->fresh('role')]);
    }

    /**
     * Stop privilege escalation: only a super admin may grant the super admin role or
     * edit a super admin, and nobody may change their own role or deactivate themselves.
     */
    private function guardRoleChange(Request $request, ?User $target, ?int $roleId, ?bool $isActive = null): void
    {
        $actor = $request->user();
        $actorIsSuper = $actor->role?->slug === 'super_admin';
        $grantsSuper = $roleId !== null && Role::query()->whereKey($roleId)->value('slug') === 'super_admin';

        if (! $actorIsSuper && ($grantsSuper || $target?->role?->slug === 'super_admin')) {
            abort(403, 'Only a super admin can assign the super admin role or change a super admin account.');
        }

        if ($target && $target->is($actor) && (($roleId !== null && $roleId !== (int) $actor->role_id) || $isActive === false)) {
            throw ValidationException::withMessages(['role_id' => 'You cannot change your own role or deactivate your own account.']);
        }
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->with('permissions')->orderBy('name')->get(), 'permissions' => Permission::query()->orderBy('name')->get()]);
    }
}
