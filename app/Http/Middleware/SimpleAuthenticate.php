<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Request;

class SimpleAuthenticate
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
    	$sign = Request::header('Sign');
    	$app_id = Request::header('App');
    	if (!$sign) {
    		throw  new AuthorizationException('Unauthorized.', 401);
    	} else {
    		$origin_app_id = simple_decrypt(hex2bin($sign), config('api.api_key'));
    		if (utf8_encode($app_id) == utf8_encode($origin_app_id)) {
    			throw new AuthorizationException('Unauthorized.', 401);
    		}
    	}
        return $next($request);
    }
}
