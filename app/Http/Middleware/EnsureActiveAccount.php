<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($current = $request->user()) {
            $user = $current->fresh();
            // A remembered login is valid only while its rotated remember token remains valid.
            if ($user && $user->is_active && Auth::viaRemember() && ! $request->session()->has('auth_version')) {
                $request->session()->put('auth_version', $user->auth_version);
            }
            if (! $user || ! $user->is_active || (int) $request->session()->get('auth_version', 0) !== $user->auth_version) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                if ($request->expectsJson()) {
                    return response()->json(['message' => 'Your session has ended. Please sign in again.'], 401);
                }

                return redirect('/login')->withErrors(['email' => 'Your session has ended. Please sign in again.']);
            }
            Auth::setUser($user);
        }

        return $next($request);
    }
}
