<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationOrigin;
use App\Workflow\Condition\InvoiceReservationOriginCondition;
use PHPUnit\Framework\TestCase;

final class InvoiceReservationOriginConditionTest extends TestCase
{
    public function testMatchesWhenSingleReservationHasCorrectOrigin(): void
    {
        $condition = new InvoiceReservationOriginCondition();

        $origin = $this->createStub(ReservationOrigin::class);
        $origin->method('getId')->willReturn(5);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationOrigin')->willReturn($origin);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getReservations')->willReturn([$reservation]);

        self::assertTrue($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testMatchesWhenAnyOfMultipleReservationsHasCorrectOrigin(): void
    {
        $condition = new InvoiceReservationOriginCondition();

        $matchingOrigin = $this->createStub(ReservationOrigin::class);
        $matchingOrigin->method('getId')->willReturn(5);

        $otherOrigin = $this->createStub(ReservationOrigin::class);
        $otherOrigin->method('getId')->willReturn(2);

        $reservationA = $this->createStub(Reservation::class);
        $reservationA->method('getReservationOrigin')->willReturn($otherOrigin);

        $reservationB = $this->createStub(Reservation::class);
        $reservationB->method('getReservationOrigin')->willReturn($matchingOrigin);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getReservations')->willReturn([$reservationA, $reservationB]);

        self::assertTrue($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testReturnsFalseOnMismatch(): void
    {
        $condition = new InvoiceReservationOriginCondition();

        $origin = $this->createStub(ReservationOrigin::class);
        $origin->method('getId')->willReturn(2);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationOrigin')->willReturn($origin);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getReservations')->willReturn([$reservation]);

        self::assertFalse($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testReturnsFalseWhenOriginIsNull(): void
    {
        $condition = new InvoiceReservationOriginCondition();

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationOrigin')->willReturn(null);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getReservations')->willReturn([$reservation]);

        self::assertFalse($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testReturnsFalseWhenNoReservationsLinked(): void
    {
        $condition = new InvoiceReservationOriginCondition();

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getReservations')->willReturn([]);

        self::assertFalse($condition->evaluate(['originId' => 5], $invoice, []));
    }

    public function testReturnsFalseForWrongEntityType(): void
    {
        $condition = new InvoiceReservationOriginCondition();
        $reservation = $this->createStub(Reservation::class);

        self::assertFalse($condition->evaluate(['originId' => 1], $reservation, []));
    }

    public function testReturnsFalseWhenConfigMissing(): void
    {
        $condition = new InvoiceReservationOriginCondition();

        $origin = $this->createStub(ReservationOrigin::class);
        $origin->method('getId')->willReturn(1);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getReservationOrigin')->willReturn($origin);

        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getReservations')->willReturn([$reservation]);

        self::assertFalse($condition->evaluate([], $invoice, []));
    }
}
