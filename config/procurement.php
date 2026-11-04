<?php

/*
|--------------------------------------------------------------------------
| Import Procurement
|--------------------------------------------------------------------------
|
| Read the feature flags through App\Support\Procurement\ProcurementFeatures,
| never directly: the effective surplus permission couples two flags so an
| inconsistent environment fails closed.
|
| Toggling a flag is an operational action (env change + config:clear or
| config:cache); it never requires a code deploy.
|
*/

return [
    'logistics' => [
        // Must match the CHECK constraints on purchase_requisitions and
        // purchase_orders (migrations 2026_10_14_000002 / 000003).
        'ports_of_discharge' => ['TANJUNG_PRIOK', 'TANJUNG_PERAK'],
        'incoterms' => ['CFR', 'CIF', 'FOB'],
    ],

    // Kill switch enforced at PurchaseOrderGenerationService::generateFromAwards().
    'po_generation_enabled' => (bool) env('PROCUREMENT_PO_GENERATION_ENABLED', true),

    // Ordered-quantity write path (Mission 04-W). Off until that release.
    'ordered_quantity_write_enabled' => (bool) env('PROCUREMENT_ORDERED_QUANTITY_WRITE_ENABLED', false),

    // Supplier offers above the PR quantity. Only effective together with
    // ordered_quantity_write_enabled.
    'allow_offer_surplus' => (bool) env('PROCUREMENT_ALLOW_OFFER_SURPLUS', false),

    // Mission 05 atomic MTC switch (Q6B): MTC per shipment, required before
    // submit, and new quotation MTC uploads retired. false = pre-R3 behaviour.
    'shipment_mtc_enabled' => (bool) env('PROCUREMENT_SHIPMENT_MTC_ENABLED', true),

    // Ready to Ship gate (3 Nov 2026): shipment drafts and submits only accept
    // awarded items whose latest supplier progress is ready_to_ship. false =
    // previous behaviour (progress never blocks a shipment). Rollback switch.
    'require_ready_to_ship_for_shipment' => (bool) env('PROCUREMENT_REQUIRE_READY_TO_SHIP', true),

    // Historical quotation comparison (Gate 1 D-3, info flag only): a snapshot
    // rate older than this many days before its quotation date is marked stale.
    'historical_comparison' => [
        'stale_rate_days' => (int) env('PROCUREMENT_HISTORY_STALE_RATE_DAYS', 31),
    ],
];
