<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('module:assets'). A module listed in config('modules.disabled') is in
 * maintenance: its endpoints answer 503 and the rest of the application is unaffected.
 */
final class EnsureModuleAvailable
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (in_array($module, config('modules.disabled', []), true)) {
            $name = config("modules.names.{$module}", $module);
            throw new ApiException('MODULE_UNAVAILABLE', "Modul {$name} sedang dalam pemeliharaan. Menu lain tetap dapat digunakan.", 503, ['module' => $module]);
        }

        return $next($request);
    }
}
