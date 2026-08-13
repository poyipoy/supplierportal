<?php

namespace App\Services\Materials;

use App\Models\MaterialMaster;
use Illuminate\Support\Collection;

final class MaterialResolver
{
    public function __construct(private readonly MaterialCodeNormalizer $normalizer) {}

    public function resolveById(int $id, bool $activeOnly = true): ?MaterialMaster
    {
        $query = MaterialMaster::with('aliases')->whereKey($id);
        if ($activeOnly) {
            $query->active();
        }

        return $query->first();
    }

    public function resolveExact(string $value, ?Collection $index = null): ?MaterialMaster
    {
        $normalized = $this->normalizer->normalize($value);
        if ($normalized === '') {
            return null;
        }

        if ($index !== null) {
            return $index->get($normalized);
        }

        return MaterialMaster::query()
            ->active()
            ->with('aliases')
            ->where(function ($query) use ($normalized) {
                $query->where('normalized_code', $normalized)
                    ->orWhereHas('aliases', fn ($aliases) => $aliases->where('normalized_alias', $normalized));
            })
            ->first();
    }

    public function activeIndex(): Collection
    {
        $index = collect();

        MaterialMaster::query()->active()->with('aliases')->get()->each(function (MaterialMaster $material) use ($index) {
            $index->put($material->normalized_code, $material);
            foreach ($material->aliases as $alias) {
                $index->put($alias->normalized_alias, $material);
            }
        });

        return $index;
    }
}
