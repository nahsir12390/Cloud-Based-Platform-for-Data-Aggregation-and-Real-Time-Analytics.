<?php

namespace App\Http\Middleware;

use App\Models\ApiSource;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSource
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless($token && preg_match('/^bf_[a-f0-9]{64}$/D', $token), 401, 'A valid branch API key is required.');
        $source = ApiSource::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->whereHas('user', fn ($q) => $q->where('is_active', true)->where('role', 'owner'))->first();
        abort_unless($source, 401, 'A valid branch API key is required.');
        abort_if(strlen($request->getContent()) > 16384, 413, 'Request body exceeds 16 KB.');
        $request->attributes->set('api_source', $source);
        $key = 'ingestion:source:'.$source->id;
        if (RateLimiter::tooManyAttempts($key, 120)) {
            return response()->json(['message' => 'Source rate limit exceeded.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key))->header('Cache-Control', 'no-store');
        }
        RateLimiter::hit($key, 60);

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
