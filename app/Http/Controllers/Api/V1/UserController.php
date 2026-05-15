<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = User::with(['hospital:id,code,name_th', 'department:id,code,name_th', 'roles:id,name'])
            ->where('hospital_id', $request->user()->hospital_id);

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('full_name', 'like', $term)
                  ->orWhere('email', 'like', $term)
                  ->orWhere('employee_code', 'like', $term);
            });
        }
        if ($request->filled('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $request->string('role')));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min($request->integer('per_page', 25), 100);

        return UserResource::collection($query->orderBy('full_name')->paginate($perPage));
    }

    public function show(User $user, Request $request): JsonResponse
    {
        abort_if($user->hospital_id !== $request->user()->hospital_id, 404);
        $user->load(['hospital:id,code,name_th', 'department:id,code,name_th', 'roles:id,name']);

        return response()->json([
            'user' => new UserResource($user),
            'roles' => $user->roles->pluck('name'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username'        => ['required', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')],
            'employee_code'   => ['nullable', 'string', 'max:64'],
            'full_name'       => ['required', 'string', 'max:255'],
            'name'            => ['nullable', 'string', 'max:255'],
            'email'           => ['required', 'email', 'unique:users,email'],
            'phone'           => ['nullable', 'string', 'max:32'],
            'password'        => ['required', 'string', 'min:6'],
            'department_id'   => ['nullable', 'integer', 'exists:departments,id'],
            'role'            => ['required', 'string', Rule::exists('roles', 'name')->where('guard_name', 'api')],
            'is_active'       => ['boolean'],
            'moph_client_key' => ['nullable', 'string', 'max:128'],
            'moph_secret_key' => ['nullable', 'string', 'max:128'],
        ]);

        $hospital = $request->user()->hospital;
        $user = User::create([
            'hospital_id'    => $hospital->id,
            'department_id'  => $data['department_id'] ?? null,
            'employee_code'  => $data['employee_code'] ?? null,
            'username'       => $data['username'],
            'full_name'      => $data['full_name'],
            'name'           => $data['name'] ?? $data['username'],
            'email'          => $data['email'],
            'phone'          => $data['phone'] ?? null,
            'password'       => Hash::make($data['password']),
            'is_active'      => $data['is_active'] ?? true,
            'email_verified_at' => now(),
            'moph_client_key' => $data['moph_client_key'] ?? null,
            'moph_secret_key' => $data['moph_secret_key'] ?? null,
        ]);

        // Assign roles for both web + api guards (since some lookups happen via web)
        $role = $data['role'];
        $user->syncRoles([Role::where(['name' => $role, 'guard_name' => 'api'])->first()]);
        if ($webRole = Role::where(['name' => $role, 'guard_name' => 'web'])->first()) {
            $user->assignRole($webRole);
        }

        $user->load(['hospital', 'department', 'roles']);

        return response()->json(new UserResource($user), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_if($user->hospital_id !== $request->user()->hospital_id, 404);

        $data = $request->validate([
            'username'        => ['sometimes', 'string', 'max:64', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->id)],
            'employee_code'   => ['nullable', 'string', 'max:64'],
            'full_name'       => ['sometimes', 'string', 'max:255'],
            'name'            => ['sometimes', 'string', 'max:255', Rule::unique('users', 'name')->ignore($user->id)],
            'email'           => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'           => ['nullable', 'string', 'max:32'],
            'department_id'   => ['nullable', 'integer', 'exists:departments,id'],
            'role'            => ['sometimes', 'string', Rule::exists('roles', 'name')->where('guard_name', 'api')],
            'is_active'       => ['sometimes', 'boolean'],
            'password'        => ['sometimes', 'nullable', 'string', 'min:6'],
            'moph_client_key' => ['nullable', 'string', 'max:128'],
            'moph_secret_key' => ['nullable', 'string', 'max:128'],
        ]);

        $payload = collect($data)->except(['password', 'role'])->toArray();
        if (! empty($data['password'])) {
            $payload['password'] = Hash::make($data['password']);
        }

        $user->update($payload);

        if (! empty($data['role'])) {
            $apiRole = Role::where(['name' => $data['role'], 'guard_name' => 'api'])->first();
            $user->syncRoles([$apiRole]);
            if ($webRole = Role::where(['name' => $data['role'], 'guard_name' => 'web'])->first()) {
                $user->assignRole($webRole);
            }
        }

        $user->load(['hospital', 'department', 'roles']);

        return response()->json(new UserResource($user));
    }

    public function destroy(User $user, Request $request): JsonResponse
    {
        abort_if($user->hospital_id !== $request->user()->hospital_id, 404);
        abort_if($user->id === $request->user()->id, 422, 'ไม่สามารถลบบัญชีตนเองได้');

        $user->delete();

        return response()->json(['message' => 'ลบผู้ใช้เรียบร้อย']);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        abort_if($user->hospital_id !== $request->user()->hospital_id, 404);

        $data = $request->validate([
            'password' => ['required', 'string', 'min:6'],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        return response()->json(['message' => 'รีเซ็ตรหัสผ่านเรียบร้อย']);
    }

    public function rolesList(): JsonResponse
    {
        return response()->json(
            Role::where('guard_name', 'api')
                ->orderBy('id')
                ->get(['id', 'name'])
        );
    }
}
