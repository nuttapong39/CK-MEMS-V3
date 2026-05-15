<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string', 'min:4'],
        ]);

        // Primary: login by dedicated username column
        $token = Auth::guard('api')->attempt(['username' => $data['username'], 'password' => $data['password']]);

        // Fallback 1: old `name` field (for accounts created before username column existed)
        if (! $token) {
            $token = Auth::guard('api')->attempt(['name' => $data['username'], 'password' => $data['password']]);
        }

        // Fallback 2: email (for users who type their email address)
        if (! $token && filter_var($data['username'], FILTER_VALIDATE_EMAIL)) {
            $token = Auth::guard('api')->attempt(['email' => $data['username'], 'password' => $data['password']]);
        }

        if (! $token) {
            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::guard('api')->user();
        if (! $user->is_active) {
            Auth::guard('api')->logout();
            throw ValidationException::withMessages([
                'username' => 'บัญชีนี้ถูกระงับการใช้งาน',
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return $this->respondWithToken($token, $user);
    }

    public function refresh(): JsonResponse
    {
        $token = Auth::guard('api')->refresh();
        $user = Auth::guard('api')->user();

        return $this->respondWithToken($token, $user);
    }

    public function logout(): JsonResponse
    {
        Auth::guard('api')->logout();

        return response()->json(['message' => 'logged out']);
    }

    public function me(): JsonResponse
    {
        $user = Auth::guard('api')->user()
            ->load(['hospital:id,code,name_th', 'department:id,code,name_th']);

        return response()->json([
            'user' => new UserResource($user),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }

    /** Step 1: verify emergency code (no auth) */
    public function emergencyVerify(Request $request): JsonResponse
    {
        $data = $request->validate(['emergency_code' => ['required', 'string']]);

        $valid = env('EMERGENCY_RESET_CODE', 'CK-MEMS-RESET-2024');
        if ($data['emergency_code'] !== $valid) {
            return response()->json(['message' => 'รหัสฉุกเฉินไม่ถูกต้อง'], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Step 2: reset admin password (no auth — guarded by emergency code) */
    public function emergencyReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'emergency_code' => ['required', 'string'],
            'new_password'   => ['required', 'string', 'min:6'],
        ]);

        $valid = env('EMERGENCY_RESET_CODE', 'CK-MEMS-RESET-2024');
        if ($data['emergency_code'] !== $valid) {
            return response()->json(['message' => 'รหัสฉุกเฉินไม่ถูกต้อง'], 422);
        }

        // Reset the first admin user
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))
            ->orderBy('id')
            ->firstOrFail();

        $admin->forceFill(['password' => bcrypt($data['new_password'])])->save();

        return response()->json(['message' => 'รีเซ็ตรหัสผ่านสำเร็จ', 'username' => $admin->username ?? $admin->name]);
    }

    private function respondWithToken(string $token, User $user): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => Auth::guard('api')->factory()->getTTL() * 60,
            'user' => new UserResource($user->load(['hospital:id,code,name_th', 'department:id,code,name_th'])),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]);
    }
}
