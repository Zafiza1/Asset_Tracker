<?php

namespace App\Listeners;

use App\Services\AuditService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LogAuthenticationActivity implements ShouldQueue
{
    use InteractsWithQueue;

    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    public function handle(Login|Logout|Failed $event): void
    {
        if ($event instanceof Login) {
            $this->auditService->logLoginSuccess($event->user->id);
        } elseif ($event instanceof Logout) {
            $this->auditService->logLogout($event->user->id);
        } elseif ($event instanceof Failed) {
            $this->auditService->logLoginFailed(
                $event->credentials['email'] ?? 'unknown',
                request()->ip()
            );
        }
    }
}
