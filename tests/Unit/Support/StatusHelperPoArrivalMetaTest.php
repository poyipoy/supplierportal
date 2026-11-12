<?php

namespace Tests\Unit\Support;

use App\Support\StatusHelper;
use Carbon\Carbon;
use Tests\TestCase;

class StatusHelperPoArrivalMetaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    public function test_completed_po_with_on_time_arrival(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: '2026-11-05',
            isOverdue: false,
            status: 'completed',
            actualArrival: '2026-11-04'
        );

        $this->assertSame(__('status.meta.arrived_on_time'), $meta['label']);
        $this->assertStringContainsString('bg-success', $meta['class']);
        $this->assertSame(__('status.meta.arrival_on_time'), $meta['description']);
    }

    public function test_completed_po_with_late_arrival(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: '2026-11-05',
            isOverdue: false,
            status: 'completed',
            actualArrival: '2026-11-08'
        );

        $this->assertSame(__('status.meta.arrived_late'), $meta['label']);
        $this->assertStringContainsString('bg-warning', $meta['class']);
        $this->assertSame(__('status.meta.arrival_late'), $meta['description']);
    }

    public function test_completed_po_without_estimated_date_but_actual_date_present(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: null,
            isOverdue: false,
            status: 'completed',
            actualArrival: '2026-11-08'
        );

        $this->assertSame(__('status.meta.received'), $meta['label']);
        $this->assertStringContainsString('bg-success', $meta['class']);
    }

    public function test_completed_po_without_any_dates(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: null,
            isOverdue: false,
            status: 'completed',
            actualArrival: null
        );

        $this->assertSame(__('status.po.completed'), $meta['label']);
        $this->assertStringContainsString('bg-success', $meta['class']);
    }

    public function test_cancelled_po(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: '2026-11-05',
            isOverdue: false,
            status: 'cancelled',
            actualArrival: null
        );

        $this->assertSame(__('status.po.cancelled'), $meta['label']);
        $this->assertStringContainsString('bg-secondary', $meta['class']);
        $this->assertSame(__('status.meta.order_cancelled'), $meta['description']);
    }

    public function test_claim_needed_po(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: '2026-11-05',
            isOverdue: false,
            status: 'claim_needed',
            actualArrival: '2026-11-04'
        );

        $this->assertSame(__('status.po.claim_needed'), $meta['label']);
        $this->assertStringContainsString('bg-danger', $meta['class']);
        $this->assertSame(__('status.meta.arrival_claim_needed'), $meta['description']);
    }

    public function test_waiting_qc_po_without_actual_arrival(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: '2026-11-05',
            isOverdue: false,
            status: 'waiting_qc',
            actualArrival: null
        );

        $this->assertSame(__('status.po.waiting_qc'), $meta['label']);
        $this->assertStringContainsString('bg-info', $meta['class']);
    }

    public function test_active_po_overdue(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: '2026-11-05',
            isOverdue: true,
            status: 'active',
            actualArrival: null
        );

        $this->assertSame(__('status.po.overdue'), $meta['label']);
        $this->assertStringContainsString('bg-danger', $meta['class']);
    }

    public function test_active_po_arriving_soon(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: '2026-11-14', // 4 days from Nov 10
            isOverdue: false,
            status: 'active',
            actualArrival: null
        );

        $this->assertSame(__('status.meta.arrives_soon'), $meta['label']);
        $this->assertStringContainsString('bg-info', $meta['class']);
    }

    public function test_active_po_on_schedule_when_more_than_7_days(): void
    {
        $meta = StatusHelper::poArrivalMeta(
            estimatedArrival: '2026-11-25', // 15 days from Nov 10
            isOverdue: false,
            status: 'active',
            actualArrival: null
        );

        $this->assertSame(__('status.meta.on_schedule'), $meta['label']);
        $this->assertStringContainsString('bg-light', $meta['class']);
    }
}
