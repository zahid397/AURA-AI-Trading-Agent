<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\RunMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Models\UserSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sanctum SPA (cookie/session) authentication. The frontend is expected to
 * hit GET /sanctum/csrf-cookie first (registered by Sanctum's own service
 * provider) so the CSRF cookie is set before this stateful POST.
 */
class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                // The User model's `password` cast is 'hashed', so this is
                // hashed on save -- never call Hash::make() on top of it,
                // that would double-hash.
                'password' => $validated['password'],
                // No outbound mail is configured in this build (see
                // docs/IMPLEMENTATION_STATUS.md), so there is no real
                // verification link to send. Auto-verifying here is honest
                // about that rather than leaving the account in a permanent
                // "unverified" state nothing ever checks.
                'email_verified_at' => now(),
            ]);

            // Every new account starts in approval_required -- the same
            // safe default the demo seeder uses. Nothing about registering
            // grants autonomous trading; that's an explicit, separate,
            // confirmed opt-in (see Settings > Agent Controls).
            UserSettings::query()->create([
                'user_id' => $user->id,
                'mode' => RunMode::ApprovalRequired,
                'confidence_threshold' => '0.6500',
                'notification_prefs' => ['email' => true, 'in_app' => true, 'risk_alerts' => true],
            ]);

            return $user;
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        // ->fresh() rather than reusing the in-memory $user from create():
        // timezone/currency_display/default_page are DB column defaults
        // (see the users migration), not attributes this method sets, so
        // the just-created instance doesn't know their values yet -- only
        // the row does. Without this, the register response itself would
        // show null for all three (confirmed against the live API) even
        // though every subsequent /me call already returns them correctly.
        $user = $user->fresh('settings');

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::guard('web')->attempt($credentials, true)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();
        $user->load('settings');

        return UserResource::make($user)->response();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['logged_out' => true]]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('settings');

        return UserResource::make($user)->response();
    }
}
