<?php

namespace Database\Seeders;

use App\Data\Materials\HsCodeConditionSet;
use App\Models\HsCodeRule;
use App\Models\MaterialAlias;
use App\Models\MaterialMaster;
use App\Services\Materials\MaterialCodeNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class MaterialHsCodeMasterSeeder extends Seeder
{
    public function run(): void
    {
        $materials = $this->fixture('material_masters.json');
        $rules = $this->fixture('hs_code_rules.json');

        if (count($materials) !== 84 || count($rules) !== 21) {
            throw new RuntimeException('Material/HS fixture count does not match the approved source audit.');
        }

        $normalizer = app(MaterialCodeNormalizer::class);
        $createdMaterials = 0;
        $skippedMaterials = 0;
        $createdRules = 0;
        $skippedRules = 0;

        DB::transaction(function () use (
            $materials,
            $rules,
            $normalizer,
            &$createdMaterials,
            &$skippedMaterials,
            &$createdRules,
            &$skippedRules,
        ) {
            foreach ($materials as $source) {
                $aliases = $source['aliases'] ?? [];
                unset($source['aliases']);

                $material = MaterialMaster::where('normalized_code', $source['normalized_code'])->first();
                if ($material === null) {
                    $material = MaterialMaster::create($source);
                    $createdMaterials++;
                } else {
                    $skippedMaterials++;
                }

                foreach ($aliases as $alias) {
                    $normalizedAlias = $normalizer->normalize($alias['alias'] ?? '');
                    if ($normalizedAlias === '' || MaterialMaster::where('normalized_code', $normalizedAlias)->exists()) {
                        continue;
                    }

                    MaterialAlias::firstOrCreate(
                        ['normalized_alias' => $normalizedAlias],
                        [
                            'material_master_id' => $material->id,
                            'alias' => $alias['alias'],
                            'source_note' => $alias['source_note'] ?? null,
                        ],
                    );
                }
            }

            foreach ($rules as $source) {
                HsCodeConditionSet::fromArray($source['conditions']);
                if (HsCodeRule::where('rule_key', $source['rule_key'])->exists()) {
                    $skippedRules++;

                    continue;
                }

                HsCodeRule::create($source);
                $createdRules++;
            }
        });

        $this->command?->info(
            "Material/HS master seeded: {$createdMaterials} materials created, {$skippedMaterials} preserved, "
            ."{$createdRules} rules created, {$skippedRules} preserved."
        );
    }

    private function fixture(string $file): array
    {
        $path = database_path('data/'.$file);
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid fixture: {$file}");
        }

        return $decoded;
    }
}
