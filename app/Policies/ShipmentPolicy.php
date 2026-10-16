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
            && (int) $user->id === (int) $shipment->supplier_id;
    }

    /**
     * Determine whether the user can submit the shipment.
     */
    public function submit(User $user, Shipment $shipment): bool
    {
        return $user->role === 'supplier'
            && (int) $user->id === (int) $shipment->supplier_id;
    }

    /**
     * Determine whether the user can cancel the shipment.
     */
    public function cancel(User $user, Shipment $shipment): bool
    {
        if ($user->role === 'supplier') {
            return (int) $user->id === (int) $shipment->supplier_id;
        }

        return in_array($user->role, ['purchasing', 'admin'], true);
    }

    /**
     * Determine whether the user can confirm arrival of the shipment.
     */
    public function confirmArrival(User $user, Shipment $shipment): bool
    {
        return in_array($user->role, ['purchasing', 'admin'], true);
    }

    /**
     * MTC changes (Q6B): owner only. The status allow-lists (upload: draft,
     * submitted; delete: draft) are enforced by ShipmentService under the
     * shipment lock and answered with 422, never by an implicit allow.
     */
    public function uploadMtc(User $user, Shipment $shipment): bool
    {
        return $user->role === 'supplier' && (int) $user->id === (int) $shipment->supplier_id;
    }

    public function deleteMtc(User $user, Shipment $shipment): bool
    {
        return $this->uploadMtc($user, $shipment);
    }

    public function uploadDocument(User $user, Shipment $shipment): bool
    {
        return $user->role === 'supplier' && (int) $user->id === (int) $shipment->supplier_id;
    }

    public function reviewDocument(User $user, Shipment $shipment): bool
    {
        return in_array($user->role, ['purchasing', 'admin'], true);
    }
}
