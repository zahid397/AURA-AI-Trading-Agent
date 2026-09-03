<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Brute-force login protection: keyed by email+IP so one attacker
        // can't lock out a legitimate user from a different IP, and vice
        // versa a single IP hammering many emails is still throttled.
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->string('email');

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        // Account-creation abuse protection, keyed by IP only (there's no
        // legitimate email to key on yet -- that's exactly what automated
        // sign-up abuse looks like). Tighter than login's limit since
        // creating an account is more consequential than a failed attempt.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip());
        });
    }
}
