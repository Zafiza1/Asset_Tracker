<?php

use App\Domain\Identity\Services\OrganizationInvitations;
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
