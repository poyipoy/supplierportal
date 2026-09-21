<?php

namespace App\Services\LocalInvoice\Providers;

use App\Models\User;
use App\Services\LocalInvoice\Contracts\LocalPoProviderInterface;

class FakeLocalPoProvider implements LocalPoProviderInterface
{
    /**
     * @var array<int, array<string, array{value: float, gr: ?string, description: string}>>
     */
    protected array $pos = [];

    public function registerPo(int $supplierId, string $poNumber, float $value, ?string $grReference = null, string $description = ''): void
    {
        $this->pos[$supplierId][$poNumber] = [
            'value' => $value,
            'gr' => $grReference,
            'description' => $description,
        ];
    }

    public function getPoData(User $supplierUser, string $poNumber): ?array
    {
        if (! isset($this->pos[$supplierUser->id][$poNumber])) {
            return null;
        }

        $data = $this->pos[$supplierUser->id][$poNumber];

        return [
            'po_number' => $poNumber,
            'description' => $data['description'],
            'po_value' => (float) $data['value'],
            'has_gr' => ! empty($data['gr']),
            'gr_reference' => $data['gr'],
        ];
    }

    public function clear(): void
    {
        $this->pos = [];
    }
}
