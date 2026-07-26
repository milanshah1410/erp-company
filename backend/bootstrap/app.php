<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Tenant (business) API routes. Self-contained group in tenant.php
            // (api middleware + /api prefix), loaded here since the stancl
            // TenancyServiceProvider that used to map it has been removed.
            require base_path('routes/tenant.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every API response (validation errors included) follows the
        // caller's language — the frontend sends X-Locale: en|hi|gu.
        $middleware->api(prepend: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            // SaaS multi-tenancy
            'resolve.tenant' => \App\Http\Middleware\ResolveTenant::class,
            // SaaS-owner (platform operator) context — pins Spatie team to NULL.
            'central.context' => \App\Http\Middleware\ResolveCentralContext::class,
            'plan.limit' => \App\Http\Middleware\EnforcePlanLimit::class,
            'tenant.active' => \App\Http\Middleware\EnsureTenantActive::class,
            // B2B customer portal (SRS §14): the tokenable must be a Customer.
            'portal' => \App\Http\Middleware\EnsurePortalCustomer::class,
        ]);

        // Tenant context must be established BEFORE SubstituteBindings resolves
        // route-model bindings — otherwise the implicit-binding query for a
        // tenant-scoped model (e.g. {bom}, {product}) runs with no tenant set
        // and TenantScope fails closed. Priority-order our tenant middleware
        // ahead of binding substitution (ResolveTenant first, then the active
        // check which reads the tenant it stashes).
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\ResolveTenant::class,
        );
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\EnsureTenantActive::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
