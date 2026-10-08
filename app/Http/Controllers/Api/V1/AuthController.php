<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Register a new user and generate an API token.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
        ]);

        $tokenName = $validated['device_name'] ?? 'mailforge-api-token';
        $token = $user->createToken($tokenName)->plainTextToken;

        $userResource = new UserResource($user);

        return response()->json([
            'success' => true,
            'message' => 'Registration successful',
            'data' => [
                'user' => $userResource,
                'token' => $token,
            ],
            // Backwards compatibility
            'user' => $userResource,
            'token' => $token,
        ], 201);
    }

    /**
     * Authenticate a user and issue an API token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', strtolower($validated['email']))->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'The provided credentials do not match our records.',
            ], 401);
        }

        $tokenName = $validated['device_name'] ?? 'mailforge-api-token';
        $token = $user->createToken($tokenName)->plainTextToken;

        $userResource = new UserResource($user);

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $userResource,
                'token' => $token,
            ],
            // Backwards compatibility
            'user' => $userResource,
            'token' => $token,
        ]);
    }

    /**
     * Revoke the current access token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Successfully logged out',
        ]);
    }

    /**
     * Return the authenticated user profile.
     */
    public function user(Request $request): JsonResponse
    {
        $userResource = new UserResource($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Authenticated user profile retrieved successfully.',
            'data' => [
                'user' => $userResource,
            ],
            // Backwards compatibility
            'user' => $userResource,
        ]);
    }
}
