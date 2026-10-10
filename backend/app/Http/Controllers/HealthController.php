<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class HealthController extends Controller
{
    /** Liveness: the PHP process answers. */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: dependencies needed to serve traffic. Details stay generic on purpose. */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('SELECT 1')),
            'storage' => $this->check(function () {
                $disk = Storage::disk('local');
                $disk->put('.health', (string) time());
                $disk->delete('.health');
            }),
            'queue' => $this->check(fn () => DB::table('jobs')->limit(1)->count()),
        ];
        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => array_map(fn (bool $c) => $c ? 'ok' : 'fail', $checks),
        ], $ok ? 200 : 503);
    }

    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
