<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\LaraException;
use Closure;
use Illuminate\Http\Request;
use Lauthz\Facades\Enforcer;

class CasbinPepMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     *
     * @throws LaraException
     */
    public function handle(Request $request, Closure $next)
    {
        $sub = (string) $request->user()?->getAuthIdentifier();
        $obj = '/' . ltrim($request->path(), '/');
        $act = strtoupper($request->method());

        // In a real environment, Enforcer facade from laravel-authz is used.
        // If it throws an exception because authz isn't installed yet, we'll
        // let it surface so CI can catch it.
        $allowed = Enforcer::enforce($sub, $obj, $act);

        if ($allowed === false) {
            // Log the denied decision at WARNING
            \Illuminate\Support\Facades\Log::channel('lara-diag')->warning('casbin.enforce', [
                'RequestId' => $request->attributes->get('RequestId'),
                'sub' => $sub,
                'obj' => $obj,
                'act' => $act,
                'allowed' => false,
            ]);

            throw LaraException::forbidden('Rbac.Denied', [
                'sub' => $sub,
                'obj' => $obj,
                'act' => $act,
            ]);
        }

        // Log the allowed decision at DEBUG
        \Illuminate\Support\Facades\Log::channel('lara-diag')->debug('casbin.enforce', [
            'RequestId' => $request->attributes->get('RequestId'),
            'sub' => $sub,
            'obj' => $obj,
            'act' => $act,
            'allowed' => true,
        ]);

        return $next($request);
    }
}
