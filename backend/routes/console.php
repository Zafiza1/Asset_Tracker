<?php

use App\Domain\Identity\Services\OrganizationInvitations;
use App\Domain\ReportDesigner\Services\ReportExportService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('invitations:expire', function (OrganizationInvitations $invitations) {
    $this->info("{$invitations->expireStale()} undangan ditandai kedaluwarsa.");
})->purpose('Mark pending organization invitations past their expiry as expired');

Schedule::command('invitations:expire')->hourly();

Artisan::command('reports:expire', function (ReportExportService $exports) {
    $this->info("{$exports->expireFiles()} file laporan ditandai kedaluwarsa.");
})->purpose('Delete report export files past their expiry (F-05, D-14) and mark them expired');

Schedule::command('reports:expire')->daily();
