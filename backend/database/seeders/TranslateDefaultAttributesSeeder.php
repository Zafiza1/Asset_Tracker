<?php

namespace Database\Seeders;

use App\Domain\Shared\Tenancy\Tenancy;
use App\Domain\TenantMaster\Models\TenantCategoryAttribute;
use Illuminate\Database\Seeder;

/**
 * Renames the English default attributes of existing categories to Indonesian. Only labels
 * change: keys and option values stay, so stored asset values keep their meaning.
 */
class TranslateDefaultAttributesSeeder extends Seeder
{
    private const LABELS = [
        'Brand' => 'Merek',
        'UOM' => 'Satuan',
        'Owner' => 'Pemilik',
        'Responsible Person' => 'Penanggung Jawab',
        'Operating Hours' => 'Jam Operasi',
        'Condition' => 'Kondisi',
    ];

    private const OPTION_LABELS = [
        'GOOD' => 'Baik',
        'FAIR' => 'Cukup',
        'POOR' => 'Buruk',
        'DAMAGED' => 'Rusak',
    ];

    /**
     * Run the database seeds.
     */
    public function run(Tenancy $tenancy): void
    {
        $this->command->info('Translating default attributes to Indonesian...');

        // Run in system context to bypass tenant scope and RLS
        $tenancy->runAsSystem(function () {
            $updated = 0;
            $attributes = TenantCategoryAttribute::query()
                ->where('is_default', true)
                ->whereIn('label', array_keys(self::LABELS))
                ->get();

            foreach ($attributes as $attribute) {
                $attribute->label = self::LABELS[$attribute->label];
                if ($attribute->options !== null) {
                    $attribute->options = array_map(
                        fn (array $o) => ['value' => $o['value'], 'label' => self::OPTION_LABELS[$o['value']] ?? $o['label']],
                        $attribute->options,
                    );
                }
                $attribute->save();
                $updated++;
            }

            $this->command->line("  Translated {$updated} default attributes");
        });

        $this->command->info('Translation completed successfully.');
    }
}
