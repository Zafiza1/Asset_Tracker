<?php

namespace App\Console\Commands;

use App\Domain\Assets\Models\Asset;
use App\Domain\Internal\AssetInspector;
use App\Domain\Organization\Models\Organization;
use App\Domain\Shared\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Developer tool for the internal ("generic") data behind assets: internal ids, technical
 * references, the transaction chain, and a replay consistency check.
 *
 *   php artisan asset:inspect AST-2026-000001 --org=MAJU
 *   php artisan asset:inspect 0199...-uuid
 *   php artisan asset:inspect --org=MAJU --verify-all
 */
class InspectAsset extends Command
{
    protected $signature = 'asset:inspect
        {asset? : Asset id (UUID) or asset number (requires --org)}
        {--org= : Organization code}
        {--verify-all : Verify the history of every asset of the organization}';

    protected $description = 'Inspect the internal identity, references, and history consistency of assets (developers only)';

    public function handle(Tenancy $tenancy, AssetInspector $inspector): int
    {
        return $tenancy->runAsSystem(function () use ($inspector) {
            $organization = $this->option('org') ? Organization::query()->where('code', strtoupper((string) $this->option('org')))->first() : null;
            if ($this->option('org') && $organization === null) {
                $this->error('Organization not found.');

                return self::FAILURE;
            }

            if ($this->option('verify-all')) {
                return $this->verifyAll($inspector, $organization);
            }

            $key = (string) $this->argument('asset');
            $asset = Str::isUuid($key)
                ? Asset::query()->find($key)
                : ($organization ? Asset::query()->where('organization_id', $organization->id)->where('asset_number', $key)->first() : null);
            if ($asset === null) {
                $this->error($organization || Str::isUuid($key) ? 'Asset not found.' : 'Pass an asset UUID, or an asset number together with --org.');

                return self::FAILURE;
            }

            $report = $inspector->inspect($asset);
            foreach (['identity', 'references'] as $section) {
                $this->info(ucfirst($section));
                $this->table(['Field', 'Value'], collect($report[$section])->map(fn ($v, $k) => [$k, $v ?? '—'])->values()->all());
            }
            $this->info('Transactions');
            $this->table(['Number', 'Type', 'Date', 'Recorded at', 'Id'], $report['transactions']);
            $this->line("Audit entries: {$report['audit_entries']}");

            return $this->reportProblems($report['problems']);
        });
    }

    private function verifyAll(AssetInspector $inspector, ?Organization $organization): int
    {
        if ($organization === null) {
            $this->error('--verify-all requires --org.');

            return self::FAILURE;
        }

        $checked = 0;
        $failed = 0;
        Asset::query()->where('organization_id', $organization->id)->orderBy('asset_number')
            ->chunk(200, function ($assets) use ($inspector, &$checked, &$failed) {
                foreach ($assets as $asset) {
                    $checked++;
                    foreach ($inspector->verify($asset) as $problem) {
                        $failed++;
                        $this->warn("{$asset->asset_number} ({$asset->id}): {$problem}");
                    }
                }
            });
        $this->line("{$checked} assets checked, {$failed} problems.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @param list<string> $problems */
    private function reportProblems(array $problems): int
    {
        if ($problems === []) {
            $this->info('History is consistent with the current asset state.');

            return self::SUCCESS;
        }
        foreach ($problems as $problem) {
            $this->warn($problem);
        }

        return self::FAILURE;
    }
}
