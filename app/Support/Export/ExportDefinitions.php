<?php

namespace App\Support\Export;

use App\Exports\Advanced\Definitions\PurchaseOrderDefinition;
use App\Exports\Advanced\Definitions\QuotationDefinition;
use App\Exports\Advanced\Definitions\RequisitionDefinition;
use App\Exports\Advanced\Definitions\ShipmentDefinition;
use App\Exports\Advanced\ExportDefinition;
use InvalidArgumentException;

final class ExportDefinitions
{
    private const DEFINITIONS = [
        'purchasing.quotations' => QuotationDefinition::class,
        'supplier.quotations' => QuotationDefinition::class,
        'purchasing.pr' => RequisitionDefinition::class,
        'purchasing.shipments' => ShipmentDefinition::class,
        'purchasing.po' => PurchaseOrderDefinition::class,
        'supplier.po' => PurchaseOrderDefinition::class,
    ];

    public static function get(string $key): ExportDefinition
    {
        $class = self::DEFINITIONS[$key] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException('Unknown export definition.');
        }

        return new $class($key);
    }

    public static function forClass(string $class, string $audience): ExportDefinition
    {
        foreach (array_keys(self::DEFINITIONS) as $key) {
            $definition = self::get($key);
            if (str_starts_with($key, $audience.'.') && $definition->exportClass() === $class) {
                return $definition;
            }
        }

        throw new InvalidArgumentException('Export audience is not supported.');
    }

    public static function allowedColumns(ExportDefinition $definition): array
    {
        $audience = explode('.', $definition->key(), 2)[0];

        return array_values(array_filter($definition::columns(), fn ($column) => $column->visibleTo($audience)));
    }

    public static function defaultKeys(ExportDefinition $definition): array
    {
        return array_values(array_map(fn ($column) => $column->key, array_filter(self::allowedColumns($definition), fn ($column) => $column->default)));
    }

    public static function sanitizeKeys(ExportDefinition $definition, array $keys): array
    {
        $allowed = self::allowedColumns($definition);
        $allowedKeys = array_map(fn ($column) => $column->key, $allowed);
        $required = array_map(fn ($column) => $column->key, array_filter($allowed, fn ($column) => $column->required));
        if ($keys === [] || array_diff($keys, $allowedKeys) !== [] || array_diff($required, $keys) !== [] || count(array_unique($keys)) !== count($keys)) {
            throw new InvalidArgumentException('Invalid or unauthorized export columns.');
        }

        return array_values($keys);
    }
}
