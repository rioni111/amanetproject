<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Session-based (cookie) authentication for the first-party Next.js SPA via Sanctum.
 * The browser first calls GET /sanctum/csrf-cookie, then POSTs credentials with X-XSRF-TOKEN.
 */
class AuthController extends Controller
{
    public function login(LoginRequest $request): UserResource
    {
        abort_unless(
            $request->hasSession(),
            400,
            'Session authentication is only available to the Lexora web application.'
        );

        $request->authenticate();

        // Prevent session fixation.
        $request->session()->regenerate();

        return new UserResource($request->user());
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
