<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Login user and return token
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        try {
            $result = $this->authService->login(
                $request->email,
                $request->password,
                $request->device_name
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Login successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Register new user
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'phone' => 'nullable|string|max:20',
        ]);

        try {
            // Only these fields: public registration must never let the caller
            // pick roles (AuthService::register honours role_slug for internal
            // callers). Access comes from organization/project membership.
            $result = $this->authService->register($request->only(['name', 'email', 'password', 'phone']));

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Registration successful',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Logout user (revoke current token)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $this->authService->logout($request->user());

            return response()->json([
                'success' => true,
                'message' => 'Logout successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Logout user from all devices
     */
    public function logoutAll(Request $request): JsonResponse
    {
        try {
            $this->authService->logoutAll($request->user());

            return response()->json([
                'success' => true,
                'message' => 'Logged out from all devices',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $result = $this->authService->refreshToken(
                $request->user(),
                $request->input('device_name')
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Token refreshed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get current authenticated user
     */
    public function me(Request $request): JsonResponse
    {
        try {
            $organizationId = $request->header('X-Organization-Id') 
                ?? $request->query('organization_id');
            $projectId = $request->header('X-Project-Id') 
                ?? $request->query('project_id');

            $data = $this->authService->getCurrentUser(
                $request->user(),
                $organizationId ? (int)$organizationId : null,
                $projectId ? (int)$projectId : null
            );

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Switch organization context
     */
    public function switchOrganization(Request $request): JsonResponse
    {
        $request->validate([
            'organization_id' => 'required|integer|exists:organizations,id',
        ]);

        try {
            $data = $this->authService->switchOrganization(
                $request->user(),
                $request->organization_id
            );

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Organization context switched',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }
    }

    /**
     * Switch project context
     */
    public function switchProject(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
        ]);

        try {
            $data = $this->authService->switchProject(
                $request->user(),
                $request->project_id
            );

            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => 'Project context switched',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        }
    }
}
