<?php

namespace Database\Seeders;

use App\Models\SupplierAuditCriterion;
use App\Models\SupplierAuditSection;
use App\Models\SupplierAuditTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Template checklist "FORM CHECKLIST AUDIT SUPPLIER & VENDOR — ISO 14001, ISO 9001 & AGC AFC".
 *
 * Teks berasal dari Excel terlampir dengan koreksi ejaan yang disetujui
 * (docs/plans/IMPLEMENTATION-PLAN-SUPPLIER-AUDIT-20261008.md §4).
 * Idempoten per [code, version]; template yang sudah ada tidak pernah diubah.
 */
class SupplierAuditTemplateSeeder extends Seeder
{
    public const FIXTURE = 'supplier_audit_template_v1.json';

    public const EXPECTED_LEVEL_ONE_SECTIONS = 16;

    public const EXPECTED_LEVEL_TWO_SECTIONS = 4;

    public const EXPECTED_CRITERIA = 121;

    public const EXPECTED_GROUPS = 17;

    public function run(): void
    {
        $fixture = $this->fixture();
        $this->assertFixtureShape($fixture);

        DB::transaction(function () use ($fixture) {
            $template = SupplierAuditTemplate::where('code', $fixture['code'])
                ->where('version', $fixture['version'])
                ->lockForUpdate()
                ->first();

            if ($template === null) {
                $template = $this->createTemplate($fixture);
            } else {
                $this->assertStoredTemplate($template);
            }

            SupplierAuditTemplate::where('code', $template->code)
                ->whereKeyNot($template->getKey())
                ->update(['is_active' => false]);

            if (! $template->is_active) {
                $template->update(['is_active' => true]);
            }
        });
    }

    private function createTemplate(array $fixture): SupplierAuditTemplate
    {
        $template = SupplierAuditTemplate::create([
            'code' => $fixture['code'],
            'version' => $fixture['version'],
            'title' => $fixture['title'],
            'is_active' => false,
        ]);

        $sectionIds = [];
        foreach ($fixture['sections'] as $source) {
            $parentCode = $source['parent_code'] ?? null;
            $section = SupplierAuditSection::create([
                'supplier_audit_template_id' => $template->id,
                'parent_id' => $parentCode !== null ? $sectionIds[$parentCode] : null,
                'code' => $source['code'],
                'title' => $source['title'],
                'level' => $source['level'],
                'sheet_number' => $source['sheet_number'],
                'sort_order' => $source['sort_order'],
            ]);
            $sectionIds[$source['code']] = $section->id;

            foreach ($source['criteria'] as $criterion) {
                SupplierAuditCriterion::create([
                    'supplier_audit_section_id' => $section->id,
                    'number' => $criterion['number'],
                    'sort_order' => $criterion['sort_order'],
                    'text' => $criterion['text'],
                ]);
            }
        }

        $this->assertStoredTemplate($template);

        return $template;
    }

    private function assertStoredTemplate(SupplierAuditTemplate $template): void
    {
        $levelOne = $template->sections()->where('level', 1)->count();
        $levelTwo = $template->sections()->where('level', 2)->count();
        $criteria = $template->criteria()->count();

        if ($levelOne !== self::EXPECTED_LEVEL_ONE_SECTIONS || $levelTwo !== self::EXPECTED_LEVEL_TWO_SECTIONS || $criteria !== self::EXPECTED_CRITERIA) {
            throw new RuntimeException('Stored supplier audit template does not match the approved checklist.');
        }
    }

    private function assertFixtureShape(array $fixture): void
    {
        $sections = collect($fixture['sections'] ?? []);
        $criteria = $sections->sum(fn (array $section) => count($section['criteria'] ?? []));
        $groups = $sections->filter(fn (array $section) => count($section['criteria'] ?? []) > 0)->count();

        if ($sections->where('level', 1)->count() !== self::EXPECTED_LEVEL_ONE_SECTIONS
            || $sections->where('level', 2)->count() !== self::EXPECTED_LEVEL_TWO_SECTIONS
            || $criteria !== self::EXPECTED_CRITERIA
            || $groups !== self::EXPECTED_GROUPS) {
            throw new RuntimeException('Supplier audit template fixture does not match the approved checklist.');
        }
    }

    private function fixture(): array
    {
        $path = database_path('data/'.self::FIXTURE);
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! isset($decoded['code'], $decoded['version'], $decoded['title'], $decoded['sections'])) {
            throw new RuntimeException('Supplier audit template fixture is malformed.');
        }

        return $decoded;
    }
}
