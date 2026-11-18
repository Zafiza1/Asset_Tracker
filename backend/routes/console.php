<?php

use App\Domain\AssetTransactions\Flows\FlowEngine;
use App\Domain\AssetTransactions\Flows\FlowVersionService;
use App\Domain\AssetTransactions\Services\TransactionAttachments;
use App\Domain\Identity\Services\OrganizationInvitations;
use App\Domain\ReportDesigner\Services\ReportExportService;
use App\Domain\Shared\Tenancy\Tenancy;
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

Artisan::command('transactions:resume', function (Tenancy $tenancy, FlowEngine $engine) {
    $this->info(FlowVersionService::resumeWaiting($tenancy, $engine).' transaksi berjalan melanjutkan langkahnya.');
})->purpose('Move running flow transactions on when their wait step date has come (F-07b)');

Schedule::command('transactions:resume')->daily();

Artisan::command('transactions:prune-attachments', function (Tenancy $tenancy) {
    $this->info(TransactionAttachments::prune($tenancy).' lampiran yang tidak dipakai dihapus.');
})->purpose('Delete attachment uploads never used in a transaction (F-07b)');

Schedule::command('transactions:prune-attachments')->daily();
