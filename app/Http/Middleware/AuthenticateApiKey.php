<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        abort_unless($token && strlen($token) <= 150, 401, 'Provide a valid API key as a Bearer token.');
        $key = DB::table('api_keys')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        abort_unless($key && (! $key->expires_at || now()->lt($key->expires_at)) && DB::table('users')->where('id', $key->user_id)->where('role', 'admin')->exists(), 401, 'The API key is invalid, expired, or revoked.');
        $bucket = 'external-api:'.$key->id;
        abort_if(RateLimiter::tooManyAttempts($bucket, 60), 429, 'API limit reached. Retry in one minute.');
        RateLimiter::hit($bucket, 60);
        $key->scopes = json_decode($key->scopes, true);
        $request->attributes->set('api_key', $key);
        DB::table('api_keys')->where('id', $key->id)->update(['last_used_at' => now()]);

        return $next($request);
    }
}
