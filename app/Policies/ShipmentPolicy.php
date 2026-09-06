<?php

namespace App\Policies;

use App\Models\Shipment;
use App\Models\User;

class ShipmentPolicy
{
    /**
     * Determine whether the user can view any shipments.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['supplier', 'purchasing', 'admin', 'qc'], true);
    }

    /**
     * Determine whether the user can view the shipment.
     */
    public function view(User $user, Shipment $shipment): bool
    {
        if ($user->role === 'supplier') {
            return (int) $user->id === (int) $shipment->supplier_id;
        }

        return in_array($user->role, ['purchasing', 'admin', 'qc'], true);
    }

    /**
     * Determine whether the user can create shipments.
     */
    public function create(User $user): bool
    {
        return $user->role === 'supplier';
    }

    /**
     * Determine whether the user can update the shipment.
     */
    public function update(User $user, Shipment $shipment): bool
    {
        return $user->role === 'supplier'
            && (int) $user->id === (int) $shipment->supplier_id
            && $shipment->status === Shipment::STATUS_DRAFT;
    }

    /**
     * Determine whether the user can submit the shipment.
     */
    public function submit(User $user, Shipment $shipment): bool
    {
        return $user->role === 'supplier'
            && (int) $user->id === (int) $shipment->supplier_id
            && $shipment->status === Shipment::STATUS_DRAFT;
    }

    /**
     * Determine whether the user can cancel the shipment.
     */
    public function cancel(User $user, Shipment $shipment): bool
    {
        if ($user->role === 'supplier') {
            return (int) $user->id === (int) $shipment->supplier_id
                && in_array($shipment->status, [Shipment::STATUS_DRAFT, Shipment::STATUS_SUBMITTED], true);
        }

        return in_array($user->role, ['purchasing', 'admin'], true)
            && in_array($shipment->status, [Shipment::STATUS_DRAFT, Shipment::STATUS_SUBMITTED], true);
    }

    /**
     * Determine whether the user can confirm arrival of the shipment.
     */
    public function confirmArrival(User $user, Shipment $shipment): bool
    {
        return in_array($user->role, ['purchasing', 'admin'], true)
            && $shipment->status === Shipment::STATUS_SUBMITTED;
    }
}
