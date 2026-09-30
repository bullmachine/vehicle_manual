<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

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
        $user = User::create($validated + ['is_active' => true]);
        $this->audit->record('created', 'admin_users', User::class, $user->id, [], $validated);

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
        $old = $user->only(['name', 'email', 'username', 'role_id', 'is_active']);
        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }
        $user->update($validated);
        $this->audit->record('updated', 'admin_users', User::class, $user->id, $old, $validated);

        return response()->json(['data' => $user->fresh('role')]);
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->with('permissions')->orderBy('name')->get(), 'permissions' => Permission::query()->orderBy('name')->get()]);
    }
}
