<?php

namespace App\Support;

class BankList
{
    /**
     * Get all banks list.
     *
     * @return array<int|string, string>
     */
    public static function all(): array
    {
        return config('banks', []);
    }

    /**
     * Format bank list for x-ui.searchable-select component.
     *
     * @param string|null $currentValue
     * @return array<int, array<string, mixed>>
     */
    public static function options(?string $currentValue = null): array
    {
        $banks = self::all();
        $options = [];
        $matchedCurrent = false;

        $cleanCurrent = $currentValue !== null ? trim($currentValue) : '';

        foreach ($banks as $key => $name) {
            $bankName = is_string($name) ? trim($name) : trim((string) $key);
            if ($bankName === '') {
                continue;
            }

            $options[] = [
                'value' => $bankName,
                'label' => $bankName,
                'sublabel' => null,
                'badge' => null,
                'badgeTone' => 'neutral',
                'searchKeywords' => strtolower($bankName),
            ];

            if ($cleanCurrent !== '' && (
                strcasecmp($bankName, $cleanCurrent) === 0 ||
                $bankName === $cleanCurrent
            )) {
                $matchedCurrent = true;
            }
        }

        // If a previously saved value exists and doesn't exactly equal any standard bank name:
        if ($cleanCurrent !== '' && ! $matchedCurrent) {
            $aliasMatch = null;
            foreach ($banks as $name) {
                $bankName = is_string($name) ? trim($name) : '';
                if ($bankName !== '' && (stripos($bankName, $cleanCurrent) !== false || stripos($cleanCurrent, $bankName) !== false)) {
                    $aliasMatch = $bankName;
                    break;
                }
            }

            $options[] = [
                'value' => $cleanCurrent,
                'label' => $cleanCurrent . ($aliasMatch ? " ({$aliasMatch})" : ''),
                'sublabel' => null,
                'badge' => null,
                'badgeTone' => 'neutral',
                'searchKeywords' => strtolower($cleanCurrent),
            ];
        }

        return $options;
    }
}
