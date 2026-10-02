<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Listeners are declared explicitly in App\Providers\EventServiceProvider;
    // discovery would attach some of them a second time (double audit rows).
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Middleware\TenantMiddleware::class,
            'permission' => \App\Middleware\PermissionMiddleware::class,
            'role' => \App\Middleware\RoleMiddleware::class,
            'module' => \App\Middleware\EnsureModuleEnabled::class,
            'control-plane' => \App\Middleware\ControlPlaneMiddleware::class,
            'machine' => \App\Middleware\MachineOrUserMiddleware::class,
        ]);

        // TenantMiddleware must run before SubstituteBindings: route-model
        // binding on tenant-scoped models (e.g. Asset by system_id) relies on
        // the TenantScope global scope, which only filters once TenantContext
        // has been populated. Without this, a cross-tenant id resolves to the
        // wrong model at binding time and is only caught by the controller's
        // explicit policy check (403) instead of a clean 404 (see
        // docs/architecture/tenancy.md).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Middleware\TenantMiddleware::class,
        );
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Middleware\ControlPlaneMiddleware::class,
        );
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Middleware\MachineOrUserMiddleware::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every API error uses the standard envelope (Section 53):
        // {"success": false, "message": "...", "errors": {...}}.
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], $e->status);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            if ($isApi($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if (!$isApi($request)) {
                return null;
            }

            // Don't leak model class names from route-model binding misses.
            $message = $e->getPrevious() instanceof ModelNotFoundException || $e->getMessage() === ''
                ? (Response::$statusTexts[$e->getStatusCode()] ?? 'Error')
                : $e->getMessage();

            return response()->json(['success' => false, 'message' => $message], $e->getStatusCode(), $e->getHeaders());
        });
    })->create();
