<?php

namespace App\Http\Middleware;

use App\Models\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = $request->user('sanctum');

        if (! $device instanceof Device || ! $device->tokenCan(Device::TOKEN_ABILITY)) {
            abort(403, 'A device token is required.');
        }

        return $next($request);
    }
}
