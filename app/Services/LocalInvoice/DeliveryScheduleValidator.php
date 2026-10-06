<?php

namespace App\Services\LocalInvoice;

use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

final class DeliveryScheduleValidator
{
    /**
     * Assert that physical document delivery date is valid (not in the past and falls on a Wednesday).
     *
     * @throws ValidationException
     */
    public function assert(DateTimeInterface|string $date, string $field = 'scheduled_physical_delivery_date'): void
    {
        $sched = $date instanceof DateTimeInterface
            ? BusinessTime::toBusiness($date)->startOfDay()
            : BusinessTime::parseDate($date);

        if ($sched->isBefore(BusinessTime::today())) {
            throw ValidationException::withMessages([
                $field => __('local_invoice.validation.delivery_past'),
            ]);
        }

        if ($sched->dayOfWeek !== CarbonImmutable::WEDNESDAY) {
            throw ValidationException::withMessages([
                $field => __('local_invoice.validation.delivery_wednesday'),
            ]);
        }
    }
}
