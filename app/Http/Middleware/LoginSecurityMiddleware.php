<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Support\Google2FAAuthenticator;

class LoginSecurityMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $authenticator = app(Google2FAAuthenticator::class)->boot($request);

        if ($authenticator->isAuthenticated()) {
            return $next($request);
        }
		return response()->json([
			'error' => [
				'google_auth' => false,
				'message' => 'Google 2FA authentication was not successful'
			]
		]);
        //return $authenticator->makeRequestOneTimePasswordResponse();
    }
}
