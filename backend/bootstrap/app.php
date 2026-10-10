<?php

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolvePlatform;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Session-cookie auth for the same-origin SPA (Sanctum stateful).
        $middleware->statefulApi();
        $middleware->throttleApi('api');
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform' => ResolvePlatform::class,
            'permission' => RequirePermission::class,
        ]);

        // Context and permission checks must run before route model binding, so bindings are
        // tenant-scoped and an unauthorized caller gets 403 without learning whether an id exists.
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolvePlatform::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequirePermission::class);

        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(new ApiExceptionRenderer);
        $exceptions->dontReport([ApiException::class]);
    })->create();
