<?php
declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Appartment;
use App\Entity\Customer;
use App\Entity\CustomerAddresses;
use App\Entity\Reservation;
use App\EventListener\ReservationMqttListener;
use App\Service\MqttPublisherService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\TestCase;

final class ReservationMqttListenerTest extends TestCase
{
    private function makeUpdateArgs(object $entity): PostUpdateEventArgs
    {
        return new PostUpdateEventArgs($entity, $this->createStub(EntityManagerInterface::class));
    }

    private function makePersistArgs(object $entity): PostPersistEventArgs
    {
        return new PostPersistEventArgs($entity, $this->createStub(EntityManagerInterface::class));
    }

    private function makeFlushArgs(): PostFlushEventArgs
    {
        return new PostFlushEventArgs($this->createStub(EntityManagerInterface::class));
    }

    public function testPostFlushDoesNothingWhenNoPendingApartments(): void
    {
        $publisher = $this->createMock(MqttPublisherService::class);
        $publisher->expects($this->never())->method('publishApartmentStatus');

        $listener = new ReservationMqttListener($publisher);
        $listener->postFlush($this->makeFlushArgs());
    }

    public function testPostFlushDoesNothingWhenNotConfigured(): void
    {
        $apartment = $this->createStub(Appartment::class);
        $apartment->method('getId')->willReturn(1);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getAppartment')->willReturn($apartment);

        $publisher = $this->createMock(MqttPublisherService::class);
        $publisher->method('isConfigured')->willReturn(false);
        $publisher->expects($this->never())->method('publishApartmentStatus');

        $listener = new ReservationMqttListener($publisher);
        $listener->postPersist($this->makePersistArgs($reservation));
        $listener->postFlush($this->makeFlushArgs());
    }

    public function testReservationChangeTriggersPublishForItsApartment(): void
    {
        $apartment = $this->createStub(Appartment::class);
        $apartment->method('getId')->willReturn(1);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getAppartment')->willReturn($apartment);

        $publisher = $this->createMock(MqttPublisherService::class);
        $publisher->method('isConfigured')->willReturn(true);
        $publisher->expects($this->once())
            ->method('publishApartmentStatus')
            ->with($apartment);

        $listener = new ReservationMqttListener($publisher);
        $listener->postUpdate($this->makeUpdateArgs($reservation));
        $listener->postFlush($this->makeFlushArgs());
    }

    public function testCustomerAddressChangeTriggersPublishForBookedApartments(): void
    {
        $apartment = $this->createStub(Appartment::class);
        $apartment->method('getId')->willReturn(1);

        $reservation = $this->createStub(Reservation::class);
        $reservation->method('getAppartment')->willReturn($apartment);

        $customer = $this->createStub(Customer::class);
        $customer->method('getBookedReservations')
            ->willReturn(new ArrayCollection([$reservation]));

        $address = $this->createStub(CustomerAddresses::class);
        $address->method('getCustomers')
            ->willReturn(new ArrayCollection([$customer]));

        $publisher = $this->createMock(MqttPublisherService::class);
        $publisher->method('isConfigured')->willReturn(true);
        $publisher->expects($this->once())
            ->method('publishApartmentStatus')
            ->with($apartment);

        $listener = new ReservationMqttListener($publisher);
        $listener->postUpdate($this->makeUpdateArgs($address));
        $listener->postFlush($this->makeFlushArgs());
    }

    public function testSameApartmentIsPublishedOnlyOnceEvenIfCollectedMultipleTimes(): void
    {
        $apartment = $this->createStub(Appartment::class);
        $apartment->method('getId')->willReturn(1);

        $reservation1 = $this->createStub(Reservation::class);
        $reservation1->method('getAppartment')->willReturn($apartment);

        $reservation2 = $this->createStub(Reservation::class);
        $reservation2->method('getAppartment')->willReturn($apartment);

        $publisher = $this->createMock(MqttPublisherService::class);
        $publisher->method('isConfigured')->willReturn(true);
        $publisher->expects($this->once())->method('publishApartmentStatus');

        $listener = new ReservationMqttListener($publisher);
        $listener->postUpdate($this->makeUpdateArgs($reservation1));
        $listener->postUpdate($this->makeUpdateArgs($reservation2));
        $listener->postFlush($this->makeFlushArgs());
    }
}
