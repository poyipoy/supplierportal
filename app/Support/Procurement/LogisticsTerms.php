<?php

namespace App\Support\Procurement;

use Illuminate\Validation\ValidationException;

/**
 * Port of Discharge and Incoterms (decisions 11-13, Q1). The codes are fixed
 * configuration (no master table) and mirror the database CHECK constraints.
 */
final class LogisticsTerms
{
    /** @return list<string> */
    public static function ports(): array
    {
        return config('procurement.logistics.ports_of_discharge', []);
    }

    /** @return list<string> */
    public static function incoterms(): array
    {
        return config('procurement.logistics.incoterms', []);
    }

    /** @return array<string, string> code => label */
    public static function portOptions(): array
    {
        return collect(self::ports())->mapWithKeys(fn (string $code) => [$code => self::portLabel($code)])->all();
    }

    /** @return array<string, string> code => label */
    public static function incotermOptions(): array
    {
        return collect(self::incoterms())->mapWithKeys(fn (string $code) => [$code => self::incotermLabel($code)])->all();
    }

    public static function portLabel(?string $code): string
    {
        return $code === null || $code === '' ? __('procurement.logistics.not_set') : __('procurement.ports.'.$code);
    }

    /** Full label, e.g. "CIF — Cost, Insurance and Freight". */
    public static function incotermLabel(?string $code): string
    {
        return $code === null || $code === '' ? __('procurement.logistics.not_set') : __('procurement.incoterms.'.$code);
    }

    /** Compact description used in guard messages, e.g. "Tanjung Priok, CIF". */
    public static function describe(?string $port, ?string $incoterm): string
    {
        return self::portLabel($port).', '.($incoterm ?: __('procurement.logistics.not_set'));
    }

    /**
     * Shared submit guard for every PR submission path (decision 12): both
     * terms are required and must be known codes.
     *
     * @throws ValidationException
     */
    public static function assertSubmittable(?string $port, ?string $incoterm): void
    {
        $errors = [];

        if (! in_array($port, self::ports(), true)) {
            $errors['port_of_discharge'] = __('procurement.logistics.port_required_on_submit');
        }
        if (! in_array($incoterm, self::incoterms(), true)) {
            $errors['incoterm'] = __('procurement.logistics.incoterm_required_on_submit');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
