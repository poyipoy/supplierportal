<?php

namespace Tests\Support;

use App\Models\Attachment;
use App\Models\Shipment;
use App\Services\ShipmentService;

/**
 * Supplies the Mission 05 shipment MTC the way a Supplier does: through
 * ShipmentService::uploadMtc as the shipment owner, so the file passes native
 * inspection and is VALIDATED. Use with Storage::fake('private') in setUp().
 */
trait AttachesShipmentMtc
{
    protected function attachValidatedMtc(Shipment|int $shipment): Attachment
    {
        $shipment = $shipment instanceof Shipment ? $shipment->fresh() : Shipment::query()->findOrFail($shipment);

        return app(ShipmentService::class)->uploadMtc(
            $shipment,
            [NativeFileFixtures::upload('mtc-'.uniqid().'.pdf')],
            $shipment->supplier()->firstOrFail(),
        )[0];
    }

    /**
     * Submit after supplying an MTC to a draft that has none. Non-draft
     * shipments are submitted unchanged so status guards keep their message.
     */
    protected function submitWithMtc(Shipment|int $shipment, array $data = []): Shipment
    {
        $current = $shipment instanceof Shipment ? $shipment->fresh() : Shipment::query()->find($shipment);
        if ($current?->status === Shipment::STATUS_DRAFT && ! $current->mtcAttachments()->exists()) {
            $this->attachValidatedMtc($current);
        }

        return app(ShipmentService::class)->submitShipment($shipment, $data);
    }
}
